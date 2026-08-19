<?php

declare(strict_types=1);

namespace Flytachi\Winter\Redis\Config;

use Flytachi\Winter\Redis\Config\Common\BaseRedisConfig;
use Flytachi\Winter\Redis\Config\Common\EntityCallRedisTrait;

/**
 * The class an application extends to declare a Redis endpoint.
 *
 * ```php
 * class MainRedisConfig extends RedisConfig
 * {
 *     public function setUp(): void
 *     {
 *         $this->host     = getenv('REDIS_HOST') ?: 'localhost';
 *         $this->port     = (int) (getenv('REDIS_PORT') ?: 6379);
 *         $this->password = getenv('REDIS_PASS') ?: '';
 *     }
 * }
 * ```
 *
 * One config class means one database. To use a second database, declare a second
 * config class with its own `$databaseIndex` — never switch databases at runtime, or
 * the choice leaks to whoever borrows the connection next.
 *
 * @link https://winterframe.net/docs/redis-configuration Configuration
 */
abstract class RedisConfig extends BaseRedisConfig
{
    use EntityCallRedisTrait;
}
