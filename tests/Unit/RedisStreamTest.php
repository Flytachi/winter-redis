<?php

declare(strict_types=1);

namespace Flytachi\Winter\Redis\Tests\Unit;

use Flytachi\Winter\Redis\RedisCommandException;
use Flytachi\Winter\Redis\Store\RedisStream;
use Flytachi\Winter\Redis\Store\StreamEntry;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(RedisStream::class)]
#[CoversClass(StreamEntry::class)]
final class RedisStreamTest extends RedisTestCase
{
    private SessionStore $store;

    protected function setUp(): void
    {
        parent::setUp();
        $this->store = new SessionStore();
    }

    public function testTheHandleCarriesThePrefixedKey(): void
    {
        self::assertSame('session:events', $this->store->stream('events')->name());
        self::assertSame([], $this->store->keys(), 'taking a handle writes nothing');
    }

    public function testAddReturnsAServerAssignedIdentifier(): void
    {
        $events = $this->store->stream('events');

        $id = $events->add(['type' => 'signup', 'user' => '42']);

        self::assertMatchesRegularExpression('/^\d+-\d+$/', $id);
        self::assertSame(1, $events->count());
    }

    public function testReadingDoesNotConsume(): void
    {
        $events = $this->store->stream('events');
        $events->add(['n' => '1']);
        $events->add(['n' => '2']);

        self::assertCount(2, $events->range());
        self::assertCount(2, $events->range(), 'a stream is a log, not a queue');
        self::assertSame(2, $events->count());
    }

    public function testEntriesCarryTheirFields(): void
    {
        $events = $this->store->stream('events');
        $id = $events->add(['type' => 'signup', 'user' => '42']);

        [$entry] = $events->range();

        self::assertSame($id, $entry->id);
        self::assertSame(['type' => 'signup', 'user' => '42'], $entry->fields);
        self::assertSame('signup', $entry->get('type'));
        self::assertNull($entry->get('absent'));
        self::assertSame('fallback', $entry->get('absent', 'fallback'));
        self::assertTrue($entry->has('user'));
        self::assertGreaterThan(0, $entry->timestamp());
    }

    public function testRangeIsOldestFirstAndReverseIsNewestFirst(): void
    {
        $events = $this->store->stream('events');
        foreach (range(1, 5) as $n) {
            $events->add(['n' => (string) $n]);
        }

        self::assertSame(['1', '2', '3', '4', '5'], array_map(fn($e) => $e->get('n'), $events->range()));
        self::assertSame(['5', '4'], array_map(fn($e) => $e->get('n'), $events->reverse(count: 2)));
        self::assertSame(['1', '2'], array_map(fn($e) => $e->get('n'), $events->range(count: 2)));
    }

    public function testAfterReadsFromAPosition(): void
    {
        $events = $this->store->stream('events');
        $first  = $events->add(['n' => '1']);
        $events->add(['n' => '2']);
        $events->add(['n' => '3']);

        $rest = $events->after($first);

        self::assertSame(['2', '3'], array_map(fn($e) => $e->get('n'), $rest));
        self::assertSame([], $events->after($rest[1]->id), 'nothing after the last one');
    }

    public function testTwoReadersAreIndependent(): void
    {
        $events = $this->store->stream('events');
        $events->add(['n' => '1']);
        $events->add(['n' => '2']);

        $readerA = $events->range(count: 1);
        $readerB = $events->range(count: 1);

        self::assertSame($readerA[0]->id, $readerB[0]->id, 'both saw the same first entry');
    }

    public function testCapKeepsTheStreamBounded(): void
    {
        $events = $this->store->stream('events');
        foreach (range(1, 500) as $n) {
            $events->add(['n' => (string) $n], cap: 10, exact: true);
        }

        self::assertSame(10, $events->count());
        self::assertSame('500', $events->reverse(count: 1)[0]->get('n'), 'the newest survive');
    }

    public function testTrimIsApproximateUnlessAskedOtherwise(): void
    {
        $events = $this->store->stream('events');
        foreach (range(1, 200) as $n) {
            $events->add(['n' => (string) $n]);
        }

        $events->trim(10);
        $approximate = $events->count();

        $events->trim(10, exact: true);

        self::assertGreaterThanOrEqual(10, $approximate, 'approximate trimming may keep more');
        self::assertSame(10, $events->count(), 'exact trimming keeps exactly the cap');
    }

    public function testTrimBeforeRemovesByAge(): void
    {
        $events = $this->store->stream('events');
        $events->add(['n' => '1']);
        $boundary = $events->add(['n' => '2']);
        $events->add(['n' => '3']);

        $removed = $events->trimBefore($boundary, exact: true);

        self::assertSame(1, $removed);
        self::assertSame(['2', '3'], array_map(fn($e) => $e->get('n'), $events->range()));
    }

    public function testDeleteRemovesByIdentifier(): void
    {
        $events = $this->store->stream('events');
        $first  = $events->add(['n' => '1']);
        $events->add(['n' => '2']);

        self::assertSame(1, $events->delete($first));
        self::assertSame(0, $events->delete($first), 'already gone');
        self::assertSame(0, $events->delete());
        self::assertSame(['2'], array_map(fn($e) => $e->get('n'), $events->range()));
    }

    public function testInfoReportsWhatTheServerKnows(): void
    {
        $events = $this->store->stream('events');
        $events->add(['n' => '1']);
        $last = $events->add(['n' => '2']);

        $info = $events->info();

        self::assertSame(2, $info['length']);
        self::assertSame($last, $info['last-generated-id']);
    }

    public function testAnEmptyStreamReadsAsEmptyEverywhere(): void
    {
        $events = $this->store->stream('nothing');

        self::assertSame(0, $events->count());
        self::assertSame([], $events->range());
        self::assertSame([], $events->reverse());
        self::assertSame([], $events->after('0'));
        self::assertNull($events->keyTtl());
    }

    public function testWritingToAKeyOfTheWrongTypeFails(): void
    {
        $this->store->set('taken', 'a string');

        $this->expectException(RedisCommandException::class);
        $this->expectExceptionMessage('WRONGTYPE');

        $this->store->stream('taken')->add(['n' => '1']);
    }

    public function testFollowRemembersWhereItGotTo(): void
    {
        $events = $this->store->stream('events');
        $events->add(['n' => 'before']);          // added before the tail starts

        $seen = [];
        $writer = new SessionStore();

        // First call arms the cursor at "now" and waits; nothing arrives, so it is empty.
        self::assertSame([], $events->follow(timeout: 0.2));

        $writer->stream('events')->add(['n' => '1']);
        $writer->stream('events')->add(['n' => '2']);

        foreach ($events->follow(timeout: 0.2) as $entry) {
            $seen[] = $entry->get('n');
        }

        $writer->stream('events')->add(['n' => '3']);

        foreach ($events->follow(timeout: 0.2) as $entry) {
            $seen[] = $entry->get('n');
        }

        self::assertSame(['1', '2', '3'], $seen, 'history is skipped, nothing between calls is lost');

        $events->close();
    }

    public function testFollowCanStartFromTheBeginning(): void
    {
        $events = $this->store->stream('events');
        $events->add(['n' => 'old']);

        $entries = $events->follow(timeout: 0.2, from: '0');

        self::assertSame(['old'], array_map(fn($e) => $e->get('n'), $entries));

        $events->close();
    }

    public function testLifetimeOfTheWholeStream(): void
    {
        $events = $this->store->stream('events');
        $events->add(['n' => '1']);

        self::assertTrue($events->expireKey(120));
        self::assertSame(120, $events->keyTtl());
        self::assertTrue($events->deleteKey());
        self::assertSame(0, $events->count());
    }
}
