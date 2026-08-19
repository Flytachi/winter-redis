<?php

declare(strict_types=1);

namespace Flytachi\Winter\Redis\Tests\Unit;

use Flytachi\Winter\Redis\Store\RedisStore;

/** A prefixed store — the ordinary case. */
class SessionStore extends RedisStore
{
    protected string $redisConfigClassName = TestRedisConfig::class;
    protected string $prefix = 'session:';
}
