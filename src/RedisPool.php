<?php

declare(strict_types=1);

namespace Flytachi\Winter\Redis;

use Flytachi\Winter\CPool\ConnectionPool;
use Flytachi\Winter\CPool\PoolException;
use Flytachi\Winter\CPool\PoolPolicy;
use Flytachi\Winter\CPool\SingleConnection;
use Flytachi\Winter\Redis\Config\Common\RedisConfigInterface;
use Flytachi\Winter\Redis\Pool\BorrowedConnection;
use Flytachi\Winter\Redis\Pool\RedisConnectionFactory;
use Flytachi\Winter\Redis\Pool\RedisPoolConfigInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Redis;
use Throwable;

/**
 * The connection registry: one pool per config class, and a client for the current unit
 * of work.
 *
 * ## Under Swoole
 * Each config class gets its own {@see ConnectionPool}. The **first** {@see store()}
 * call inside a coroutine borrows one connection and caches it in the coroutine
 * context; a `defer` returns it when the coroutine ends. Nothing in application code
 * borrows or releases by hand, and two coroutines never share a socket.
 *
 * ## Everywhere else
 * A process serves one unit of work at a time, so there is nothing to distribute: each
 * config gets a {@see SingleConnection}, which keeps the same liveness probing and
 * lifetime rotation. A long-running CLI worker therefore survives a Redis restart the
 * same way a pooled worker does.
 *
 * ## Pool size
 * Configs implementing {@see RedisPoolConfigInterface} size their own pool; the rest
 * take {@see DEFAULT_POOL_SIZE}. The ceiling is a property of the server: the number
 * that matters is `worker_num × poolMax × instances` against the `maxclients` of the
 * Redis you are talking to.
 *
 * @link https://winterframe.net/packages/redis/pooling Pooling
 */
final class RedisPool
{
    /**
     * Pool size for configs that do not implement {@see RedisPoolConfigInterface}.
     *
     * Higher than PPA's database default because Redis commands are short and its
     * `maxclients` (10 000 by default) is far more generous than a database's
     * `max_connections`.
     */
    private const int DEFAULT_POOL_SIZE = 10;

    /** Swoole: one pool per config class. @var array<string, ConnectionPool> */
    private static array $pools = [];

    /** Registered config instances, for diagnostics. @var array<string, RedisConfigInterface> */
    private static array $configs = [];

    /** Non-coroutine: one self-maintaining connection per config class. @var array<string, SingleConnection> */
    private static array $static = [];

    private static ?LoggerInterface $logger = null;

    /**
     * Routes pool lifecycle events (slot opened, borrow, release, eviction) somewhere.
     * Until this is called the pool is silent.
     */
    public static function setLogger(LoggerInterface $logger): void
    {
        self::$logger = $logger;
    }

    private static function logger(): LoggerInterface
    {
        return self::$logger ??= new NullLogger();
    }

    // -------------------------------------------------------------------------
    // Public API
    // -------------------------------------------------------------------------

    /**
     * The initialised config for a class — instantiated and `setUp()` on first access,
     * cached afterwards.
     *
     * This instance is for reading settings, not for connecting: it is deliberately
     * **not** one of the pooled instances, each of which owns its own socket.
     *
     * @param class-string<RedisConfigInterface> $configClass
     */
    public static function config(string $configClass): RedisConfigInterface
    {
        $key = self::key($configClass);
        if (!isset(self::$configs[$key])) {
            /** @var RedisConfigInterface $config */
            $config = new $configClass();
            $config->setUp();
            self::$configs[$key] = $config;
            self::logger()->debug("config registered: {$configClass} {$config->getDsn()}");
        }

        return self::$configs[$key];
    }

    /**
     * A live client for the given config, valid for the current unit of work.
     *
     * Under Swoole the connection is borrowed on first use in the coroutine and
     * returned automatically when it ends. Do not store the returned object anywhere
     * that outlives the request.
     *
     * @param class-string<RedisConfigInterface> $configClass
     * @throws RedisPoolException When no usable connection could be obtained.
     */
    public static function store(string $configClass): Redis
    {
        return self::inCoroutine()
            ? self::coroutineStore($configClass)
            : self::staticStore($configClass);
    }

    /**
     * Every registered config instance, for health checks and diagnostics.
     *
     * @return array<string, RedisConfigInterface> Keyed by config FQCN.
     */
    public static function showConfigs(): array
    {
        $out = [];
        foreach (self::$configs as $key => $config) {
            $out[base64_decode($key)] = $config;
        }

        return $out;
    }

    /**
     * Reports a failure that happened **while using** a borrowed connection, so a dead
     * one is retired instead of being handed to the next borrower.
     *
     * The verdict comes from probing the connection, not from reading the error
     * message. PPA cannot afford that — a database probe is a full round trip on a
     * connection that may be hanging — but a Redis `PING` is one cheap round trip, and
     * asking the connection whether it is alive is exact where message matching is
     * guesswork. A failure that leaves the connection healthy (a wrong type, a bad
     * argument) therefore keeps it in the pool.
     *
     * The failed command is deliberately not retried: the pool cannot know whether the
     * server had already applied it, so replaying could duplicate a write.
     *
     * @param class-string<RedisConfigInterface> $configClass Config whose connection failed.
     * @param Throwable $error The failure as thrown by phpredis.
     * @return bool Whether the connection was found dead and scheduled for eviction.
     */
    public static function reportFailure(string $configClass, Throwable $error): bool
    {
        $key = self::key($configClass);

        if (self::inCoroutine()) {
            $ctxKey = 'winter_redis_' . $key;
            $ctx    = \Swoole\Coroutine::getContext();
            $held   = $ctx[$ctxKey] ?? null;
            if (!$held instanceof BorrowedConnection) {
                return false;
            }

            /** @var RedisConfigInterface $config */
            $config = $held->entry->resource;
            if ($config->ping()) {
                return false; // the command failed, the connection did not
            }

            // Mark it for the defer to evict and drop it from the context, so the next
            // command in this same coroutine borrows a healthy connection.
            $held->dead = true;
            unset($ctx[$ctxKey]);
            self::logger()->warning("evict: {$configClass} (connection lost in use) — {$error->getMessage()}");

            return true;
        }

        if (!isset(self::$static[$key])) {
            return false;
        }

        $config = self::$static[$key]->peek();
        if ($config instanceof RedisConfigInterface && $config->ping()) {
            return false;
        }

        self::$static[$key]->evict();
        self::logger()->warning("evict: {$configClass} (connection lost in use) — {$error->getMessage()}");

        return true;
    }

    /**
     * Live utilisation of every coroutine pool, keyed by config FQCN.
     *
     * The numbers are **per worker**: each Swoole worker holds its own pool, so a health
     * request reports the worker that served it. The non-coroutine path has no pool and
     * is not reported.
     *
     * @return array<string, array{total: int, idle: int, active: int, maximum: int}>
     */
    public static function stats(): array
    {
        $out = [];
        foreach (self::$pools as $key => $pool) {
            $out[base64_decode($key)] = $pool->stats();
        }

        return $out;
    }

    /**
     * Closes every connection this process owns — the worker-shutdown counterpart of
     * {@see reset()}.
     *
     * Closing a pool also releases its housekeeping timer; a live timer would keep the
     * worker's reactor from draining until Swoole force-kills it.
     */
    public static function shutdown(): void
    {
        foreach (self::$pools as $pool) {
            $pool->close();
        }
        foreach (self::$static as $connection) {
            $connection->close();
        }
        self::forgetAll();
    }

    /**
     * Drops every cached connection, pool and config **without closing** them, so the
     * next {@see store()} opens fresh sockets — the fork-safety reset.
     *
     * A fork copies file descriptors, so a connection cached before the fork is shared
     * with the parent and would corrupt the wire protocol. The child must forget the
     * inherited sockets without closing them, since closing would tear down the
     * parent's connection too.
     *
     * `abandon()` comes first for a reason: a housekeeping `Timer::tick` callback holds
     * a reference to its pool, so a pool that is merely dereferenced would stay alive
     * and keep maintaining connections this process no longer owns.
     */
    public static function reset(): void
    {
        foreach (self::$pools as $pool) {
            $pool->abandon();
        }
        self::forgetAll();
    }

    // -------------------------------------------------------------------------
    // Internals
    // -------------------------------------------------------------------------

    private static function forgetAll(): void
    {
        self::$pools   = [];
        self::$static  = [];
        self::$configs = [];
    }

    private static function key(string $configClass): string
    {
        return base64_encode($configClass);
    }

    private static function inCoroutine(): bool
    {
        return extension_loaded('swoole') && \Swoole\Coroutine::getCid() > 0;
    }

    /**
     * Non-coroutine path: one {@see SingleConnection} per config for the process
     * lifetime. For a short FPM request the connection is freshly opened and the
     * liveness checks are near no-ops; for a long-running CLI process they are what
     * keeps the connection healthy across a Redis restart.
     */
    private static function staticStore(string $configClass): Redis
    {
        $key = self::key($configClass);
        if (!isset(self::$static[$key])) {
            self::$static[$key] = new SingleConnection(
                new RedisConnectionFactory($configClass, self::logger()),
                self::policy($configClass),
            );
            self::logger()->debug("single connection registered: {$configClass}");
        }

        try {
            /** @var RedisConfigInterface $config */
            $config = self::$static[$key]->get();
        } catch (PoolException $e) {
            // The same failure must look the same in both runtimes: the coroutine path
            // wraps, so this one does too, or a caller would have to catch a different
            // exception depending on whether Swoole happens to be loaded.
            self::logger()->error("connect failed: {$configClass} — {$e->getMessage()}");
            throw new RedisPoolException(
                "RedisPool: no connection for [{$configClass}] — {$e->getMessage()}",
                previous: $e,
            );
        }

        return $config->connection();
    }

    /**
     * Swoole path: borrow once per coroutine, cache the entry in the coroutine context,
     * and return it via `defer` when the coroutine ends — on normal exit and on an
     * exception alike.
     */
    private static function coroutineStore(string $configClass): Redis
    {
        $ctxKey = 'winter_redis_' . self::key($configClass);
        $ctx    = \Swoole\Coroutine::getContext();

        if (!isset($ctx[$ctxKey])) {
            $pool = self::pool($configClass);
            $cid  = \Swoole\Coroutine::getCid();

            try {
                $entry = $pool->borrow();
            } catch (PoolException $e) {
                self::logger()->error("cid={$cid} borrow failed: {$configClass} — {$e->getMessage()}");
                throw new RedisPoolException(
                    "RedisPool: no connection for [{$configClass}] — {$e->getMessage()}",
                    previous: $e,
                );
            }

            $held         = new BorrowedConnection($entry);
            $ctx[$ctxKey] = $held;
            self::logger()->debug("cid={$cid} borrow: {$configClass}");

            // $held is captured directly rather than read back from the context, which
            // may already be tearing down, and it carries the verdict reportFailure()
            // may have left on it.
            \Swoole\Coroutine::defer(static function () use ($pool, $held, $cid, $configClass): void {
                if ($held->dead) {
                    self::logger()->warning("cid={$cid} evict: {$configClass}");
                    $pool->evict($held->entry);
                    return;
                }
                self::logger()->debug("cid={$cid} release: {$configClass}");
                $pool->release($held->entry);
            });
        }

        /** @var BorrowedConnection $held */
        $held = $ctx[$ctxKey];
        /** @var RedisConfigInterface $config */
        $config = $held->entry->resource;

        return $config->connection();
    }

    /** Returns (and lazily creates) the pool for a config class. */
    private static function pool(string $configClass): ConnectionPool
    {
        $key = self::key($configClass);
        if (!isset(self::$pools[$key])) {
            $policy = self::policy($configClass);
            self::$pools[$key] = new ConnectionPool(
                new RedisConnectionFactory($configClass, self::logger()),
                $policy,
            );
            self::logger()->debug("pool created: {$configClass} maxConnections={$policy->maximumPoolSize}");
        }

        return self::$pools[$key];
    }

    /**
     * Builds the policy from the config when it is pool-aware, otherwise from the
     * package defaults.
     */
    private static function policy(string $configClass): PoolPolicy
    {
        $config = self::config($configClass);

        return $config instanceof RedisPoolConfigInterface
            ? new PoolPolicy(
                maximumPoolSize: $config->getPoolMaxConnections(),
                connectionTimeout: $config->getPoolWaitTimeout(),
                keepaliveTime: $config->getKeepaliveTime(),
                idleTimeout: $config->getIdleTimeout(),
                minimumIdle: $config->getMinimumIdle(),
            )
            : new PoolPolicy(maximumPoolSize: self::DEFAULT_POOL_SIZE, connectionTimeout: 3.0);
    }
}
