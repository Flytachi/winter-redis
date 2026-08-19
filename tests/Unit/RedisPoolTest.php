<?php

declare(strict_types=1);

namespace Flytachi\Winter\Redis\Tests\Unit;

use Flytachi\Winter\Redis\Config\Common\RedisConfigInterface;
use Flytachi\Winter\Redis\RedisPool;
use PHPUnit\Framework\Attributes\CoversClass;
use RuntimeException;

/**
 * The non-coroutine path: one self-maintaining connection per config for the lifetime
 * of the process.
 */
#[CoversClass(RedisPool::class)]
final class RedisPoolTest extends RedisTestCase
{
    public function testConfigIsInstantiatedOnceAndSetUp(): void
    {
        $first  = RedisPool::config(TestRedisConfig::class);
        $second = RedisPool::config(TestRedisConfig::class);

        self::assertSame($first, $second);
        self::assertSame(self::port(), $first->getPort(), 'setUp() ran');
    }

    public function testStoreReturnsTheSameClientWithinTheProcess(): void
    {
        $first  = RedisPool::store(TestRedisConfig::class);
        $second = RedisPool::store(TestRedisConfig::class);

        self::assertSame($first, $second);
        self::assertTrue($first->set('winter:pool', 'value'));
        self::assertSame('value', $second->get('winter:pool'));
    }

    public function testDifferentConfigsGetDifferentConnections(): void
    {
        $a = RedisPool::store(TestRedisConfig::class);
        $b = RedisPool::store(TinyPoolRedisConfig::class);

        self::assertNotSame($a, $b);
    }

    public function testTheRegistryConfigIsNotThePooledOne(): void
    {
        $registered = RedisPool::config(TestRedisConfig::class);
        $connection = RedisPool::store(TestRedisConfig::class);

        self::assertNotSame(
            $registered->connection(),
            $connection,
            'the registry instance is for reading settings; the pooled one owns the socket',
        );
    }

    public function testShowConfigsIsKeyedByClassName(): void
    {
        RedisPool::store(TestRedisConfig::class);

        $configs = RedisPool::showConfigs();

        self::assertArrayHasKey(TestRedisConfig::class, $configs);
        self::assertInstanceOf(RedisConfigInterface::class, $configs[TestRedisConfig::class]);
    }

    public function testStatsReportsNothingWithoutCoroutinePools(): void
    {
        RedisPool::store(TestRedisConfig::class);

        self::assertSame([], RedisPool::stats(), 'the non-coroutine path holds no pool');
    }

    public function testShutdownClosesEverythingAndReopensLazily(): void
    {
        $before = RedisPool::store(TestRedisConfig::class);

        RedisPool::shutdown();

        self::assertSame([], RedisPool::showConfigs(), 'shutdown() forgets the registry too');
        self::assertNotSame($before, RedisPool::store(TestRedisConfig::class), 'and reopens lazily');
    }

    public function testReportFailureKeepsAConnectionThatIsStillAlive(): void
    {
        RedisPool::store(TestRedisConfig::class);

        $evicted = RedisPool::reportFailure(TestRedisConfig::class, new RuntimeException('WRONGTYPE'));

        self::assertFalse($evicted, 'a failed command is not a dead connection');
    }

    public function testAClosedConnectionIsNotEvictedBecausePhpredisReopensIt(): void
    {
        $connection = RedisPool::store(TestRedisConfig::class);
        $connection->close();

        $evicted = RedisPool::reportFailure(TestRedisConfig::class, new RuntimeException('read error'));

        // Measured, not assumed: phpredis 6.x reconnects transparently on the next
        // command after a close — and after a server-side `CLIENT KILL` — restoring the
        // selected database. So a dropped socket is not by itself a dead connection, and
        // evicting on the error message alone would throw away a healthy one. Eviction
        // is proven against a real outage in RedisOutageTest.
        self::assertFalse($evicted);
        self::assertTrue($connection->ping());
    }

    public function testReportFailureIsANoOpForAnUnknownConfig(): void
    {
        self::assertFalse(RedisPool::reportFailure(TestRedisConfig::class, new RuntimeException('x')));
    }
}
