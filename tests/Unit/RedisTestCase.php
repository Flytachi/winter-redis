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

    /**
     * `Coroutine\run()` that returns.
     *
     * A pool's housekeeper is a repeating `Timer::tick`, and a live timer keeps the
     * scheduler from ever finishing — the test would hang rather than fail. The pools
     * cannot simply be shut down at the end of `$body` either: a body that spawns
     * coroutines returns before they do, and they would lose their pool mid-use. So
     * this waits for every coroutine the body started, then shuts the pools down — what
     * `workerExit` does for a real worker.
     */
    protected static function runCoroutines(callable $body): void
    {
        \Swoole\Coroutine\run(static function () use ($body): void {
            try {
                $body();
            } finally {
                while (\Swoole\Coroutine::stats()['coroutine_num'] > 1) {
                    \Swoole\Coroutine::sleep(0.001);
                }
                RedisPool::shutdown();
            }
        });
    }

    protected function flushTestDatabase(): void
    {
        (new RedisCall(host: self::host(), port: self::port(), databaseIndex: self::db()))
            ->connection()
            ->flushDB();
    }
}
