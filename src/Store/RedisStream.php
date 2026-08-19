<?php

declare(strict_types=1);

namespace Flytachi\Winter\Redis\Store;

use Redis;

/**
 * A handle on one Redis stream — an append-only log of entries, each with an identifier
 * the server assigns and a set of fields.
 *
 * ```php
 * $events = $store->stream('events');
 * $events->add(['type' => 'signup', 'user' => '42']);
 *
 * foreach ($events->range(count: 100) as $entry) {
 *     echo $entry->id, ' ', $entry->get('type'), PHP_EOL;
 * }
 * ```
 *
 * What separates a stream from a [list](RedisList): reading does not consume. Entries
 * stay until they are trimmed away, any number of readers can see the same ones, and a
 * {@see RedisStreamGroup} lets the server itself track who took what and what has not
 * been acknowledged. That last part is the reason to reach for a stream at all — it is
 * the bookkeeping a list leaves to the application.
 *
 * The price is that a stream grows forever unless bounded. Give {@see add()} a `cap`, or
 * trim on a schedule.
 *
 * @link https://winterframe.net/docs/redis-streams Streams
 */
final class RedisStream
{
    use ChecksCommandErrors;
    use UsesDedicatedConnection;

    /** Where {@see follow()} has read up to; `null` until it first runs. */
    private ?string $cursor = null;

    /**
     * @param RedisStore $store Owner — supplies the connection and the prefix.
     * @param string $name The **prefixed** key, as the server sees it.
     */
    public function __construct(
        private readonly RedisStore $store,
        private readonly string $name,
    ) {
    }

    /** The key as the server sees it, prefix included. */
    public function name(): string
    {
        return $this->name;
    }

    /** The endpoint this stream lives on. */
    public function configClass(): string
    {
        return $this->store->configClass();
    }

    // -------------------------------------------------------------------------
    // Writing
    // -------------------------------------------------------------------------

    /**
     * Appends an entry and returns the identifier the server gave it.
     *
     * ```php
     * $events->add(['type' => 'signup', 'user' => '42']);        // '1755600000123-0'
     * $events->add(['level' => 'warn', 'msg' => $text], cap: 10_000);
     * ```
     *
     * @param array<string, mixed> $fields The entry's fields. At least one is required —
     *   Redis has no concept of an empty entry.
     * @param int|null $cap Keep roughly this many entries, dropping the oldest. `null`
     *   lets the stream grow without bound.
     * @param bool $exact Trim to exactly `$cap` instead of approximately.
     * @param string $id Identifier to use; `*` lets the server assign one, which is
     *   almost always what you want since server identifiers are monotonic.
     *
     * @link https://winterframe.net/docs/redis-streams#add Appending an entry
     */
    public function add(array $fields, ?int $cap = null, bool $exact = false, string $id = '*'): string
    {
        $redis = $this->store->raw();
        $added = $this->runChecked($redis, fn(Redis $r): mixed => $r->xAdd(
            $this->name,
            $id,
            $fields,
            $cap ?? 0,
            $cap !== null && !$exact,
        ));

        return (string) $added;
    }

    /**
     * Removes entries by identifier and returns how many existed.
     *
     * Deleting from the middle of a stream is allowed but unusual: a stream is a log,
     * and the ordinary way to bound it is {@see trim()}. Deletion is for the exceptional
     * case — an entry that must not be readable again.
     *
     * @link https://winterframe.net/docs/redis-streams#delete Removing entries
     */
    public function delete(string ...$ids): int
    {
        if ($ids === []) {
            return 0;
        }

        return (int) $this->store->raw()->xDel($this->name, $ids);
    }

    /**
     * Keeps roughly the newest `$cap` entries and returns how many were removed.
     *
     * Approximate trimming stops at a node boundary of the stream's internal
     * representation, so the result can hold somewhat more than asked — and costs far
     * less than walking to an exact count. On a short stream it may remove **nothing**
     * at all, which is correct rather than broken.
     *
     * @param bool $exact Trim to exactly `$cap`. Predictable, and more work for the server.
     *
     * @link https://winterframe.net/docs/redis-streams#trim Bounding the stream
     */
    public function trim(int $cap, bool $exact = false): int
    {
        return (int) $this->store->raw()->xTrim($this->name, (string) $cap, !$exact);
    }

    /**
     * Removes everything older than an identifier and returns how many went.
     *
     * This is how a stream is kept to a period rather than to a count: identifiers begin
     * with the server's millisecond clock, so "older than a day" is an identifier.
     *
     * ```php
     * $events->trimBefore((string) ((time() - 86400) * 1000));
     * ```
     *
     * @link https://winterframe.net/docs/redis-streams#trimbefore Trimming by age
     */
    public function trimBefore(string $id, bool $exact = false): int
    {
        return (int) $this->store->raw()->xTrim($this->name, $id, !$exact, true);
    }

    // -------------------------------------------------------------------------
    // Reading
    // -------------------------------------------------------------------------

    /** How many entries the stream holds; `0` when it does not exist. */
    public function count(): int
    {
        return (int) $this->store->raw()->xLen($this->name);
    }

    /**
     * Entries in the order they were added, oldest first, taking nothing out.
     *
     * @param string|null $from Identifier to start at, inclusive; `null` = the beginning.
     * @param string|null $to Identifier to stop at, inclusive; `null` = the end.
     * @param int|null $count At most this many entries; `null` = no limit.
     * @return list<StreamEntry>
     *
     * @link https://winterframe.net/docs/redis-streams#range Reading a range
     */
    public function range(?string $from = null, ?string $to = null, ?int $count = null): array
    {
        $redis = $this->store->raw();
        $reply = $count === null
            ? $redis->xRange($this->name, $from ?? '-', $to ?? '+')
            : $redis->xRange($this->name, $from ?? '-', $to ?? '+', $count);

        return StreamEntry::listFrom($reply);
    }

    /**
     * The same, newest first — the cheap way to look at what just happened.
     *
     * @return list<StreamEntry>
     *
     * @link https://winterframe.net/docs/redis-streams#reverse Reading newest first
     */
    public function reverse(?int $count = null): array
    {
        $redis = $this->store->raw();
        $reply = $count === null
            ? $redis->xRevRange($this->name, '+', '-')
            : $redis->xRevRange($this->name, '+', '-', $count);

        return StreamEntry::listFrom($reply);
    }

    /**
     * Entries added after a given identifier, without waiting.
     *
     * This is the building block of a reader that keeps its own position: store the id
     * of the last entry handled, pass it back next time. Two readers doing so are
     * independent — a stream is not consumed by reading.
     *
     * @return list<StreamEntry>
     *
     * @link https://winterframe.net/docs/redis-streams#after Reading from a position
     */
    public function after(string $id, int $count = 10): array
    {
        $reply = $this->store->raw()->xRead([$this->name => $id], $count, -1);

        return StreamEntry::listFrom(is_array($reply) ? ($reply[$this->name] ?? []) : []);
    }

    /**
     * Waits for entries newer than the last ones this handle saw.
     *
     * ```php
     * $events = $store->stream('events');
     *
     * while (!$this->stopping) {
     *     foreach ($events->follow(timeout: 5) as $entry) {
     *         $this->handle($entry);
     *     }
     * }
     *
     * $events->close();
     * ```
     *
     * The handle remembers where it got to, so nothing added between two calls is
     * missed — which is why the handle is worth keeping for the loop rather than
     * re-taking it each iteration.
     *
     * The first call starts from **now**, and pays one extra round trip to find out
     * where "now" is. The obvious alternative — Redis's own `$`, meaning "entries added
     * after this call begins" — cannot be used across calls: entries written between two
     * of them would fall into neither, and the loop would lose work without a trace.
     * Resolving the position once turns the tail into an unbroken sequence of concrete
     * identifiers. History is read with {@see range()} or {@see after()}, deliberately
     * not mixed into a tail.
     *
     * **This does not use the pool.** A blocking read holds its connection for the whole
     * wait, so the handle opens one of its own; {@see close()} releases it.
     *
     * @param float $timeout Seconds to wait; `0` waits indefinitely.
     * @param string|null $from Position to start from on the **first** call — pass an
     *   identifier, or `'0'` to begin with everything the stream already holds.
     * @return list<StreamEntry>
     *
     * @link https://winterframe.net/docs/redis-streams#follow Tailing a stream
     */
    public function follow(float $timeout = 0.0, int $count = 10, ?string $from = null): array
    {
        $this->cursor ??= $from ?? $this->lastId();

        $redis = $this->dedicatedConnection($timeout);
        $reply = $redis->xRead([$this->name => $this->cursor], $count, (int) ($timeout * 1000));

        $entries = StreamEntry::listFrom(is_array($reply) ? ($reply[$this->name] ?? []) : []);
        if ($entries !== []) {
            $this->cursor = $entries[array_key_last($entries)]->id;
        }

        return $entries;
    }

    /**
     * The identifier of the newest entry, or `0-0` for a stream that does not exist yet.
     *
     * `XREVRANGE` is used rather than `XINFO STREAM` because it answers plainly for a
     * missing key instead of raising, and a tail armed on a stream nobody has written to
     * is an ordinary case.
     */
    private function lastId(): string
    {
        $last = $this->store->raw()->xRevRange($this->name, '+', '-', 1);

        if (!is_array($last) || $last === []) {
            return '0-0';
        }

        return (string) array_key_first($last);
    }

    // -------------------------------------------------------------------------
    // Consumer groups
    // -------------------------------------------------------------------------

    /**
     * Creates a consumer group unless it is already there, and says which happened.
     *
     * Call it once at startup. It is deliberately separate from {@see group()}: creating
     * a group on demand would turn a mistyped group name into an empty new group that
     * silently receives nothing, instead of an error naming the group that does not
     * exist.
     *
     * @param string $name Group name.
     * @param string $from Where the group starts reading: `'0'` from the beginning of
     *   the stream, `'$'` from entries added after this call.
     * @return bool `true` when the group was created, `false` when it already existed.
     *
     * @link https://winterframe.net/docs/redis-streams#ensuregroup Creating a group
     */
    public function ensureGroup(string $name, string $from = '0'): bool
    {
        $redis = $this->store->raw();
        $redis->clearLastError();

        // `true` also creates the stream when it does not exist yet, so a group can be
        // declared at startup before the first entry is ever written.
        $created = $redis->xGroup('CREATE', $this->name, $name, $from, true);

        if ($created === false) {
            $error = (string) $redis->getLastError();
            $redis->clearLastError();

            // The one refusal that is not a failure: the group is already there, which
            // is exactly the state this method promises.
            if (!str_contains($error, 'BUSYGROUP')) {
                $this->failCommand($error);
            }

            return false;
        }

        return true;
    }

    /**
     * A handle on one consumer group. Does not create it — see {@see ensureGroup()}.
     *
     * @link https://winterframe.net/docs/redis-streams#group Working in a group
     */
    public function group(string $name, string $consumer = 'default'): RedisStreamGroup
    {
        return new RedisStreamGroup($this->store, $this->name, $name, $consumer);
    }

    /**
     * Every consumer group on this stream, as the server reports them: name, consumers,
     * pending count, last delivered identifier, lag.
     *
     * @return list<array<string, mixed>>
     *
     * @link https://winterframe.net/docs/redis-streams#groups Inspecting groups
     */
    public function groups(): array
    {
        $reply = $this->store->raw()->xInfo('GROUPS', $this->name);

        return is_array($reply) ? $reply : [];
    }

    /**
     * The server's own summary of the stream: length, first and last entry, number of
     * groups, identifiers seen.
     *
     * @return array<string, mixed>
     *
     * @link https://winterframe.net/docs/redis-streams#info Inspecting the stream
     */
    public function info(): array
    {
        $reply = $this->store->raw()->xInfo('STREAM', $this->name);

        return is_array($reply) ? $reply : [];
    }

    // -------------------------------------------------------------------------
    // The key as a whole
    // -------------------------------------------------------------------------

    public function expireKey(int $seconds): bool
    {
        return (bool) $this->store->raw()->expire($this->name, $seconds);
    }

    /** Remaining lifetime of the stream, `null` when it has none or does not exist. */
    public function keyTtl(): ?int
    {
        $ttl = $this->store->raw()->ttl($this->name);

        return is_int($ttl) && $ttl >= 0 ? $ttl : null;
    }

    /** Deletes the stream, its entries and its consumer groups. */
    public function deleteKey(): bool
    {
        return (bool) $this->store->raw()->del($this->name);
    }
}
