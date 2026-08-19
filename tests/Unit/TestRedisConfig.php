<?php

declare(strict_types=1);

namespace Flytachi\Winter\Redis\Tests\Unit;

use Flytachi\Winter\Redis\Config\RedisConfig;

/** The endpoint every test uses. */
class TestRedisConfig extends RedisConfig
{
    public function setUp(): void
    {
        $this->host          = getenv('REDIS_TEST_HOST') ?: '127.0.0.1';
        $this->port          = (int) (getenv('REDIS_TEST_PORT') ?: 6379);
        $this->databaseIndex = (int) (getenv('REDIS_TEST_DB') ?: 0);
    }
}
