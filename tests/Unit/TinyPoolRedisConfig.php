<?php

declare(strict_types=1);

namespace Flytachi\Winter\Redis\Tests\Unit;

use Flytachi\Winter\Redis\Pool\RedisPoolConfigInterface;
use Flytachi\Winter\Redis\Pool\RedisPoolTrait;

/** The same endpoint, sized down so pool exhaustion is reachable in a test. */
class TinyPoolRedisConfig extends TestRedisConfig implements RedisPoolConfigInterface
{
    use RedisPoolTrait;

    public int $poolMaxConnections = 1;
    public float $poolWaitTimeout = 0.2;
}
