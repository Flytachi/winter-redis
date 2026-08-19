<?php

declare(strict_types=1);

namespace Flytachi\Winter\Redis\Store;

/**
 * One entry of a stream: the identifier Redis assigned it and its fields.
 *
 * It exists because the driver hands entries over as `['1755600000123-0' => ['type' =>
 * 'signup']]` — a map whose only key is the identifier. Iterating that means reaching for
 * `key()` and `current()` on every entry, and the identifier is needed constantly: it is
 * what {@see RedisStreamGroup::ack()} acknowledges and what a reader remembers as its
 * position.
 *
 * @link https://winterframe.net/docs/redis-streams Streams
 */
final readonly class StreamEntry
{
    /**
     * @param string $id Identifier assigned by the server, `<milliseconds>-<sequence>`.
     * @param array<string, mixed> $fields The entry's fields.
     */
    public function __construct(
        public string $id,
        public array $fields,
    ) {
    }

    /** One field, or `$default` when the entry does not carry it. */
    public function get(string $field, mixed $default = null): mixed
    {
        return $this->fields[$field] ?? $default;
    }

    public function has(string $field): bool
    {
        return array_key_exists($field, $this->fields);
    }

    /**
     * The moment the entry was added, in milliseconds — the first half of the identifier.
     *
     * Redis builds identifiers from the server clock, so this is the server's notion of
     * when the entry arrived, not the client's.
     */
    public function timestamp(): int
    {
        return (int) strtok($this->id, '-');
    }

    /**
     * Builds entries from the shape the driver returns: `['id' => ['field' => 'value']]`.
     *
     * @param mixed $reply
     * @return list<self>
     */
    public static function listFrom(mixed $reply): array
    {
        if (!is_array($reply)) {
            return [];
        }

        $entries = [];
        foreach ($reply as $id => $fields) {
            $entries[] = new self((string) $id, is_array($fields) ? $fields : []);
        }

        return $entries;
    }
}
