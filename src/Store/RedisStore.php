<?php

declare(strict_types=1);

namespace Flytachi\Winter\Redis\Store;

use Flytachi\Winter\Redis\Config\Common\RedisConfigInterface;
use Flytachi\Winter\Redis\RedisCommandException;
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
 * @link https://winterframe.net/docs/redis-stores Stores
 */
abstract class RedisStore
{
    use ChecksCommandErrors;

    /** @var class-string<RedisConfigInterface> The endpoint this store talks to. */
    protected string $redisConfigClassName;

    /** Prepended to every key this store touches. */
    protected string $prefix = '';

    /**
     * The client for the current unit of work — the raw driver, for everything this
     * store does not wrap: sorted sets, hashes, pipelines, pub/sub.
     *
     * **The prefix is not applied here.** Wrap every key in {@see key()}:
     *
     * ```php
     * $store->raw()->zAdd($store->key('leaders'), 100, 'user:1');   // right
     * $store->raw()->zAdd('leaders', 100, 'user:1');                // wrong
     * ```
     *
     * The second line is not an error — it writes to a key outside the store, and
     * nothing complains. The damage shows up later and elsewhere: `keys()` and `get()`
     * will not see that key, `flush()` will not delete it, and two stores that both
     * forget the prefix quietly share one name. That is exactly the collision the
     * prefix exists to prevent, so a forgotten `key()` removes the store's only
     * guarantee.
     *
     * The client itself is valid **for this request only**: under Swoole it returns to
     * the pool when the coroutine ends, so storing it in a property of a long-lived
     * object hands one socket to every later request.
     *
     * @link https://winterframe.net/docs/redis-stores#raw The raw client
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
     *
     * @link https://winterframe.net/docs/redis-stores#configclass The endpoint
     */
    final public function configClass(): string
    {
        return $this->redisConfigClassName;
    }

    /**
     * This store's prefix applied to a key — the name the server actually sees.
     *
     * @link https://winterframe.net/docs/redis-stores#key The prefixed name
     */
    final public function key(string $key): string
    {
        return $this->prefix . $key;
    }

    /**
     * A handle on the hash stored under this key.
     *
     * ```php
     * $store->hash('cart:42')->set('qty', '2', ttl: 60);
     * ```
     *
     * The prefix is applied once, here, so nothing downstream can forget it. The handle
     * is a view, not a connection: creating one talks to no server, and it stays valid
     * for as long as the current unit of work.
     */
    final public function hash(string $key): RedisHash
    {
        return new RedisHash($this, $this->key($key));
    }

    /**
     * A handle on the stream stored under this key.
     *
     * ```php
     * $store->stream('events')->add(['type' => 'signup']);
     * ```
     *
     * Like the other handles this one talks to no server until a method is called, and
     * the prefix is applied here, once. Keep it for a loop that tails the stream: its
     * blocking reads reuse a single connection of their own.
     *
     * @link https://winterframe.net/docs/redis-streams Streams
     */
    final public function stream(string $key): RedisStream
    {
        return new RedisStream($this, $this->key($key));
    }

    /**
     * A handle on the list stored under this key.
     *
     * ```php
     * $store->list('jobs')->push($payload);
     * ```
     *
     * As with {@see hash()}, the prefix is applied once, here. Keep the handle for a
     * consumer loop: its blocking reads reuse one connection of their own.
     */
    final public function list(string $key): RedisList
    {
        return new RedisList($this, $this->key($key));
    }

    /**
     * The stored value, or `null` when the key does not exist.
     *
     * phpredis reports a miss as `false`, which is indistinguishable from a stored
     * `false`; `null` removes that ambiguity for the common case. If you need to store
     * booleans, store them encoded or use {@see raw()}.
     *
     * @link https://winterframe.net/docs/redis-stores#get Reading a value
     */
    final public function get(string $key): mixed
    {
        $redis = $this->raw();
        $value = $this->runChecked($redis, fn(Redis $r): mixed => $r->get($this->key($key)));

        return $value === false ? null : $value;
    }

    /**
     * Stores a value, optionally with a lifetime.
     *
     * ```php
     * $store->set('key', 'value', ttl: 60);   // gone in a minute
     * $store->set('key', 'value');            // stays until deleted
     * ```
     *
     * `$ttl` is a **duration in seconds counted from now**, never a timestamp. Passing
     * `time() + 60` asks for roughly fifty-six years, and nothing reports it — the key
     * simply never expires. For an absolute moment there is `EXPIREAT`, deliberately
     * left to the driver so one argument never carries two meanings:
     * `$store->raw()->expireAt($store->key('key'), $moment)`.
     *
     * What may be passed as a value depends on the config's serializer: with the
     * default `SERIALIZER_NONE` it must be a string or a number, since the server
     * stores those bytes verbatim — an array becomes the string `"Array"` with only a
     * PHP warning. Configure a serializer to store arrays and objects.
     *
     * @link https://winterframe.net/docs/redis-stores#set Writing a value
     */
    final public function set(string $key, mixed $value, ?int $ttl = null): bool
    {
        if ($ttl !== null && $ttl <= 0) {
            throw new \LogicException(
                'A ttl must be a positive number of seconds; got ' . $ttl
                . '. To remove a key, call delete().',
            );
        }

        $name  = $this->key($key);
        $redis = $this->raw();

        return (bool) $this->runChecked($redis, fn(Redis $r): mixed => $ttl === null
            ? $r->set($name, $value)
            : $r->setex($name, $ttl, $value));
    }

    /**
     * Deletes keys and returns how many existed.
     *
     * @param string ...$keys Unprefixed key names.
     *
     * @link https://winterframe.net/docs/redis-stores#delete Deleting keys
     */
    final public function delete(string ...$keys): int
    {
        if ($keys === []) {
            return 0;
        }

        return (int) $this->raw()->del(array_map($this->key(...), $keys));
    }

    /**
     * @link https://winterframe.net/docs/redis-stores#has Checking a key
     */
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
        $redis = $this->raw();

        return (int) $this->runChecked($redis, fn(Redis $r): mixed => $r->incrBy($this->key($key), $by));
    }

    /** Atomically subtracts from a counter and returns the new value. */
    final public function decrement(string $key, int $by = 1): int
    {
        $redis = $this->raw();

        return (int) $this->runChecked($redis, fn(Redis $r): mixed => $r->decrBy($this->key($key), $by));
    }

    /**
     * Remaining lifetime in seconds, `null` when the key has none or does not exist.
     *
     * @link https://winterframe.net/docs/redis-stores#ttl Remaining lifetime
     */
    final public function ttl(string $key): ?int
    {
        $ttl = $this->raw()->ttl($this->key($key));

        return is_int($ttl) && $ttl >= 0 ? $ttl : null;
    }

    /**
     * Every key of this store, **without** the prefix — the names you pass back to
     * {@see get()} and {@see delete()}.
     *
     * ```php
     * $store->keys();            // ['abc', 'def']  — everything in this store
     * $store->keys('user:*');    // only what matches, still inside the store
     * ```
     *
     * It scans in batches instead of issuing `KEYS`. The difference is not cosmetic:
     * `KEYS` walks the whole keyspace in one go and Redis is single-threaded, so on a
     * large database it stalls **every** client for the duration. `SCAN` gives the
     * server room to breathe between batches. The cost is that the result is a snapshot
     * assembled over time: a key added while the walk is in progress may or may not
     * appear, and one deleted meanwhile may still be listed.
     *
     * The whole result is materialised in memory. For a store holding millions of keys,
     * prefer a narrower `$pattern`.
     *
     * @param string $pattern Glob applied **inside** the store, not to the database.
     * @return list<string> Unprefixed key names.
     *
     * @link https://winterframe.net/docs/redis-stores#keys Listing the store
     */
    final public function keys(string $pattern = '*'): array
    {
        $redis  = $this->raw();
        $cursor = null;
        $found  = [];
        $offset = strlen($this->prefix);

        // Driven by the cursor, not by the batch: a SCAN slice that matches nothing
        // comes back empty while the iteration is unfinished, so stopping at the first
        // empty batch would silently return a partial list.
        do {
            $batch = $redis->scan($cursor, $this->prefix . $pattern, 1000);
            if (is_array($batch)) {
                foreach ($batch as $key) {
                    $found[] = $offset === 0 ? $key : substr($key, $offset);
                }
            }
        } while ((int) $cursor !== 0);

        return $found;
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
     *
     * @link https://winterframe.net/docs/redis-stores#transaction One connection per block
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
     *
     * @link https://winterframe.net/docs/redis-stores#flush Emptying the store
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
