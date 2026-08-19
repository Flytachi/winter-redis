<?php

declare(strict_types=1);

namespace Flytachi\Winter\Redis\Tests\Unit;

use Flytachi\Winter\Redis\RedisCommandException;
use Flytachi\Winter\Redis\RedisPool;
use Flytachi\Winter\Redis\Store\RedisStreamGroup;
use PHPUnit\Framework\Attributes\CoversClass;
use Swoole\Coroutine;

#[CoversClass(RedisStreamGroup::class)]
final class RedisStreamGroupTest extends RedisTestCase
{
    private SessionStore $store;

    protected function setUp(): void
    {
        parent::setUp();
        $this->store = new SessionStore();
    }

    public function testEnsureGroupSaysWhetherItCreatedTheGroup(): void
    {
        $events = $this->store->stream('events');

        self::assertTrue($events->ensureGroup('workers'), 'created');
        self::assertFalse($events->ensureGroup('workers'), 'already there — not an error');
    }

    public function testTheGroupIsCreatedEvenBeforeTheStreamExists(): void
    {
        $events = $this->store->stream('events');

        self::assertTrue($events->ensureGroup('workers'));
        self::assertSame(0, $events->count());
        self::assertSame([], $events->group('workers')->consume(timeout: -1), 'nothing yet, and no error');
    }

    public function testConsumingFromAGroupThatDoesNotExistSaysSo(): void
    {
        $this->store->stream('events')->add(['n' => '1']);

        $this->expectException(RedisCommandException::class);
        $this->expectExceptionMessage('NOGROUP');

        $this->store->stream('events')->group('mistyped')->consume(timeout: -1);
    }

    public function testEachEntryGoesToExactlyOneConsumer(): void
    {
        $events = $this->store->stream('events');
        $events->ensureGroup('workers');
        foreach (range(1, 6) as $n) {
            $events->add(['n' => (string) $n]);
        }

        $first  = $events->group('workers', 'worker-1')->consume(count: 3, timeout: -1);
        $second = $events->group('workers', 'worker-2')->consume(count: 3, timeout: -1);

        self::assertSame(['1', '2', '3'], array_map(fn($e) => $e->get('n'), $first));
        self::assertSame(['4', '5', '6'], array_map(fn($e) => $e->get('n'), $second));
    }

    public function testAnUnacknowledgedEntryStaysPending(): void
    {
        $events = $this->store->stream('events');
        $events->ensureGroup('workers');
        $events->add(['n' => '1']);

        $group = $events->group('workers', 'worker-1');
        [$entry] = $group->consume(timeout: -1);

        self::assertSame(1, $group->pendingCount());

        $pending = $group->pending();
        self::assertCount(1, $pending);
        self::assertSame($entry->id, $pending[0]->id);
        self::assertSame('worker-1', $pending[0]->consumer);
        self::assertSame(1, $pending[0]->deliveries);
        self::assertGreaterThanOrEqual(0, $pending[0]->idleMs);
    }

    public function testAckClearsThePendingEntry(): void
    {
        $events = $this->store->stream('events');
        $events->ensureGroup('workers');
        $events->add(['n' => '1']);
        $events->add(['n' => '2']);

        $group   = $events->group('workers', 'worker-1');
        $entries = $group->consume(timeout: -1);

        self::assertSame(2, $group->ack(...$entries));
        self::assertSame(0, $group->pendingCount());
        self::assertSame(0, $group->ack(), 'acknowledging nothing is not an error');
        self::assertSame(0, $group->ack($entries[0]), 'already acknowledged');
    }

    public function testAckAcceptsIdentifiersAsWellAsEntries(): void
    {
        $events = $this->store->stream('events');
        $events->ensureGroup('workers');
        $events->add(['n' => '1']);

        $group   = $events->group('workers', 'worker-1');
        [$entry] = $group->consume(timeout: -1);

        self::assertSame(1, $group->ack($entry->id));
    }

    public function testBacklogReturnsWhatThisConsumerStillHolds(): void
    {
        $events = $this->store->stream('events');
        $events->ensureGroup('workers');
        $events->add(['n' => '1']);

        $group = $events->group('workers', 'worker-1');
        $group->consume(timeout: -1);                 // taken, not acknowledged

        self::assertSame([], $group->consume(timeout: -1), 'nothing new is left');

        $resumed = $group->backlog();                  // a restarted worker asks for its own
        self::assertSame(['1'], array_map(fn($e) => $e->get('n'), $resumed));
    }

    public function testClaimStaleTakesOverWorkFromAConsumerThatStopped(): void
    {
        $events = $this->store->stream('events');
        $events->ensureGroup('workers');
        $events->add(['n' => '1']);

        $dead = $events->group('workers', 'worker-1');
        [$entry] = $dead->consume(timeout: -1);        // taken and never acknowledged

        usleep(60_000);

        $alive   = $events->group('workers', 'worker-2');
        $claimed = $alive->claimStale(idle: 50);

        self::assertCount(1, $claimed);
        self::assertSame($entry->id, $claimed[0]->id);

        $pending = $alive->pending();
        self::assertSame('worker-2', $pending[0]->consumer, 'it now belongs to the live consumer');
        self::assertSame(2, $pending[0]->deliveries, 'and the delivery count records the retry');
    }

    public function testClaimingResetsTheIdleClockSoTheLoopConverges(): void
    {
        $events = $this->store->stream('events');
        $events->ensureGroup('workers');
        $events->add(['n' => '1']);
        $events->group('workers', 'worker-1')->consume(timeout: -1);

        usleep(60_000);
        $alive = $events->group('workers', 'worker-2');

        self::assertCount(1, $alive->claimStale(idle: 50));
        self::assertCount(0, $alive->claimStale(idle: 50), 'the same entry is not returned twice');
    }

    public function testAsReturnsTheSameGroupUnderAnotherConsumerName(): void
    {
        $group = $this->store->stream('events')->group('workers', 'worker-1');
        $other = $group->as('worker-2');

        self::assertSame('workers', $other->name());
        self::assertSame('worker-2', $other->consumer());
        self::assertSame('worker-1', $group->consumer(), 'the original is left alone');
    }

    public function testGroupsAndConsumersAreReported(): void
    {
        $events = $this->store->stream('events');
        $events->ensureGroup('workers');
        $events->add(['n' => '1']);
        $events->group('workers', 'worker-1')->consume(timeout: -1);

        $groups = $events->groups();
        self::assertSame('workers', $groups[0]['name']);
        self::assertSame(1, $groups[0]['pending']);

        $consumers = $events->group('workers')->consumers();
        self::assertSame('worker-1', $consumers[0]['name']);
    }

    public function testDestroyRemovesTheGroupButKeepsTheEntries(): void
    {
        $events = $this->store->stream('events');
        $events->ensureGroup('workers');
        $events->add(['n' => '1']);

        self::assertTrue($events->group('workers')->destroy());
        self::assertSame([], $events->groups());
        self::assertSame(1, $events->count(), 'the log itself is untouched');
    }

    public function testBlockingConsumeWaitsOnItsOwnConnection(): void
    {
        $held = null;

        Coroutine\run(function () use (&$held): void {
            $store  = new SessionStore();
            $events = $store->stream('events');
            $events->ensureGroup('workers');
            $events->add(['n' => 'warm']);

            $before = RedisPool::stats()[TestRedisConfig::class]['total'];

            $group = $events->group('workers', 'worker-1');
            $group->consume(timeout: -1);                 // drain
            $group->consume(count: 1, timeout: 0.3);      // now it really waits

            $held = [$before, RedisPool::stats()[TestRedisConfig::class]['total']];
            $group->close();
        });

        self::assertSame($held[0], $held[1], 'the pool did not grow while a consumer waited');
    }
}
