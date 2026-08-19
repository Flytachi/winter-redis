<?php

declare(strict_types=1);

namespace Flytachi\Winter\Redis\Store;

/**
 * An entry a consumer group delivered but nobody has acknowledged yet.
 *
 * The interesting part is {@see $deliveries}: Redis counts how many times an entry has
 * been handed out. One delivery with a large {@see $idleMs} usually means the consumer
 * died holding it; a delivery count that keeps climbing means the entry itself is
 * breaking whoever takes it. Deciding what to do about either — retry, alert, bury — is
 * the application's call, so this type reports and does not act.
 *
 * @link https://winterframe.net/docs/redis-streams#pending Pending entries
 */
final readonly class PendingEntry
{
    /**
     * @param string $id The entry's identifier.
     * @param string $consumer Who holds it.
     * @param int $idleMs Milliseconds since it was last delivered.
     * @param int $deliveries How many times it has been delivered.
     */
    public function __construct(
        public string $id,
        public string $consumer,
        public int $idleMs,
        public int $deliveries,
    ) {
    }

    /**
     * Builds the list from the shape `XPENDING` returns in its extended form:
     * `[[id, consumer, idleMs, deliveries], ...]`.
     *
     * @return list<self>
     */
    public static function listFrom(mixed $reply): array
    {
        if (!is_array($reply)) {
            return [];
        }

        $pending = [];
        foreach ($reply as $row) {
            if (!is_array($row) || count($row) < 4) {
                continue;
            }
            $pending[] = new self((string) $row[0], (string) $row[1], (int) $row[2], (int) $row[3]);
        }

        return $pending;
    }
}
