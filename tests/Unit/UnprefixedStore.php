<?php

declare(strict_types=1);

namespace Flytachi\Winter\Redis\Tests\Unit;

use Flytachi\Winter\Redis\Store\RedisStore;

/** No prefix — allowed for reads and writes, refused for flush(). */
class UnprefixedStore extends RedisStore
{
    protected string $redisConfigClassName = TestRedisConfig::class;
}
