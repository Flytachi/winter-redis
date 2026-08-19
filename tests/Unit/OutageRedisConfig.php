<?php

declare(strict_types=1);

namespace Flytachi\Winter\Redis\Tests\Unit;

use Flytachi\Winter\Redis\Config\RedisConfig;

/** Points at the disposable server {@see RedisOutageTest} starts and stops. */
class OutageRedisConfig extends RedisConfig
{
    public function setUp(): void
    {
        $this->host    = '127.0.0.1';
        $this->port    = (int) (getenv('REDIS_OUTAGE_PORT') ?: 6398);
        $this->timeout = 0.3;
    }
}
