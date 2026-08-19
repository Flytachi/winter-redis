<?php

declare(strict_types=1);

namespace Flytachi\Winter\Redis\Config\Common;

use Flytachi\Winter\Redis\RedisPool;
use Redis;

/**
 * Gives a config class the shorthand `MainRedisConfig::instance()` for the pooled
 * client, so infrastructure code can reach Redis without holding a store.
 *
 * Application code should prefer a {@see \Flytachi\Winter\Redis\Store\RedisStore},
 * which adds the key prefix and keeps raw commands out of business logic.
 *
 * The returned client is valid **for the current unit of work only** — under Swoole it
 * is returned to the pool when the coroutine ends. Storing it in a property of a
 * long-lived object hands one socket to every later request, which is the exact
 * failure a pool exists to prevent.
 */
trait EntityCallRedisTrait
{
    final public static function instance(): Redis
    {
        return RedisPool::store(static::class);
    }
}
