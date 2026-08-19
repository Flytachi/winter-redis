<?php

declare(strict_types=1);

namespace Flytachi\Winter\Redis\Config\Call;

use Flytachi\Winter\Redis\Config\Common\BaseRedisConfig;
use Redis;

/**
 * An inline config for one-off work — a script, a migration, a test — where declaring
 * a config class would be ceremony.
 *
 * ```php
 * $redis = (new RedisCall(host: '127.0.0.1', databaseIndex: 3))->connection();
 * ```
 *
 * Every instance opens its **own** connection and closes it when the object is
 * released. That is the wrong shape for an application serving requests: there, declare
 * a {@see \Flytachi\Winter\Redis\Config\RedisConfig} and let the pool own the sockets.
 *
 * @link https://winterframe.net/packages/redis/configuration Configuration
 */
final class RedisCall extends BaseRedisConfig
{
    public function __construct(
        string $host = 'localhost',
        int $port = 6379,
        string $password = '',
        int $databaseIndex = 0,
        float $timeout = 1.5,
        float $readTimeout = 2.0,
        int $serializer = Redis::SERIALIZER_NONE,
    ) {
        $this->host          = $host;
        $this->port          = $port;
        $this->password      = $password;
        $this->databaseIndex = $databaseIndex;
        $this->timeout       = $timeout;
        $this->readTimeout   = $readTimeout;
        $this->serializer    = $serializer;
    }

    public function setUp(): void
    {
        // Credentials arrive through the constructor.
    }
}
