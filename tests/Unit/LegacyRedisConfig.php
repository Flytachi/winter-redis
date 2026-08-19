<?php

declare(strict_types=1);

namespace Flytachi\Winter\Redis\Tests\Unit;

use Flytachi\Winter\Redis\Config\RedisConfig;

/** Points at the server {@see RedisHashFeatureTest} starts with the new commands taken away. */
class LegacyRedisConfig extends RedisConfig
{
    public function setUp(): void
    {
        $this->host    = '127.0.0.1';
        $this->port    = (int) (getenv('REDIS_LEGACY_PORT') ?: 6397);
        $this->timeout = 0.3;
    }
}
