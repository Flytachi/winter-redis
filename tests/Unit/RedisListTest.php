<?php

declare(strict_types=1);

namespace Flytachi\Winter\Redis\Tests\Unit;

use Flytachi\Winter\Redis\RedisPool;
use Flytachi\Winter\Redis\Store\RedisList;
use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use Swoole\Coroutine;

#[CoversClass(RedisList::class)]
final class RedisListTest extends RedisTestCase
{
    private SessionStore $store;

    protected function setUp(): void
    {
        parent::setUp();
        $this->store = new SessionStore();
    }

    public function testTheHandleCarriesThePrefixedKey(): void
    {
        self::assertSame('session:jobs', $this->store->list('jobs')->name());
    }

    public function testPushAndPopAreFirstInFirstOut(): void
    {
        $jobs = $this->store->list('jobs');

        self::assertSame(1, $jobs->push('first'));
        self::assertSame(2, $jobs->push('second'));

        self::assertSame('first', $jobs->pop());
        self::assertSame('second', $jobs->pop());
        self::assertNull($jobs->pop(), 'an empty list gives null, not false');
    }

    public function testBothEndsAreReachable(): void
    {
        $jobs = $this->store->list('jobs');
        $jobs->push('b');
        $jobs->pushFront('a');
        $jobs->push('c');

        self::assertSame(['a', 'b', 'c'], $jobs->range());
        self::assertSame('c', $jobs->popBack());
        self::assertSame('a', $jobs->pop());
    }

    public function testReadingWithoutTakingAnythingOut(): void
    {
        $events = $this->store->list('events');
        foreach (['a', 'b', 'c', 'd'] as $value) {
            $events->push($value);
        }

        self::assertSame(4, $events->count());
        self::assertSame(['a', 'b'], $events->range(0, 1));
        self::assertSame(['c', 'd'], $events->range(-2, -1));
        self::assertSame('a', $events->at(0));
        self::assertSame('d', $events->at(-1));
        self::assertNull($events->at(99));
        self::assertSame(4, $events->count(), 'nothing was consumed');
    }

    public function testCapKeepsTheNewestElements(): void
    {
        $events = $this->store->list('events');
        foreach (range(1, 10) as $n) {
            $length = $events->push("e{$n}", cap: 3);
        }

        self::assertSame(3, $length);
        self::assertSame(3, $events->count());
        self::assertSame(['e8', 'e9', 'e10'], $events->range(), 'the oldest fall off the head');
    }

    public function testCapOnPushFrontKeepsTheNewestToo(): void
    {
        $events = $this->store->list('events');
        foreach (range(1, 10) as $n) {
            $events->pushFront("e{$n}", cap: 3);
        }

        self::assertSame(['e10', 'e9', 'e8'], $events->range());
    }

    public function testTheListIsNeverLongerThanItsCap(): void
    {
        $events = $this->store->list('events');
        $raw    = RedisPool::store(TestRedisConfig::class);

        foreach (range(1, 50) as $n) {
            $events->push("e{$n}", cap: 5);
            self::assertLessThanOrEqual(5, (int) $raw->lLen('session:events'));
        }
    }

    public function testRemoveAndTrim(): void
    {
        $jobs = $this->store->list('jobs');
        foreach (['a', 'b', 'a', 'c', 'a'] as $value) {
            $jobs->push($value);
        }

        self::assertSame(1, $jobs->remove('a'), 'one occurrence by default');
        self::assertSame(['b', 'a', 'c', 'a'], $jobs->range());

        self::assertSame(2, $jobs->remove('a', count: 0), 'zero means all of them');
        self::assertSame(['b', 'c'], $jobs->range());

        $jobs->trim(0, 0);
        self::assertSame(['b'], $jobs->range());
    }

    public function testSetOverwritesByIndex(): void
    {
        $jobs = $this->store->list('jobs');
        $jobs->push('a');
        $jobs->push('b');

        self::assertTrue($jobs->set(1, 'B'));
        self::assertSame(['a', 'B'], $jobs->range());
    }

    public function testSetOutOfRangeAnswersFalseAndLeavesNoErrorBehind(): void
    {
        $jobs = $this->store->list('jobs');
        $jobs->push('a');

        self::assertFalse($jobs->set(5, 'z'));
        self::assertNull(
            $this->store->raw()->getLastError(),
            'the connection goes back to the pool; it must not carry this error with it',
        );
    }

    public function testMoveToLeavesTheElementInExactlyOneList(): void
    {
        $jobs       = $this->store->list('jobs');
        $processing = $this->store->list('processing');
        $jobs->push('job-1');
        $jobs->push('job-2');

        $moved = $jobs->moveTo($processing);

        self::assertSame('job-1', $moved);
        self::assertSame(['job-2'], $jobs->range());
        self::assertSame(['job-1'], $processing->range(), 'never in neither, never in both');
    }

    public function testMoveFromAnEmptyListGivesNull(): void
    {
        self::assertNull($this->store->list('jobs')->moveTo($this->store->list('processing')));
    }

    public function testMovingAcrossEndpointsIsRefused(): void
    {
        $mine     = $this->store->list('jobs');
        $foreign  = (new LegacyStore())->list('jobs');

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('same config');

        $mine->moveTo($foreign);
    }

    public function testLifetimeOfTheWholeList(): void
    {
        $jobs = $this->store->list('jobs');
        $jobs->push('a');

        self::assertNull($jobs->keyTtl());
        self::assertTrue($jobs->expireKey(90));
        self::assertSame(90, $jobs->keyTtl());
        self::assertTrue($jobs->deleteKey());
        self::assertSame(0, $jobs->count());
    }

    public function testConsumeReturnsAnElementThatIsAlreadyThere(): void
    {
        $jobs = $this->store->list('jobs');
        $jobs->push('job-1');

        self::assertSame('job-1', $jobs->consume(timeout: 1));

        $jobs->close();
    }

    public function testConsumeWaitsAndGivesUpEmptyHanded(): void
    {
        $jobs  = $this->store->list('jobs');
        $start = microtime(true);

        $result = $jobs->consume(timeout: 1);
        $waited = microtime(true) - $start;

        self::assertNull($result);
        self::assertGreaterThan(0.9, $waited, 'it really waited');
        self::assertLessThan(2.5, $waited, 'and did not die on the connection read timeout');

        $jobs->close();
    }

    public function testConsumeDoesNotTakeAConnectionFromThePool(): void
    {
        $held = null;

        Coroutine\run(function () use (&$held): void {
            $jobs = (new SessionStore())->list('jobs');
            $jobs->push('warm');                       // this one does use the pool
            $before = RedisPool::stats()[TestRedisConfig::class]['total'];

            $jobs->consume(timeout: 1);                // waits on its own connection
            $held = [$before, RedisPool::stats()[TestRedisConfig::class]['total']];

            $jobs->close();
        });

        self::assertSame($held[0], $held[1], 'the pool grew by nothing while a consumer waited');
    }

    public function testConsumersDoNotStarveTheRestOfTheApplication(): void
    {
        $served = 0;

        Coroutine\run(function () use (&$served): void {
            // Two consumers wait on an empty list for longer than the pool's own wait
            // timeout, on a pool that holds a single connection.
            foreach (range(1, 2) as $n) {
                Coroutine::create(function (): void {
                    $jobs = (new TinyPoolStore())->list('jobs');
                    $jobs->consume(timeout: 1);
                    $jobs->close();
                });
            }
            Coroutine::sleep(0.05);

            Coroutine::create(function () use (&$served): void {
                (new TinyPoolStore())->set('unrelated', 'request');
                $served = 1;
            });
        });

        self::assertSame(1, $served, 'an ordinary request went through while both consumers waited');
    }
}
