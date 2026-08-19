<?php

declare(strict_types=1);

namespace Flytachi\Winter\Redis\Tests\Unit;

use Redis;

/** The same endpoint with PHP serialization, for the PSR-16 adapter. */
class SerializingRedisConfig extends TestRedisConfig
{
    public function setUp(): void
    {
        parent::setUp();
        $this->serializer = Redis::SERIALIZER_PHP;
    }
}
