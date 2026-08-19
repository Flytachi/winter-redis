<?php

declare(strict_types=1);

namespace Flytachi\Winter\Redis\Store;

use Flytachi\Winter\Redis\Config\Common\RedisConfigInterface;
use Flytachi\Winter\Redis\RedisPool;
use LogicException;
use Redis;

/**
 * A named slice of a Redis database: a key prefix plus the handful of commands most
 * application code actually uses.
 *
 * ```php
 * class SessionStore extends RedisStore
 * {
 *     protected string $redisConfigClassName = MainRedisConfig::class;
 *     protected string $prefix               = 'session:';
 * }
 * ```
 *
 * A store is an ordinary object — resolve it through the container, inject it, test it.
 * It holds no connection of its own: every call asks {@see RedisPool} for the client
 * belonging to the current unit of work, so a store may safely live in a singleton even
 * though a connection may not.
 *
 * The prefix is what makes several stores share one database without colliding, and it
 * is what makes {@see flush()} safe — it deletes this store's keys, not the database.
 *
 * Commands beyond the basics are reached through {@see raw()}; wrapping all of Redis
 * would be an endless job and a guessing game about what is already wrapped.
 *
 * @link https://winterframe.net/packages/redis/stores Stores
 */
abstract class RedisStore
{
    /** @var class-string<RedisConfigInterface> The endpoint this store talks to. */
    protected string $redisConfigClassName;

    /** Prepended to every key this store touches. */
    protected string $prefix = '';

    /**
     * The client for the current unit of work, **without** the prefix applied.
     *
     * Use it for everything the store does not wrap — sorted sets, pipelines, pub/sub.
     * Prefix keys yourself with {@see key()} so they land in this store's namespace.
     *
     * The returned client is valid for this request only: under Swoole it goes back to
     * the pool when the coroutine ends, so storing it in a property hands one socket to
     * every later request.
     */
    final public function raw(): Redis
    {
        return RedisPool::store($this->redisConfigClassName);
    }

    /**
     * The config class this store talks to — for diagnostics and for adapters that need
     * to inspect the endpoint's settings.
     *
     * @return class-string<RedisConfigInterface>
     */
    final public function configClass(): string
    {
        return $this->redisConfigClassName;
    }

    /** This store's prefix applied to a key — the name the server actually sees. */
    final public function key(string $key): string
    {
        return $this->prefix . $key;
    }

    /**
     * The stored value, or `null` when the key does not exist.
     *
     * phpredis reports a miss as `false`, which is indistinguishable from a stored
     * `false`; `null` removes that ambiguity for the common case. If you need to store
     * booleans, store them encoded or use {@see raw()}.
     */
    final public function get(string $key): mixed
    {
        $value = $this->raw()->get($this->key($key));

        return $value === false ? null : $value;
    }

    /**
     * Stores a value, optionally with a time to live in seconds.
     *
     * What may be passed depends on the config's serializer: with the default
     * `SERIALIZER_NONE` the value must be a string or a number, because that is what
     * the server stores verbatim. Configure a serializer to store arrays and objects.
     */
    final public function set(string $key, mixed $value, ?int $ttl = null): bool
    {
        $name = $this->key($key);

        return $ttl === null
            ? (bool) $this->raw()->set($name, $value)
            : (bool) $this->raw()->setex($name, $ttl, $value);
    }

    /**
     * Deletes keys and returns how many existed.
     *
     * @param string ...$keys Unprefixed key names.
     */
    final public function delete(string ...$keys): int
    {
        if ($keys === []) {
            return 0;
        }

        return (int) $this->raw()->del(array_map($this->key(...), $keys));
    }

    final public function has(string $key): bool
    {
        return (bool) $this->raw()->exists($this->key($key));
    }

    /**
     * Atomically adds to a counter and returns the new value. Creates the key at `0`
     * first if it does not exist.
     */
    final public function increment(string $key, int $by = 1): int
    {
        return (int) $this->raw()->incrBy($this->key($key), $by);
    }

    /** Atomically subtracts from a counter and returns the new value. */
    final public function decrement(string $key, int $by = 1): int
    {
        return (int) $this->raw()->decrBy($this->key($key), $by);
    }

    /**
     * Remaining lifetime in seconds, `null` when the key has none or does not exist.
     */
    final public function ttl(string $key): ?int
    {
        $ttl = $this->raw()->ttl($this->key($key));

        return is_int($ttl) && $ttl >= 0 ? $ttl : null;
    }

    /**
     * Runs a callback against one connection held for the whole block.
     *
     * This is the exception to "the pool hands the connection back automatically":
     * `MULTI` and `pipeline()` keep state **on the connection**, so every command of
     * the sequence has to reach the same socket. Within the callback that is guaranteed.
     *
     * ```php
     * $store->transaction(function (Redis $r) use ($store) {
     *     $r->multi();
     *     $r->incr($store->key('a'));
     *     $r->incr($store->key('b'));
     *     $r->exec();
     * });
     * ```
     *
     * Keys are **not** prefixed for you here — the callback gets the raw client, so use
     * {@see key()} as above.
     *
     * @template T
     * @param callable(Redis): T $callback
     * @return T
     */
    final public function transaction(callable $callback): mixed
    {
        return $callback($this->raw());
    }

    /**
     * Deletes every key of this store and returns how many were removed.
     *
     * It scans instead of calling `FLUSHDB`, so it removes this store's keys and leaves
     * everything else in the database alone. Scanning is incremental — the server is
     * never blocked — but on a large keyspace it is a walk, not an instant operation.
     *
     * @throws LogicException When the store has no prefix, since there would be nothing
     *   to scope the deletion to and every key in the database would match.
     */
    final public function flush(): int
    {
        if ($this->prefix === '') {
            throw new LogicException(
                static::class . '::flush() needs a $prefix — without one it would delete the whole database.',
            );
        }

        $redis   = $this->raw();
        $deleted = 0;
        $cursor  = null;

        // Driven by the cursor rather than by the returned batch: a SCAN slice that
        // matches nothing comes back empty with the iteration still unfinished, so
        // looping `while ($keys = ...)` would stop at the first such gap and report a
        // flush that deleted only part of the store.
        do {
            $keys = $redis->scan($cursor, $this->prefix . '*', 1000);
            if (is_array($keys) && $keys !== []) {
                $deleted += (int) $redis->del($keys);
            }
        } while ((int) $cursor !== 0);

        return $deleted;
    }
}
