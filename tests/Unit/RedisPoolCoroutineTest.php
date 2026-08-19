<?php

declare(strict_types=1);

namespace Flytachi\Winter\Redis\Tests\Unit;

use Flytachi\Winter\Redis\RedisPool;
use Flytachi\Winter\Redis\RedisPoolException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use Swoole\Coroutine;

/**
 * The coroutine path — where a pool is actually a pool.
 *
 * Concurrency is exercised with real coroutines rather than doubles: what these tests
 * check is the interleaving itself, and a double would only replay the assumptions the
 * implementation already makes.
 */
#[CoversClass(RedisPool::class)]
#[RequiresPhpExtension('swoole')]
final class RedisPoolCoroutineTest extends RedisTestCase
{
    public function testEachCoroutineGetsItsOwnConnection(): void
    {
        $ids = [];

        Coroutine\run(function () use (&$ids): void {
            foreach (range(1, 3) as $n) {
                Coroutine::create(function () use (&$ids): void {
                    $redis = RedisPool::store(TestRedisConfig::class);
                    $redis->ping();
                    $ids[] = spl_object_id($redis);
                    Coroutine::sleep(0.02);          // hold it while the others run
                });
            }
        });

        self::assertCount(3, $ids);
        self::assertCount(3, array_unique($ids), 'three concurrent coroutines, three sockets');
    }

    public function testTheSameCoroutineReusesOneConnection(): void
    {
        $ids = [];

        Coroutine\run(function () use (&$ids): void {
            $ids[] = spl_object_id(RedisPool::store(TestRedisConfig::class));
            $ids[] = spl_object_id(RedisPool::store(TestRedisConfig::class));
        });

        self::assertCount(1, array_unique($ids), 'borrowed once, cached for the unit of work');
    }

    public function testTheConnectionIsReturnedWhenTheCoroutineEnds(): void
    {
        $duringRun = [];

        Coroutine\run(function () use (&$duringRun): void {
            Coroutine::create(function () use (&$duringRun): void {
                RedisPool::store(TestRedisConfig::class)->ping();
                $duringRun = RedisPool::stats()[TestRedisConfig::class];
                Coroutine::sleep(0.02);
            });
            Coroutine::sleep(0.05);
        });

        self::assertSame(1, $duringRun['active'], 'held while the coroutine is alive');

        $afterRun = RedisPool::stats()[TestRedisConfig::class];
        self::assertSame(0, $afterRun['active'], 'released by defer, with no manual call');
        self::assertSame(1, $afterRun['idle']);
    }

    public function testAReusedConnectionCarriesNoStateFromThePreviousBorrower(): void
    {
        $seen = [];

        Coroutine\run(function () use (&$seen): void {
            // Sequential coroutines, so the second is handed the first one's connection.
            Coroutine::create(function (): void {
                RedisPool::store(TestRedisConfig::class)->set('winter:leak', 'first');
            });
            Coroutine::sleep(0.02);
            Coroutine::create(function () use (&$seen): void {
                $redis  = RedisPool::store(TestRedisConfig::class);
                $seen[] = $redis->getDbNum();
                $seen[] = $redis->get('winter:leak');
            });
            Coroutine::sleep(0.02);
        });

        self::assertSame([self::db(), 'first'], $seen, 'same database, same data — no accidental SELECT');
    }

    public function testTheCeilingIsRespectedAndExhaustionIsReported(): void
    {
        $error = null;

        Coroutine\run(function () use (&$error): void {
            Coroutine::create(function (): void {
                RedisPool::store(TinyPoolRedisConfig::class)->ping();
                Coroutine::sleep(0.5);               // hold the only connection
            });
            Coroutine::sleep(0.02);
            Coroutine::create(function () use (&$error): void {
                try {
                    RedisPool::store(TinyPoolRedisConfig::class);
                } catch (RedisPoolException $e) {
                    $error = $e;
                }
            });
        });

        self::assertInstanceOf(RedisPoolException::class, $error);
        self::assertStringContainsString(TinyPoolRedisConfig::class, $error->getMessage());
        self::assertStringContainsString('no free connection', $error->getMessage());
    }

    public function testAWaitingCoroutineIsServedWhenTheHolderFinishes(): void
    {
        $served = false;

        Coroutine\run(function () use (&$served): void {
            Coroutine::create(function (): void {
                RedisPool::store(TinyPoolRedisConfig::class)->ping();
                Coroutine::sleep(0.05);              // released well inside the 0.2s wait
            });
            Coroutine::sleep(0.01);
            Coroutine::create(function () use (&$served): void {
                $served = RedisPool::store(TinyPoolRedisConfig::class)->ping();
            });
        });

        self::assertTrue($served, 'a full pool queues borrowers, it does not fail them outright');
    }

    public function testStatsReportsEachConfigSeparately(): void
    {
        Coroutine\run(function (): void {
            RedisPool::store(TestRedisConfig::class)->ping();
            RedisPool::store(TinyPoolRedisConfig::class)->ping();
        });

        $stats = RedisPool::stats();

        self::assertArrayHasKey(TestRedisConfig::class, $stats);
        self::assertArrayHasKey(TinyPoolRedisConfig::class, $stats);
        self::assertSame(10, $stats[TestRedisConfig::class]['maximum'], 'package default');
        self::assertSame(1, $stats[TinyPoolRedisConfig::class]['maximum'], 'from the config');
    }
}
