<?php

declare(strict_types=1);

namespace Flytachi\Winter\Redis\Tests\Unit;

use Flytachi\Winter\Redis\RedisPool;
use Flytachi\Winter\Redis\RedisPoolException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * What the pool exists for: surviving a Redis that goes away and comes back.
 *
 * These tests run against their own disposable server so it can be stopped mid-test —
 * the one scenario a shared Redis cannot provide, and the one that a mock cannot
 * honestly simulate.
 */
#[CoversClass(RedisPool::class)]
final class RedisOutageTest extends TestCase
{
    private const int PORT = 6398;

    private ?string $pidFile = null;

    protected function setUp(): void
    {
        if (trim((string) shell_exec('command -v redis-server')) === '') {
            self::markTestSkipped('redis-server is not on PATH.');
        }

        putenv('REDIS_OUTAGE_PORT=' . self::PORT);
        RedisPool::shutdown();
        $this->startServer();
    }

    protected function tearDown(): void
    {
        RedisPool::shutdown();
        $this->stopServer();
        putenv('REDIS_OUTAGE_PORT');
    }

    public function testReportFailureEvictsWhenTheServerIsGone(): void
    {
        RedisPool::store(OutageRedisConfig::class);
        $this->stopServer();

        $evicted = RedisPool::reportFailure(OutageRedisConfig::class, new RuntimeException('read error'));

        self::assertTrue($evicted, 'a probe that cannot reach the server retires the connection');
    }

    public function testTheConnectionHealsAfterTheServerComesBack(): void
    {
        $before = RedisPool::store(OutageRedisConfig::class);
        self::assertTrue($before->set('before', 'outage'));

        $this->stopServer();
        RedisPool::reportFailure(OutageRedisConfig::class, new RuntimeException('read error'));

        try {
            RedisPool::store(OutageRedisConfig::class)->ping();
            self::fail('a command against a stopped server should not succeed');
        } catch (RedisPoolException | \RedisException) {
            // expected while the server is down
        }

        $this->startServer();

        $after = RedisPool::store(OutageRedisConfig::class);
        self::assertTrue($after->set('after', 'recovery'), 'the process healed without a restart');
    }

    // -------------------------------------------------------------------------

    private function startServer(): void
    {
        $this->pidFile = sys_get_temp_dir() . '/winter-redis-test-' . self::PORT . '.pid';
        shell_exec(sprintf(
            'redis-server --port %d --save "" --appendonly no --daemonize yes --pidfile %s 2>/dev/null',
            self::PORT,
            escapeshellarg($this->pidFile),
        ));

        for ($i = 0; $i < 100; $i++) {
            $socket = @fsockopen('127.0.0.1', self::PORT, $errno, $error, 0.1);
            if ($socket !== false) {
                fclose($socket);
                return;
            }
            usleep(20_000);
        }

        self::markTestSkipped('Could not start a disposable redis-server on port ' . self::PORT . '.');
    }

    private function stopServer(): void
    {
        shell_exec(sprintf('redis-cli -p %d shutdown nosave 2>/dev/null', self::PORT));

        for ($i = 0; $i < 100; $i++) {
            $socket = @fsockopen('127.0.0.1', self::PORT, $errno, $error, 0.1);
            if ($socket === false) {
                return;
            }
            fclose($socket);
            usleep(20_000);
        }
    }
}
