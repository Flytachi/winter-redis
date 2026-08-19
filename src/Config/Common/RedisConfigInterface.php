<?php

declare(strict_types=1);

namespace Flytachi\Winter\Redis\Config\Common;

use Redis;

/**
 * A Redis endpoint: where to connect, and the connection itself.
 *
 * A config instance owns exactly **one** socket. That is what makes it poolable —
 * the pool holds config instances, so `close()` deterministically drops the socket
 * and `validate()` reuses this object's own `PING`.
 *
 * The database index belongs to the config, not to a call. Switching databases on a
 * pooled connection would leak that choice to the next borrower, so one config class
 * means one database.
 */
interface RedisConfigInterface
{
    /** Fills in the credentials. Called once per instance, before the first connect. */
    public function setUp(): void;

    /** Opens the socket if it is not open yet. Idempotent. */
    public function connect(): void;

    /** Closes the socket if one is open. Safe to call when already closed. */
    public function disconnect(): void;

    /** Closes and reopens — used after a connection is found dead. */
    public function reconnect(): void;

    /** The live client, connecting on first use. */
    public function connection(): Redis;

    /** Liveness probe: did the server answer a `PING`? Never throws. */
    public function ping(): bool;

    /**
     * `ping()` with timing, for health endpoints.
     *
     * @return array{status: bool, latency: float|null, error: string|null}
     */
    public function pingDetail(): array;

    public function getHost(): string;

    public function getPort(): int;

    public function getDatabaseIndex(): int;

    /** One of the `Redis::SERIALIZER_*` constants — how values are encoded on the wire. */
    public function getSerializer(): int;

    /** Host, port and database — never the password. Safe to log. */
    public function getDsn(): string;
}
