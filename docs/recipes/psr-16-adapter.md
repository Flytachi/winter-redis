# Recipe: a PSR-16 adapter

`winter-redis` deliberately ships **no** PSR-16 (`psr/simple-cache`) implementation. This
page keeps the one that was written and tested, so it can be dropped back in the day a
real consumer appears.

## Why it is not in the package

Nothing in the ecosystem asks for it. No package in the framework's dependency tree
requires `psr/simple-cache` or `psr/cache`, and no application code references
`CacheInterface`. Shipping an interface nobody consumes costs a documentation page, a
second way to do the same thing, and the question "store or cache?" for every reader —
while adding it later costs a minor release, and removing it later would cost a major
one.

There is a second reason, visible in the code below: the two guards in the constructor.
PSR-16 semantics do not lie flat on a Redis store. `clear()` means "empty the cache",
which without a key prefix means the whole database; and the standard accepts any
serializable value, while a `SERIALIZER_NONE` connection stores bytes. Both had to be
refused at construction time rather than documented away.

## What to keep even without the adapter

One finding from writing it is real regardless: **a stored `false` and a missing key are
indistinguishable over the wire.** `RedisStore::get()` reports both as `null`, which is
the right trade for ordinary use. Any code that must tell them apart has to ask `EXISTS`,
as `read()` does below.

## Wiring, if you restore it

```php
$cache = new SimpleCache(new PageCacheStore());   // prefixed store, serializing config
$thirdPartyClient->setCache($cache);
```

Requirements: `composer require psr/simple-cache:^3.0`, a store with a `$prefix`, and a
config whose `$serializer` is not `SERIALIZER_NONE`.

## The code

### `src/Psr/SimpleCache.php`

```php
<?php

declare(strict_types=1);

namespace Flytachi\Winter\Redis\Psr;

use DateInterval;
use DateTimeImmutable;
use Flytachi\Winter\Redis\RedisPool;
use Flytachi\Winter\Redis\Store\RedisStore;
use LogicException;
use Psr\SimpleCache\CacheInterface;
use Redis;

/**
 * A PSR-16 face for a {@see RedisStore}, for libraries that ask for a cache rather than
 * for Redis.
 *
 * ```php
 * $cache = new SimpleCache(new PageCacheStore());
 * $thirdPartyClient->setCache($cache);
 * ```
 *
 * It is deliberately a separate, optional class. Redis is not a cache — lists, sets,
 * counters and pub/sub have no PSR-16 expression — so the interface is offered as an
 * adapter rather than as the package's own API. `psr/simple-cache` is a `suggest`, not
 * a requirement: the package works without it, and only this class needs it.
 *
 * Two constraints follow from the standard and are enforced rather than documented
 * away:
 *
 *   - **The store must have a prefix.** PSR-16 `clear()` empties the cache; without a
 *     prefix that would mean the whole Redis database, including keys nobody meant to
 *     hand over.
 *   - **The config must serialize.** PSR-16 accepts any serializable value, while a
 *     `SERIALIZER_NONE` connection stores bytes only and would mangle arrays.
 *
 * @link https://winterframe.net/packages/redis/psr-16 PSR-16 adapter
 */
final class SimpleCache implements CacheInterface
{
    /** Characters PSR-16 reserves; a key containing one is invalid. */
    private const string RESERVED = '{}()/\\@:';

    public function __construct(private readonly RedisStore $store)
    {
        if ($store->key('') === '') {
            throw new LogicException(
                'SimpleCache needs a store with a $prefix: PSR-16 clear() would otherwise '
                . 'empty the entire Redis database, not just this cache.',
            );
        }

        $serializer = RedisPool::config($store->configClass())->getSerializer();
        if ($serializer === Redis::SERIALIZER_NONE) {
            throw new LogicException(
                'SimpleCache needs a config with a serializer (Redis::SERIALIZER_PHP or '
                . 'SERIALIZER_JSON): PSR-16 stores arbitrary values, and SERIALIZER_NONE '
                . 'stores bytes only.',
            );
        }
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $this->assertKey($key);

        return $this->read($key, $default);
    }

    public function set(string $key, mixed $value, DateInterval|int|null $ttl = null): bool
    {
        $this->assertKey($key);
        $seconds = self::seconds($ttl);

        // A non-positive TTL means "already expired" in PSR-16 — the value must not be
        // stored, and any existing one must go.
        if ($seconds !== null && $seconds <= 0) {
            $this->store->delete($key);
            return true;
        }

        return $this->store->set($key, $value, $seconds);
    }

    public function delete(string $key): bool
    {
        $this->assertKey($key);
        $this->store->delete($key);

        // PSR-16: deleting a key that was not there is a success, not a failure.
        return true;
    }

    public function clear(): bool
    {
        $this->store->flush();

        return true;
    }

    /**
     * @param iterable<string> $keys
     * @return iterable<string, mixed>
     */
    public function getMultiple(iterable $keys, mixed $default = null): iterable
    {
        $out = [];
        foreach ($keys as $key) {
            $this->assertKey($key);
            $out[$key] = $this->read($key, $default);
        }

        return $out;
    }

    /**
     * @param iterable<string, mixed> $values
     */
    public function setMultiple(iterable $values, DateInterval|int|null $ttl = null): bool
    {
        $ok = true;
        foreach ($values as $key => $value) {
            $ok = $this->set((string) $key, $value, $ttl) && $ok;
        }

        return $ok;
    }

    /**
     * @param iterable<string> $keys
     */
    public function deleteMultiple(iterable $keys): bool
    {
        foreach ($keys as $key) {
            $this->assertKey($key);
        }
        $names = is_array($keys) ? $keys : iterator_to_array($keys, false);
        $this->store->delete(...array_map(strval(...), $names));

        return true;
    }

    public function has(string $key): bool
    {
        $this->assertKey($key);

        return $this->store->has($key);
    }

    /**
     * Reads a key, telling a stored `false` apart from a miss.
     *
     * {@see RedisStore::get()} reports both as `null`, which is the right trade for
     * ordinary use but wrong here: PSR-16 requires a cached `false` to come back as
     * `false` and `has()` to agree. Only `EXISTS` separates the two, and it is paid for
     * solely when the value read is `false`.
     */
    private function read(string $key, mixed $default): mixed
    {
        $name  = $this->store->key($key);
        $redis = $this->store->raw();
        $value = $redis->get($name);

        if ($value === false) {
            return $redis->exists($name) ? false : $default;
        }

        return $value;
    }

    /** @throws InvalidArgumentException */
    private function assertKey(string $key): void
    {
        if ($key === '') {
            throw new InvalidArgumentException('Cache key must not be empty.');
        }
        if (strpbrk($key, self::RESERVED) !== false) {
            throw new InvalidArgumentException(
                "Cache key [{$key}] contains a character reserved by PSR-16 (" . self::RESERVED . ').',
            );
        }
    }

    /** Normalises a PSR-16 TTL to whole seconds, or `null` for "no expiry". */
    private static function seconds(DateInterval|int|null $ttl): ?int
    {
        if ($ttl === null || is_int($ttl)) {
            return $ttl;
        }

        // DateInterval carries months and years, whose length depends on when they
        // start, so it is resolved against now rather than approximated.
        $now = new DateTimeImmutable();

        return $now->add($ttl)->getTimestamp() - $now->getTimestamp();
    }
}
```

### `src/Psr/InvalidArgumentException.php`

```php
<?php

declare(strict_types=1);

namespace Flytachi\Winter\Redis\Psr;

use Psr\SimpleCache\InvalidArgumentException as PsrInvalidArgumentException;

/**
 * Thrown for a key PSR-16 does not allow — an empty key, or one containing any of
 * `{}()/\@:`, which the standard reserves for future use.
 */
final class InvalidArgumentException extends \InvalidArgumentException implements PsrInvalidArgumentException
{
}
```

## The tests it came with

The suite that covered it lives in the history of this repository as
`tests/Unit/SimpleCacheTest.php` — 17 tests: arbitrary value round-trips, a stored
`false`, `DateInterval` TTLs, a non-positive TTL deleting instead of storing, multiple
operations, `clear()` scoped to the prefix, every reserved character rejected, and both
constructor guards.
