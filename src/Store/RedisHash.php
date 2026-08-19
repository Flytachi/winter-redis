<?php

declare(strict_types=1);

namespace Flytachi\Winter\Redis\Store;

use Flytachi\Winter\Redis\RedisFeatureException;
use LogicException;
use Redis;
use RedisException;
use Throwable;

/**
 * A handle on one Redis hash — a map of fields under a single key.
 *
 * Obtained from a store, which supplies the prefix once:
 *
 * ```php
 * $cart = $store->hash('cart:42');      // the server sees "shop:cart:42"
 * $cart->set('qty', '2', ttl: 60);
 * $cart->all();                          // ['qty' => '2']
 * ```
 *
 * Because the key is bound at construction, there is nowhere left to forget the
 * prefix — the trap that {@see RedisStore::raw()} carries.
 *
 * **Everything on this handle is about fields**, and that is what `$ttl` means here
 * too: a lifetime for the field being written, not for the hash. Operations on the key
 * as a whole say so in their names — {@see expireKey()}, {@see keyTtl()},
 * {@see deleteKey()}.
 *
 * The handle holds no connection. Every call asks the store for the client belonging to
 * the current unit of work, so a handle may safely be kept for the length of a request.
 *
 * @link https://winterframe.net/docs/redis-hashes Hashes
 */
final readonly class RedisHash
{
    use ChecksCommandErrors;

    /** Field-level lifetimes arrived in this release of Redis. */
    private const string FIELD_TTL_SINCE = '8.0';

    /**
     * @param RedisStore $store Owner — supplies the connection and the prefix.
     * @param string $name The **prefixed** key, as the server sees it.
     */
    public function __construct(
        private RedisStore $store,
        private string $name,
    ) {
    }

    /**
     * The key as the server sees it, prefix included — for use with {@see RedisStore::raw()}.
     *
     * @link https://winterframe.net/docs/redis-hashes#name The prefixed key name
     */
    public function name(): string
    {
        return $this->name;
    }

    // -------------------------------------------------------------------------
    // Reading
    // -------------------------------------------------------------------------

    /**
     * One field's value, or `null` when the field (or the hash) does not exist.
     *
     * @link https://winterframe.net/docs/redis-hashes#get Reading a field
     */
    public function get(string $field): mixed
    {
        $redis = $this->store->raw();
        $value = $this->runChecked($redis, fn(Redis $r): mixed => $r->hGet($this->name, $field));

        return $value === false ? null : $value;
    }

    /**
     * Several fields in one round trip, keyed by field name, `null` for each one that
     * is missing — so the result always has exactly the keys you asked for.
     *
     * @return array<string, mixed>
     *
     * @link https://winterframe.net/docs/redis-hashes#getmany Several fields at once
     */
    public function getMany(string ...$fields): array
    {
        if ($fields === []) {
            return [];
        }

        $values = $this->store->raw()->hMget($this->name, $fields);
        if (!is_array($values)) {
            return array_fill_keys($fields, null);
        }

        return array_map(static fn(mixed $v): mixed => $v === false ? null : $v, $values);
    }

    /**
     * The whole hash as an array.
     *
     * This is `HGETALL`: one reply carrying every field. Redis is single-threaded, so a
     * hash with hundreds of thousands of fields blocks the server while it is
     * assembled, and the whole thing lands in this process's memory. For hashes that
     * large, read what you need with {@see getMany()} or scan through
     * {@see RedisStore::raw()}.
     *
     * @return array<string, mixed>
     *
     * @link https://winterframe.net/docs/redis-hashes#all The whole hash
     */
    public function all(): array
    {
        $values = $this->store->raw()->hGetAll($this->name);

        return is_array($values) ? $values : [];
    }

    /**
     * Field names only — the cheap half of {@see all()} when values are not needed.
     *
     * @return list<string>
     */
    public function fields(): array
    {
        $fields = $this->store->raw()->hKeys($this->name);

        return is_array($fields) ? $fields : [];
    }

    /**
     * Values only, in the same order {@see fields()} reports.
     *
     * @return list<mixed>
     */
    public function values(): array
    {
        $values = $this->store->raw()->hVals($this->name);

        return is_array($values) ? $values : [];
    }

    /**
     * @link https://winterframe.net/docs/redis-hashes#has Checking a field
     */
    public function has(string $field): bool
    {
        return (bool) $this->store->raw()->hExists($this->name, $field);
    }

    /**
     * How many fields the hash holds; `0` when it does not exist.
     *
     * @link https://winterframe.net/docs/redis-hashes#count Number of fields
     */
    public function count(): int
    {
        return (int) $this->store->raw()->hLen($this->name);
    }

    // -------------------------------------------------------------------------
    // Writing
    // -------------------------------------------------------------------------

    /**
     * Sets one field, optionally with a lifetime **for that field**.
     *
     * ```php
     * $cart->set('qty', '2');             // lives as long as the hash does
     * $cart->set('lock', '1', ttl: 30);   // this field alone expires in 30s
     * ```
     *
     * With a `$ttl` this is a single `HSETEX` — the write and the lifetime arrive
     * together, so there is no window in which the field exists without its expiry.
     *
     * @param int|null $ttl Seconds from now, never a timestamp.
     * @throws RedisFeatureException When `$ttl` is used against a server older than 8.0.
     *
     * @link https://winterframe.net/docs/redis-hashes#set Writing a field
     */
    public function set(string $field, mixed $value, ?int $ttl = null): bool
    {
        return $this->setAll([$field => $value], $ttl);
    }

    /**
     * Sets several fields at once, with one lifetime for all of them.
     *
     * @param array<string, mixed> $fields
     * @param int|null $ttl Seconds from now, applied to every field written.
     * @throws RedisFeatureException When `$ttl` is used against a server older than 8.0.
     *
     * @link https://winterframe.net/docs/redis-hashes#setall Writing several fields
     */
    public function setAll(array $fields, ?int $ttl = null): bool
    {
        if ($fields === []) {
            return true;
        }

        if ($ttl !== null && $ttl <= 0) {
            throw new LogicException(
                'A ttl must be a positive number of seconds; got ' . $ttl
                . '. To remove a field, call delete().',
            );
        }

        if ($ttl === null) {
            $redis = $this->store->raw();

            return $this->runChecked($redis, fn(Redis $r): mixed => $r->hSet($this->name, $fields)) !== false;
        }

        return $this->fieldTtlCommand(
            'HSETEX',
            fn(Redis $redis): mixed => $redis->hSetEx($this->name, $fields, ['EX' => $ttl]),
        ) !== false;
    }

    /**
     * Adds to a numeric field and returns the new value, creating it at `0` first.
     *
     * The read and the write are one command, so two concurrent requests cannot both
     * read `5` and both write `6`.
     */
    public function increment(string $field, int $by = 1): int
    {
        $redis = $this->store->raw();

        return (int) $this->runChecked($redis, fn(Redis $r): mixed => $r->hIncrBy($this->name, $field, $by));
    }

    public function decrement(string $field, int $by = 1): int
    {
        return $this->increment($field, -$by);
    }

    /**
     * Deletes fields and returns how many existed.
     *
     * A hash with no fields left stops existing — Redis keeps no empty containers.
     *
     * @link https://winterframe.net/docs/redis-hashes#delete Deleting fields
     */
    public function delete(string ...$fields): int
    {
        if ($fields === []) {
            return 0;
        }

        $first = array_shift($fields);

        return (int) $this->store->raw()->hDel($this->name, $first, ...$fields);
    }

    // -------------------------------------------------------------------------
    // Field lifetimes (Redis 7.4+ / 8.0+)
    // -------------------------------------------------------------------------

    /**
     * Remaining lifetime of one field in seconds, or `null` when it has none — which
     * covers both "set to live forever" and "no such field".
     *
     * @throws RedisFeatureException When the server is older than 7.4.
     *
     * @link https://winterframe.net/docs/redis-hashes#ttl A field's lifetime
     */
    public function ttl(string $field): ?int
    {
        $reply = $this->fieldTtlCommand(
            'HTTL',
            fn(Redis $redis): mixed => $redis->hTtl($this->name, [$field]),
        );

        $ttl = is_array($reply) ? ($reply[0] ?? -1) : -1;

        return is_int($ttl) && $ttl >= 0 ? $ttl : null;
    }

    /**
     * Removes the lifetime from a field, so it lives as long as the hash does.
     *
     * @return bool Whether a lifetime was actually removed.
     * @throws RedisFeatureException When the server is older than 7.4.
     *
     * @link https://winterframe.net/docs/redis-hashes#persist Dropping a field's lifetime
     */
    public function persist(string $field): bool
    {
        $reply = $this->fieldTtlCommand(
            'HPERSIST',
            fn(Redis $redis): mixed => $redis->hPersist($this->name, [$field]),
        );

        return is_array($reply) && ($reply[0] ?? -1) === 1;
    }

    // -------------------------------------------------------------------------
    // The key as a whole
    // -------------------------------------------------------------------------

    /**
     * Gives the whole hash a lifetime, fields and all.
     *
     * @link https://winterframe.net/docs/redis-hashes#expirekey The hash's lifetime
     */
    public function expireKey(int $seconds): bool
    {
        return (bool) $this->store->raw()->expire($this->name, $seconds);
    }

    /**
     * Remaining lifetime of the whole hash, `null` when it has none or does not exist.
     *
     * @link https://winterframe.net/docs/redis-hashes#keyttl The hash's remaining lifetime
     */
    public function keyTtl(): ?int
    {
        $ttl = $this->store->raw()->ttl($this->name);

        return is_int($ttl) && $ttl >= 0 ? $ttl : null;
    }

    /**
     * Deletes the hash outright.
     *
     * @link https://winterframe.net/docs/redis-hashes#deletekey Deleting the hash
     */
    public function deleteKey(): bool
    {
        return (bool) $this->store->raw()->del($this->name);
    }

    // -------------------------------------------------------------------------

    /**
     * Runs a command that only newer servers know, and translates the refusal.
     *
     * An older Redis answers `ERR unknown command`, and phpredis reports that by
     * returning `false` and parking the text in `getLastError()` — measured, not
     * assumed: it does **not** throw. Left alone, a per-field lifetime would therefore
     * fail silently, which is the one outcome worth ruling out. The exception branch is
     * kept because a driver may surface the same refusal that way instead.
     *
     * The error is cleared on both sides of the call. Before, so a message left by an
     * earlier command cannot be mistaken for this one's; after, because the connection
     * goes back to the pool and the next borrower must not find someone else's error
     * waiting in it.
     *
     * @throws RedisFeatureException
     */
    private function fieldTtlCommand(string $command, callable $run): mixed
    {
        $redis = $this->store->raw();
        $redis->clearLastError();

        try {
            $result = $run($redis);
        } catch (RedisException $e) {
            if (str_contains(strtolower($e->getMessage()), 'unknown command')) {
                throw $this->unsupported($command, $e);
            }
            throw $e;
        }

        if ($result === false && str_contains(strtolower((string) $redis->getLastError()), 'unknown command')) {
            $redis->clearLastError();
            throw $this->unsupported($command, null);
        }

        return $result;
    }

    private function unsupported(string $command, ?Throwable $previous): RedisFeatureException
    {
        return new RedisFeatureException(sprintf(
            'Redis %s (per-field lifetimes) needs server %s or newer; this server reports %s. '
            . 'Give the whole hash a lifetime with expireKey() instead.',
            $command,
            self::FIELD_TTL_SINCE,
            $this->serverVersion() ?? 'an older version',
        ), previous: $previous);
    }

    private function serverVersion(): ?string
    {
        try {
            $info = $this->store->raw()->info('server');
        } catch (Throwable) {
            return null;
        }

        return is_array($info) ? ($info['redis_version'] ?? null) : null;
    }
}
