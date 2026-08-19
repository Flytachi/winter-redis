<?php

declare(strict_types=1);

namespace Flytachi\Winter\Redis\Store;

use Flytachi\Winter\Redis\Config\Common\RedisConfigInterface;
use Flytachi\Winter\Redis\RedisPool;
use LogicException;
use Redis;

/**
 * A handle on one Redis list — an ordered sequence under a single key, most often a
 * queue.
 *
 * Obtained from a store, which supplies the prefix once:
 *
 * ```php
 * $jobs = $store->list('jobs');      // the server sees "queue:jobs"
 * $jobs->push($payload);
 * $job = $jobs->pop();               // null when empty
 * ```
 *
 * The default direction is a queue: {@see push()} appends to the tail and {@see pop()}
 * takes from the head, so what goes in first comes out first. The other end is reachable
 * through {@see pushFront()} and {@see popBack()}.
 *
 * Every method except {@see consume()} uses the pooled connection of the current unit of
 * work. `consume()` is the exception on purpose — see its own note.
 *
 * @link https://winterframe.net/docs/redis-lists Lists
 */
final class RedisList
{
    /** Owned by this handle alone, opened on the first blocking read. */
    private ?RedisConfigInterface $dedicated = null;

    /**
     * @param RedisStore $store Owner — supplies the connection and the prefix.
     * @param string $name The **prefixed** key, as the server sees it.
     */
    public function __construct(
        private readonly RedisStore $store,
        private readonly string $name,
    ) {
    }

    /**
     * The key as the server sees it, prefix included.
     *
     * @link https://winterframe.net/docs/redis-lists#name The prefixed key name
     */
    public function name(): string
    {
        return $this->name;
    }

    /**
     * The endpoint this list lives on — two lists must share it to be moved between.
     *
     * @link https://winterframe.net/docs/redis-lists#configclass The endpoint
     */
    public function configClass(): string
    {
        return $this->store->configClass();
    }

    // -------------------------------------------------------------------------
    // Writing
    // -------------------------------------------------------------------------

    /**
     * Appends to the tail and returns the resulting length.
     *
     * With `$cap` the list is kept to that many newest elements — the "last N events"
     * pattern:
     *
     * ```php
     * $events->push($line, cap: 1000);
     * ```
     *
     * The append and the trim run inside one `MULTI`, so the list is never briefly
     * longer than the cap and cannot stay that way if something fails in between.
     *
     * @param int|null $cap Keep at most this many elements, newest kept.
     *
     * @link https://winterframe.net/docs/redis-lists#push Appending to the tail
     */
    public function push(mixed $value, ?int $cap = null): int
    {
        if ($cap === null) {
            return (int) $this->store->raw()->rPush($this->name, $value);
        }

        $length = $this->capped(fn(Redis $redis): mixed => $redis->rPush($this->name, $value), -$cap, -1);

        return min($length, $cap);
    }

    /**
     * Prepends to the head and returns the resulting length.
     *
     * `$cap` keeps the newest elements here too — which, since new elements arrive at
     * the head, means the first `$cap` of them.
     *
     * @link https://winterframe.net/docs/redis-lists#pushfront Prepending to the head
     */
    public function pushFront(mixed $value, ?int $cap = null): int
    {
        if ($cap === null) {
            return (int) $this->store->raw()->lPush($this->name, $value);
        }

        $length = $this->capped(fn(Redis $redis): mixed => $redis->lPush($this->name, $value), 0, $cap - 1);

        return min($length, $cap);
    }

    /**
     * Takes the head, or `null` when the list is empty.
     *
     * @link https://winterframe.net/docs/redis-lists#pop Taking from the head
     */
    public function pop(): mixed
    {
        $value = $this->store->raw()->lPop($this->name);

        return $value === false ? null : $value;
    }

    /**
     * Takes the tail, or `null` when the list is empty.
     *
     * @link https://winterframe.net/docs/redis-lists#popback Taking from the tail
     */
    public function popBack(): mixed
    {
        $value = $this->store->raw()->rPop($this->name);

        return $value === false ? null : $value;
    }

    /**
     * Moves the head of this list onto the tail of another, atomically, and returns the
     * element moved — `null` when this list is empty.
     *
     * This is `LMOVE`, and it is the building block for a queue that does not lose work:
     * `pop()` alone takes an element out and holds it only in the worker's memory, so a
     * crash between taking and finishing loses it. Moving it to a second list instead
     * means it is always in exactly one of them.
     *
     * ```php
     * $job = $jobs->moveTo($processing);
     * // ... handle it ...
     * $processing->remove($job);
     * ```
     *
     * What happens to jobs left in `$processing` by a crashed worker — retry, alert,
     * bury — is the application's decision, and deliberately not this package's.
     *
     * @throws LogicException When the lists live on different endpoints: both keys are
     *   sent over one connection, so a list belonging to another config would be
     *   written in **this** database under that name — silently, and to the wrong place.
     *
     * @link https://winterframe.net/docs/redis-lists#moveto Atomic move between lists
     */
    public function moveTo(self $target): mixed
    {
        if ($target->configClass() !== $this->configClass()) {
            throw new LogicException(sprintf(
                'Cannot move between [%s] and [%s]: LMOVE runs on one connection, so both lists '
                . 'must belong to the same config.',
                $this->configClass(),
                $target->configClass(),
            ));
        }

        $value = $this->store->raw()->lMove($this->name, $target->name(), Redis::LEFT, Redis::RIGHT);

        return $value === false ? null : $value;
    }

    /**
     * Waits for an element and takes the head — for a consumer loop.
     *
     * ```php
     * $jobs = $store->list('jobs');
     * while (!$stopping) {
     *     $job = $jobs->consume(timeout: 5);
     *     if ($job !== null) {
     *         $this->handle($job);
     *     }
     * }
     * $jobs->close();
     * ```
     *
     * **This does not use the pool.** A blocking read occupies its connection for the
     * whole wait, so ten consumers waiting thirty seconds would hold ten pool slots for
     * thirty seconds and every other request would fail with an exhausted pool — while
     * Redis itself sits idle. Instead the handle opens one connection of its own on the
     * first call and reuses it; {@see close()} gives it back.
     *
     * The read timeout of that connection is raised to cover the wait. It has to be: a
     * connection is configured to give up on a silent server after `readTimeout`
     * seconds, and a blocking read is a deliberately silent server — measured, the
     * default two seconds kills a five-second wait with `read error on connection`.
     *
     * @param float $timeout Seconds to wait; `0` waits indefinitely.
     * @return mixed The element, or `null` if the wait ended empty.
     *
     * @link https://winterframe.net/docs/redis-lists#consume Blocking read on its own connection
     */
    public function consume(float $timeout = 0.0): mixed
    {
        $redis = $this->dedicatedConnection();
        $redis->setOption(Redis::OPT_READ_TIMEOUT, $timeout > 0 ? $timeout + 1.0 : -1);

        $reply = $redis->blPop([$this->name], $timeout);

        return is_array($reply) && isset($reply[1]) ? $reply[1] : null;
    }

    /**
     * Closes the connection {@see consume()} opened, if there is one.
     *
     * @link https://winterframe.net/docs/redis-lists#close Releasing the dedicated connection
     */
    public function close(): void
    {
        $this->dedicated?->disconnect();
        $this->dedicated = null;
    }

    /**
     * Removes elements equal to a value and returns how many were removed.
     *
     * @param int $count Occurrences to remove, from the head; `0` removes all of them.
     *
     * @link https://winterframe.net/docs/redis-lists#remove Removing by value
     */
    public function remove(mixed $value, int $count = 1): int
    {
        return (int) $this->store->raw()->lRem($this->name, $value, $count);
    }

    /**
     * Keeps only the given range, dropping everything else.
     *
     * @link https://winterframe.net/docs/redis-lists#trim Keeping a range
     */
    public function trim(int $start, int $stop): bool
    {
        return (bool) $this->store->raw()->lTrim($this->name, $start, $stop);
    }

    /**
     * Overwrites the element at an index.
     *
     * @return bool `false` when the index is out of range — the list is authoritative
     *   about its own length, so this is an answer, not an exception.
     *
     * @link https://winterframe.net/docs/redis-lists#set Overwriting by index
     */
    public function set(int $index, mixed $value): bool
    {
        $redis = $this->store->raw();
        $done  = (bool) $redis->lSet($this->name, $index, $value);

        if (!$done) {
            // Redis parks `ERR index out of range` on the connection, and the connection
            // goes back to the pool. The next borrower would find someone else's error
            // waiting in getLastError(); the answer is already in the return value.
            $redis->clearLastError();
        }

        return $done;
    }

    // -------------------------------------------------------------------------
    // Reading
    // -------------------------------------------------------------------------

    /**
     * How many elements the list holds; `0` when it does not exist.
     *
     * @link https://winterframe.net/docs/redis-lists#count Length
     */
    public function count(): int
    {
        return (int) $this->store->raw()->lLen($this->name);
    }

    /**
     * A slice, without taking anything out. Indexes may be negative, counted from the
     * tail, so the default is the whole list.
     *
     * @return list<mixed>
     *
     * @link https://winterframe.net/docs/redis-lists#range A slice
     */
    public function range(int $start = 0, int $stop = -1): array
    {
        $values = $this->store->raw()->lRange($this->name, $start, $stop);

        return is_array($values) ? $values : [];
    }

    /**
     * The element at an index, or `null` when there is none there.
     *
     * @link https://winterframe.net/docs/redis-lists#at By index
     */
    public function at(int $index): mixed
    {
        $value = $this->store->raw()->lIndex($this->name, $index);

        return $value === false ? null : $value;
    }

    // -------------------------------------------------------------------------
    // The key as a whole
    // -------------------------------------------------------------------------

    /**
     * @link https://winterframe.net/docs/redis-lists#expirekey The list's lifetime
     */
    public function expireKey(int $seconds): bool
    {
        return (bool) $this->store->raw()->expire($this->name, $seconds);
    }

    /**
     * Remaining lifetime of the list, `null` when it has none or does not exist.
     *
     * @link https://winterframe.net/docs/redis-lists#keyttl The list's remaining lifetime
     */
    public function keyTtl(): ?int
    {
        $ttl = $this->store->raw()->ttl($this->name);

        return is_int($ttl) && $ttl >= 0 ? $ttl : null;
    }

    /**
     * @link https://winterframe.net/docs/redis-lists#deletekey Deleting the list
     */
    public function deleteKey(): bool
    {
        return (bool) $this->store->raw()->del($this->name);
    }

    // -------------------------------------------------------------------------

    /** Runs a push and a trim as one transaction, returning the length the push saw. */
    private function capped(callable $push, int $start, int $stop): int
    {
        return $this->store->transaction(function (Redis $redis) use ($push, $start, $stop): int {
            $redis->multi();
            $push($redis);
            $redis->lTrim($this->name, $start, $stop);
            $replies = $redis->exec();

            return is_array($replies) ? (int) ($replies[0] ?? 0) : 0;
        });
    }

    private function dedicatedConnection(): Redis
    {
        $this->dedicated ??= RedisPool::dedicated($this->configClass());

        return $this->dedicated->connection();
    }
}
