<?php

declare(strict_types=1);

namespace Flytachi\Winter\Redis\Pool;

use Flytachi\Winter\CPool\ConnectionFactory;
use Flytachi\Winter\CPool\ResettableConnectionFactory;
use Flytachi\Winter\Redis\Config\Common\RedisConfigInterface;
use Psr\Log\LoggerInterface;
use Redis;

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
 * @link https://winterframe.net/docs/redis-pooling Connection pool
 */
final readonly class RedisConnectionFactory implements ResettableConnectionFactory
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

    /**
     * Puts back the session state a returning borrower changed: an open `MULTI` or
     * `pipeline()` is discarded, a switched database is selected back.
     *
     * Each of these lives on the connection, so the next borrower would inherit it. An
     * abandoned `MULTI` is the worst: the next borrower's reads come back as queued
     * `Redis` objects instead of values, its writes never reach the server, and the
     * next `exec()` on the connection publishes the writes the failed request meant to
     * abandon. Discard, never exec — executing would publish work its author never
     * finished.
     *
     * Logged at ERROR, not higher: the pool heals itself and keeps serving, but the
     * application has a defect — a `multi()`/`pipeline()` without `exec()`/`discard()`
     * on some path (an exception, an early return, a request timeout), or a `select()`
     * on a pooled connection, which should be a second config class instead.
     *
     * Costs no round trip when there is nothing to undo: the mode and the database are
     * phpredis's own client-side state. `WATCH` is not covered — the client keeps no
     * trace of it, and clearing it blindly would cost an `UNWATCH` round trip on every
     * return.
     */
    public function reset(object $connection): bool
    {
        try {
            /** @var RedisConfigInterface $connection */
            $redis = $connection->connection();
            $left = [];

            $mode = $redis->getMode();
            if ($mode !== Redis::ATOMIC) {
                $left[] = $mode === Redis::MULTI ? 'MULTI' : 'PIPELINE';
                $redis->discard();
                if ($redis->getMode() !== Redis::ATOMIC) {
                    throw new \RuntimeException('discard() left the connection in ' . $left[0]);
                }
            }

            $database = $connection->getDatabaseIndex();
            $current  = $redis->getDbNum();
            if ($current === false) {
                // The client cannot say — the socket is gone. Nothing to reset; retire it.
                return false;
            }
            if ($current !== $database) {
                $left[] = 'SELECT ' . $current;
                $redis->select($database);
            }

            if ($left !== []) {
                $this->logger->error(
                    "{$this->configClass}: connection returned to the pool with "
                    . implode(' + ', $left) . ' (' . self::unitOfWork() . ') — reset; queued commands discarded.'
                    . ' Close every multi()/pipeline() with exec()/discard(); use a separate config class'
                    . ' per database instead of select().'
                );
            }
            return true;
        } catch (\Throwable $e) {
            $this->logger->error(
                "{$this->configClass}: could not reset a returned connection — retired: {$e->getMessage()}"
            );
            return false;
        }
    }

    /** Which unit of work returned the connection — the coroutine id under Swoole. */
    private static function unitOfWork(): string
    {
        if (extension_loaded('swoole') && \Swoole\Coroutine::getCid() > 0) {
            return 'cid=' . \Swoole\Coroutine::getCid();
        }
        return 'pid=' . getmypid();
    }

    /** Closes the socket this slot owned. */
    public function close(object $connection): void
    {
        /** @var RedisConfigInterface $connection */
        $connection->disconnect();
    }
}
