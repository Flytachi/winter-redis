<?php

declare(strict_types=1);

namespace Flytachi\Winter\Redis\Pool;

use Flytachi\Winter\CPool\ConnectionFactory;
use Flytachi\Winter\Redis\Config\Common\RedisConfigInterface;
use Psr\Log\LoggerInterface;

/**
 * Adapts a {@see RedisConfigInterface} to the driver-agnostic {@see ConnectionFactory}
 * that {@see \Flytachi\Winter\CPool\ConnectionPool} drives.
 *
 * The pooled resource is the **config instance**, not the raw `\Redis` — the config
 * owns the socket (`connection()`/`disconnect()`/`ping()`), so pooling it lets
 * `close()` drop the socket deterministically and `validate()` reuse the config's own
 * `PING`. Each {@see create()} builds a fresh config, so every pool slot gets an
 * independent socket with its own authentication and database selection.
 *
 * @link https://winterframe.net/packages/redis/pooling Pooling
 */
final readonly class RedisConnectionFactory implements ConnectionFactory
{
    /**
     * @param class-string<RedisConfigInterface> $configClass Config to instantiate per slot.
     * @param LoggerInterface $logger Where slot lifecycle events are reported.
     */
    public function __construct(
        private string $configClass,
        private LoggerInterface $logger,
    ) {
    }

    /** Opens one independent connection (own socket) through a fresh config instance. */
    public function create(): object
    {
        /** @var RedisConfigInterface $config */
        $config = new ($this->configClass)();
        $config->setUp();
        $config->connect();
        $this->logger->debug("slot opened: {$this->configClass} {$config->getDsn()}");

        return $config;
    }

    /**
     * Liveness probe — one `PING` round trip.
     *
     * Unlike PPA, which cannot trust its driver's `ping()`, the config's own probe is
     * used directly: it already swallows every `Throwable` and accepts both reply
     * shapes phpredis produces, so a connection that cannot complete the round trip
     * answers `false`.
     */
    public function validate(object $connection): bool
    {
        /** @var RedisConfigInterface $connection */
        return $connection->ping();
    }

    /** Closes the socket this slot owned. */
    public function close(object $connection): void
    {
        /** @var RedisConfigInterface $connection */
        $connection->disconnect();
    }
}
