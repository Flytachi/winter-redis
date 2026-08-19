<?php

declare(strict_types=1);

namespace Flytachi\Winter\Redis\Tests\Unit;

use Flytachi\Winter\Redis\Store\RedisStore;

/** A second store on the same database, to prove the prefix keeps them apart. */
class QueueStore extends RedisStore
{
    protected string $redisConfigClassName = TestRedisConfig::class;
    protected string $prefix = 'queue:';
}
