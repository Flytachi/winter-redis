<?php

declare(strict_types=1);

namespace Flytachi\Winter\Redis\Tests\Unit;

use Flytachi\Winter\Redis\Config\Call\RedisCall;
use Flytachi\Winter\Redis\Pool\RedisConnectionFactory;
use Flytachi\Winter\Redis\RedisPool;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use Redis;
use Swoole\Coroutine;

/**
 * A connection must go back to the pool in the state it was handed out in. `MULTI`,
 * `pipeline()` and `SELECT` live on the connection, so whatever a borrower left behind
 * — an exception between `multi()` and `exec()` above all — is inherited by the next
 * one: its reads come back as queued `Redis` objects instead of values, its writes never
 * reach the server, and a later `exec()` publishes the abandoned borrower's writes.
 */
#[CoversClass(RedisConnectionFactory::class)]
final class RedisConnectionResetTest extends RedisTestCase
{
    /** What the server really holds, read over a client outside the pool. */
    private static function server(string $key, ?int $db = null): mixed
    {
        return new RedisCall(host: self::host(), port: self::port(), databaseIndex: $db ?? self::db())
            ->connection()
            ->get($key);
    }

    public function testACleanConnectionIsKeptWithoutAWord(): void
    {
        $log = new RecordingLogger();
        $factory = new RedisConnectionFactory(TestRedisConfig::class, $log);
        $config = $factory->create();

        self::assertTrue($factory->reset($config));
        self::assertSame([], $log->messagesAt('error'));
        $factory->close($config);
    }

    public function testAnAbandonedMultiIsDiscardedAndReported(): void
    {
        $log = new RecordingLogger();
        $factory = new RedisConnectionFactory(TestRedisConfig::class, $log);
        $config = $factory->create();
        $redis = $config->connection();
        $redis->multi();
        $redis->set('winter:abandoned', 'A');

        self::assertTrue($factory->reset($config), 'discarded cleanly — the connection is reusable');
        self::assertSame(Redis::ATOMIC, $redis->getMode());
        $redis->exec();     // must not publish the abandoned write
        self::assertFalse(self::server('winter:abandoned'));

        $errors = $log->messagesAt('error');
        self::assertCount(1, $errors);
        self::assertStringContainsString(TestRedisConfig::class, $errors[0]);
        self::assertStringContainsString('MULTI', $errors[0]);
        $factory->close($config);
    }

    public function testAnAbandonedPipelineIsDiscarded(): void
    {
        $log = new RecordingLogger();
        $factory = new RedisConnectionFactory(TestRedisConfig::class, $log);
        $config = $factory->create();
        $redis = $config->connection();
        $redis->pipeline();
        $redis->set('winter:abandoned', 'A');

        self::assertTrue($factory->reset($config));
        self::assertSame(Redis::ATOMIC, $redis->getMode());
        self::assertFalse($redis->get('winter:abandoned'), 'reads answer with values again, not queued objects');
        self::assertStringContainsString('PIPELINE', $log->messagesAt('error')[0]);
        $factory->close($config);
    }

    public function testASwitchedDatabaseIsSwitchedBack(): void
    {
        $log = new RecordingLogger();
        $factory = new RedisConnectionFactory(TestRedisConfig::class, $log);
        $config = $factory->create();
        $redis = $config->connection();
        $other = self::db() === 9 ? 8 : 9;
        $redis->select($other);

        self::assertTrue($factory->reset($config));
        self::assertSame(self::db(), $redis->getDbNum());
        self::assertStringContainsString("SELECT {$other}", $log->messagesAt('error')[0]);
        $factory->close($config);
    }

    /** The scenario end to end: requests are coroutines sharing one pooled connection. */
    #[RequiresPhpExtension('swoole')]
    public function testTheNextRequestDoesNotInheritAnAbandonedMulti(): void
    {
        $log = new RecordingLogger();
        $seen = [];

        self::runCoroutines(function () use ($log, &$seen): void {
            RedisPool::setLogger($log);
            // Sequential coroutines, so each is handed the previous one's connection.
            Coroutine::create(function (): void {
                $redis = RedisPool::store(TestRedisConfig::class);
                $redis->multi();
                $redis->set('winter:a', 'A');
                // ... and the request fails before exec()
            });
            Coroutine::sleep(0.02);
            Coroutine::create(function () use (&$seen): void {
                $redis = RedisPool::store(TestRedisConfig::class);
                $seen['mode'] = $redis->getMode();
                $redis->set('winter:b', 'B');
                $seen['get'] = $redis->get('winter:b');
            });
            Coroutine::sleep(0.02);
            Coroutine::create(function (): void {
                $redis = RedisPool::store(TestRedisConfig::class);
                $redis->multi();
                $redis->set('winter:c', 'C');
                $redis->exec();
            });
            Coroutine::sleep(0.02);
            $seen['stats'] = RedisPool::stats()[TestRedisConfig::class];
        });

        self::assertSame(Redis::ATOMIC, $seen['mode']);
        self::assertSame('B', $seen['get'], 'a value, not a queued Redis object');
        self::assertFalse(self::server('winter:a'), 'the abandoned write is never published');
        self::assertSame('C', self::server('winter:c'));
        self::assertSame(1, $seen['stats']['total'], 'the discarded connection stays in service');
        self::assertCount(1, $log->messagesAt('error'));
    }
}
