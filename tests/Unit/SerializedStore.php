<?php

declare(strict_types=1);

namespace Flytachi\Winter\Redis\Tests\Unit;

use Flytachi\Winter\Redis\Store\RedisStore;

/** A store on a serializing endpoint, for values that are not strings. */
class SerializedStore extends RedisStore
{
    protected string $redisConfigClassName = SerializingRedisConfig::class;
    protected string $prefix = 'serialized:';
}
