<?php

declare(strict_types=1);

namespace Flytachi\Winter\Redis\Tests\Unit;

use Flytachi\Winter\Redis\Config\Call\RedisCall;
use Flytachi\Winter\Redis\RedisPool;
use PHPUnit\Framework\TestCase;

/**
 * Base for tests that talk to a real Redis.
 *
 * The pool's interesting behaviour is protocol behaviour — a socket that dies, a
 * connection handed to a second coroutine, a `SCAN` that returns an empty slice — and
 * none of it is observable against a mock. Tests therefore run against a live server
 * and skip when there is none, rather than pretending with a double.
 *
 * Point them elsewhere with `REDIS_TEST_HOST` / `REDIS_TEST_PORT` / `REDIS_TEST_DB`.
 */
abstract class RedisTestCase extends TestCase
{
    protected function setUp(): void
    {
        if (!(new RedisCall(host: self::host(), port: self::port(), databaseIndex: self::db()))->ping()) {
            self::markTestSkipped(sprintf(
                'No Redis at %s:%d — start one or set REDIS_TEST_HOST/REDIS_TEST_PORT.',
                self::host(),
                self::port(),
            ));
        }

        RedisPool::shutdown();
        $this->flushTestDatabase();
    }

    protected function tearDown(): void
    {
        RedisPool::shutdown();
    }

    protected static function host(): string
    {
        return getenv('REDIS_TEST_HOST') ?: '127.0.0.1';
    }

    protected static function port(): int
    {
        return (int) (getenv('REDIS_TEST_PORT') ?: 6379);
    }

    protected static function db(): int
    {
        return (int) (getenv('REDIS_TEST_DB') ?: 0);
    }

    protected function flushTestDatabase(): void
    {
        (new RedisCall(host: self::host(), port: self::port(), databaseIndex: self::db()))
            ->connection()
            ->flushDB();
    }
}
