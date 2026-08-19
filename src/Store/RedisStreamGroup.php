<?php

declare(strict_types=1);

namespace Flytachi\Winter\Redis\Store;

use Redis;

/**
 * A handle on one consumer group of a stream, acting as one consumer within it.
 *
 * ```php
 * $events->ensureGroup('mailers');                       // once, at startup
 * $group = $events->group('mailers', consumer: 'worker-1');
 *
 * while (!$this->stopping) {
 *     foreach ($group->consume(count: 10, timeout: 5) as $entry) {
 *         $this->handle($entry);
 *         $group->ack($entry);
 *     }
 * }
 *
 * $group->close();
 * ```
 *
 * What a group buys, and what a list cannot give: the server remembers which entries it
 * handed to which consumer and which of them were acknowledged. An entry taken by a
 * worker that then died is not lost — it sits in the group's pending list, visible to
 * {@see pending()} and reclaimable with {@see claimStale()}.
 *
 * The acknowledgement is deliberately manual. Acknowledging on delivery would throw away
 * the single guarantee the group exists for: that an entry survives a consumer that
 * takes it and never finishes.
 *
 * @link https://winterframe.net/docs/redis-streams#group Working in a group
 */
final class RedisStreamGroup
{
    use ChecksCommandErrors;
    use UsesDedicatedConnection;

    /**
     * @param RedisStore $store Owner — supplies the connection and the prefix.
     * @param string $stream The **prefixed** stream key.
     * @param string $group Group name.
     * @param string $consumer This consumer's name within the group.
     */
    public function __construct(
        private readonly RedisStore $store,
        private readonly string $stream,
        private readonly string $group,
        private readonly string $consumer = 'default',
    ) {
    }

    /**
     * The same group seen as a different consumer.
     *
     * Consumer names matter: the server tracks pending entries per consumer, so two
     * workers sharing a name share a pending list and will reclaim each other's work.
     * Name them after something stable and unique — the worker slot, the hostname and
     * pid.
     *
     * @link https://winterframe.net/docs/redis-streams Streams: naming the consumer
     */
    public function as(string $consumer): self
    {
        return new self($this->store, $this->stream, $this->group, $consumer);
    }

    public function name(): string
    {
        return $this->group;
    }

    public function consumer(): string
    {
        return $this->consumer;
    }

    /** The endpoint this group's stream lives on. */
    public function configClass(): string
    {
        return $this->store->configClass();
    }

    // -------------------------------------------------------------------------
    // Consuming
    // -------------------------------------------------------------------------

    /**
     * Takes entries nobody in the group has been given yet.
     *
     * Each entry goes to exactly one consumer of the group and enters that consumer's
     * pending list until {@see ack()} clears it.
     *
     * @param int $count At most this many entries.
     * @param float $timeout Seconds to wait when there is nothing new; `0` waits
     *   indefinitely, and a negative value returns immediately without blocking (and
     *   without taking a connection of its own).
     * @return list<StreamEntry>
     *
     * @throws \Flytachi\Winter\Redis\RedisCommandException When the group does not
     *   exist — a mistyped name says so instead of quietly delivering nothing.
     *
     * @link https://winterframe.net/docs/redis-streams#consume Consuming new entries
     */
    public function consume(int $count = 10, float $timeout = 0.0): array
    {
        // A negative timeout means "look and return"; the driver expresses that by the
        // BLOCK argument being absent, not by a negative one — measured: passing -1 makes
        // xReadGroup answer `false` with no error to explain it.
        if ($timeout < 0) {
            return $this->read($this->store->raw(), '>', $count, null);
        }

        return $this->read($this->dedicatedConnection($timeout), '>', $count, (int) ($timeout * 1000));
    }

    /**
     * Re-reads what this consumer already holds but has not acknowledged.
     *
     * This is the first thing a restarted worker should do: entries it took before the
     * restart are still assigned to it and will not arrive through {@see consume()}
     * again. Reading them back is how the work resumes rather than waiting for the idle
     * timeout and a reclaim.
     *
     * @return list<StreamEntry>
     *
     * @link https://winterframe.net/docs/redis-streams#backlog Resuming after a restart
     */
    public function backlog(int $count = 10): array
    {
        return $this->read($this->store->raw(), '0', $count, null);
    }

    /**
     * Marks entries as handled, removing them from the group's pending list.
     *
     * @param StreamEntry|string ...$entries Entries or their identifiers.
     * @return int How many were pending and are now acknowledged.
     *
     * @link https://winterframe.net/docs/redis-streams#ack Acknowledging work
     */
    public function ack(StreamEntry|string ...$entries): int
    {
        if ($entries === []) {
            return 0;
        }

        $ids = array_map(
            static fn(StreamEntry|string $entry): string => $entry instanceof StreamEntry ? $entry->id : $entry,
            $entries,
        );

        return (int) $this->store->raw()->xAck($this->stream, $this->group, $ids);
    }

    // -------------------------------------------------------------------------
    // Recovery
    // -------------------------------------------------------------------------

    /**
     * Entries the group delivered and nobody acknowledged, with who holds them, how long
     * they have been idle and how many times they have been delivered.
     *
     * @param int $count At most this many entries.
     * @param string|null $consumer Limit to one consumer; `null` covers the group.
     * @return list<PendingEntry>
     *
     * @link https://winterframe.net/docs/redis-streams#pending Pending entries
     */
    public function pending(int $count = 100, ?string $consumer = null): array
    {
        $reply = $this->store->raw()->xPending($this->stream, $this->group, '-', '+', $count, $consumer);

        return PendingEntry::listFrom($reply);
    }

    /** How many entries the group has delivered and not had acknowledged. */
    public function pendingCount(): int
    {
        $reply = $this->store->raw()->xPending($this->stream, $this->group);

        return is_array($reply) ? (int) ($reply[0] ?? 0) : 0;
    }

    /**
     * Takes over entries that have been idle too long in someone else's hands, and
     * returns them.
     *
     * This is the recovery path: a consumer that died holding entries leaves them
     * pending forever, because only an acknowledgement clears them. Claiming moves them
     * to this consumer, resets their idle time and lets the work continue.
     *
     * ```php
     * foreach ($group->claimStale(idle: 60_000) as $entry) {
     *     $this->handle($entry);
     *     $group->ack($entry);
     * }
     * ```
     *
     * Claiming resets the idle clock, so a repeated call with the same threshold will
     * not return the same entries again — the loop converges instead of spinning.
     *
     * How many times an entry may be reclaimed before it is treated as poison is the
     * application's decision: {@see pending()} reports the delivery count, and what to
     * do with a high one — retry, alert, move aside — belongs to whoever knows what the
     * entry costs.
     *
     * @param int $idle Milliseconds an entry must have been idle to be claimable.
     * @param int $count At most this many entries.
     * @param string $from Identifier to start scanning the pending list from.
     * @return list<StreamEntry>
     *
     * @link https://winterframe.net/docs/redis-streams#claimstale Reclaiming stuck entries
     */
    public function claimStale(int $idle, int $count = 10, string $from = '0-0'): array
    {
        $reply = $this->store->raw()->xAutoClaim(
            $this->stream,
            $this->group,
            $this->consumer,
            $idle,
            $from,
            $count,
        );

        // XAUTOCLAIM answers [next cursor, entries, deleted ids].
        return StreamEntry::listFrom(is_array($reply) ? ($reply[1] ?? []) : []);
    }

    /**
     * Every consumer the group knows, as the server reports them: name, pending count,
     * idle time.
     *
     * @return list<array<string, mixed>>
     *
     * @link https://winterframe.net/docs/redis-streams#consumers Inspecting consumers
     */
    public function consumers(): array
    {
        $reply = $this->store->raw()->xInfo('CONSUMERS', $this->stream, $this->group);

        return is_array($reply) ? $reply : [];
    }

    /**
     * Removes the group, its pending list and its consumers. The stream and its entries
     * stay.
     *
     * @link https://winterframe.net/docs/redis-streams#destroy Removing a group
     */
    public function destroy(): bool
    {
        return (bool) $this->store->raw()->xGroup('DESTROY', $this->stream, $this->group);
    }

    // -------------------------------------------------------------------------

    /**
     * @return list<StreamEntry>
     */
    private function read(Redis $redis, string $id, int $count, ?int $block): array
    {
        $reply = $this->runChecked(
            $redis,
            fn(Redis $r): mixed => $block === null
                ? $r->xReadGroup($this->group, $this->consumer, [$this->stream => $id], $count)
                : $r->xReadGroup($this->group, $this->consumer, [$this->stream => $id], $count, $block),
        );

        return StreamEntry::listFrom(is_array($reply) ? ($reply[$this->stream] ?? []) : []);
    }
}
