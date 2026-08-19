<?php

declare(strict_types=1);

namespace Flytachi\Winter\Redis\Tests\Unit;

use Flytachi\Winter\Redis\Store\RedisStore;

class LegacyStore extends RedisStore
{
    protected string $redisConfigClassName = LegacyRedisConfig::class;
    protected string $prefix = 'legacy:';
}
