<?php

declare(strict_types=1);

namespace Flytachi\Winter\Redis\Tests\Unit;

use Flytachi\Winter\Redis\Store\RedisStore;

/** A store on the single-connection pool, for proving consumers do not occupy it. */
class TinyPoolStore extends RedisStore
{
    protected string $redisConfigClassName = TinyPoolRedisConfig::class;
    protected string $prefix = 'tiny:';
}
