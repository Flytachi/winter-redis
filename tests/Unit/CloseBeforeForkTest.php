<?php

declare(strict_types=1);

namespace Flytachi\Winter\Redis\Tests\Unit;

use Flytachi\Winter\Redis\RedisPool;
use Flytachi\Winter\Redis\RedisPoolException;
use PHPUnit\Framework\Attributes\CoversClass;
use Redis;

/**
 * The parent closes its own connection before a fork, so a child that goes on to use
 * Redis opens its own session instead of talking into the parent's. A connection with
 * queued `MULTI`/`pipeline()` commands cannot be closed without dropping them, so the
 * fork is refused instead.
 */
#[CoversClass(RedisPool::class)]
final class CloseBeforeForkTest extends RedisTestCase
{
    public function testAnIdleConnectionIsClosedAndAChildGetsASessionOfItsOwn(): void
    {
        $parent = (int) RedisPool::store(TestRedisConfig::class)->client('id');

        RedisPool::closeBeforeFork();
        $pid = pcntl_fork();
        if ($pid === 0) {
            $child = (int) RedisPool::store(TestRedisConfig::class)->client('id');
            posix_kill(getmypid(), $child !== $parent ? SIGUSR1 : SIGUSR2);
            sleep(5);
        }
        pcntl_waitpid($pid, $status);

        self::assertTrue(pcntl_wifsignaled($status));
        self::assertSame(SIGUSR1, pcntl_wtermsig($status), 'the child must not share the parent\'s session');
        self::assertNotSame($parent, (int) RedisPool::store(TestRedisConfig::class)->client('id'), 'the parent reopened');
    }

    public function testAConnectionInMultiRefusesTheFork(): void
    {
        $redis = RedisPool::store(TestRedisConfig::class);
        $redis->multi();

        try {
            RedisPool::closeBeforeFork();
            self::fail('forking with queued MULTI commands must be refused');
        } catch (RedisPoolException $e) {
            self::assertStringContainsString('MULTI', $e->getMessage());
        }

        self::assertSame(Redis::MULTI, $redis->getMode(), 'the refusal leaves the queued commands alone');
        $redis->discard();
    }
}
