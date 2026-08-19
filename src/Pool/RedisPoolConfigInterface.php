<?php

declare(strict_types=1);

namespace Flytachi\Winter\Redis\Pool;

/**
 * Marks a config as pool-aware, so it sizes its own pool instead of taking the default.
 *
 * Mix in {@see RedisPoolTrait} to get every method with a sensible default and override
 * only what you need:
 *
 * ```php
 * class MainRedisConfig extends RedisConfig implements RedisPoolConfigInterface
 * {
 *     use RedisPoolTrait;
 *
 *     public int   $poolMaxConnections = 20;
 *     public float $poolWaitTimeout    = 5.0;
 *
 *     public function setUp(): void { ... }
 * }
 * ```
 *
 * @link https://winterframe.net/docs/redis-pooling Connection pool: sizing
 */
interface RedisPoolConfigInterface
{
    /** Hard ceiling on connections this worker opens for the config. */
    public function getPoolMaxConnections(): int;

    /** Seconds a borrower waits for a free connection before the pool gives up. */
    public function getPoolWaitTimeout(): float;

    /**
     * Seconds after which the background housekeeper probes an idle connection,
     * retiring dead ones before a borrow meets them. `0` = disabled. Swoole only.
     */
    public function getKeepaliveTime(): float;

    /**
     * Seconds after which the housekeeper closes an idle connection, shrinking the pool
     * down to {@see getMinimumIdle()}. `0` = never shrink. Swoole only.
     */
    public function getIdleTimeout(): float;

    /**
     * Warm connection floor the housekeeper maintains. `0` = fully lazy. Swoole only.
     */
    public function getMinimumIdle(): int;
}
