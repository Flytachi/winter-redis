<?php

declare(strict_types=1);

namespace Flytachi\Winter\Redis\Tests\Unit;

use Flytachi\Winter\Redis\RedisPool;
use Flytachi\Winter\Redis\Store\RedisStore;
use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use Redis;

#[CoversClass(RedisStore::class)]
final class RedisStoreTest extends RedisTestCase
{
    private SessionStore $store;

    protected function setUp(): void
    {
        parent::setUp();
        $this->store = new SessionStore();
    }

    public function testKeysAreWrittenUnderThePrefix(): void
    {
        $this->store->set('abc', 'value');

        self::assertSame('session:abc', $this->store->key('abc'));
        self::assertSame('value', RedisPool::store(TestRedisConfig::class)->get('session:abc'));
    }

    public function testAMissingKeyReadsAsNullNotFalse(): void
    {
        self::assertNull($this->store->get('nothing'));
    }

    public function testSetWithTtlExpires(): void
    {
        $this->store->set('short', 'x', ttl: 30);

        self::assertSame(30, $this->store->ttl('short'));
        self::assertNull($this->store->ttl('missing'), 'no key, no lifetime');

        $this->store->set('forever', 'x');
        self::assertNull($this->store->ttl('forever'), 'a key without a TTL reports none');
    }

    public function testDeleteReportsHowManyExisted(): void
    {
        $this->store->set('a', '1');
        $this->store->set('b', '2');

        self::assertSame(2, $this->store->delete('a', 'b', 'never-existed'));
        self::assertSame(0, $this->store->delete(), 'deleting nothing touches nothing');
        self::assertFalse($this->store->has('a'));
    }

    public function testCounters(): void
    {
        self::assertSame(1, $this->store->increment('hits'));
        self::assertSame(4, $this->store->increment('hits', 3));
        self::assertSame(3, $this->store->decrement('hits'));
        self::assertSame('session:hits', $this->store->key('hits'));
    }

    public function testTwoStoresOnOneDatabaseDoNotCollide(): void
    {
        $queue = new QueueStore();

        $this->store->set('id', 'session-value');
        $queue->set('id', 'queue-value');

        self::assertSame('session-value', $this->store->get('id'));
        self::assertSame('queue-value', $queue->get('id'));
    }

    public function testFlushRemovesOnlyThisStoresKeys(): void
    {
        $queue = new QueueStore();
        foreach (range(1, 5) as $n) {
            $this->store->set("k{$n}", 'x');
        }
        $queue->set('kept', 'x');

        self::assertSame(5, $this->store->flush());
        self::assertNull($this->store->get('k1'));
        self::assertSame('x', $queue->get('kept'), 'the neighbour survived');
    }

    public function testFlushWalksTheWholeKeyspaceNotJustTheFirstScanSlice(): void
    {
        $noise = RedisPool::store(TestRedisConfig::class);
        foreach (range(1, 3000) as $n) {
            $noise->set("noise:{$n}", 'x');       // spread ours thin across SCAN slices
        }
        foreach (range(1, 40) as $n) {
            $this->store->set("k{$n}", 'x');
        }

        self::assertSame(40, $this->store->flush(), 'an empty SCAN slice must not end the walk');
        self::assertSame(3000, (int) $noise->dbSize(), 'and nothing else was touched');
    }

    public function testKeysListsOnlyThisStoreAndStripsThePrefix(): void
    {
        $queue = new QueueStore();
        $this->store->set('alpha', '1');
        $this->store->set('beta', '2');
        $queue->set('gamma', '3');
        RedisPool::store(TestRedisConfig::class)->set('unrelated', '4');

        $keys = $this->store->keys();
        sort($keys);

        self::assertSame(['alpha', 'beta'], $keys, 'names come back usable, without the prefix');
        self::assertSame(['gamma'], $queue->keys());
    }

    public function testKeysAcceptsAPatternInsideTheStore(): void
    {
        $this->store->set('user:1', 'x');
        $this->store->set('user:2', 'x');
        $this->store->set('order:1', 'x');

        $keys = $this->store->keys('user:*');
        sort($keys);

        self::assertSame(['user:1', 'user:2'], $keys);
    }

    public function testKeysWalksTheWholeKeyspaceNotJustTheFirstScanSlice(): void
    {
        $noise = RedisPool::store(TestRedisConfig::class);
        foreach (range(1, 3000) as $n) {
            $noise->set("noise:{$n}", 'x');
        }
        foreach (range(1, 40) as $n) {
            $this->store->set("k{$n}", 'x');
        }

        self::assertCount(40, $this->store->keys(), 'an empty SCAN slice must not end the walk');
    }

    public function testKeysIsEmptyForAnEmptyStore(): void
    {
        self::assertSame([], $this->store->keys());
    }

    public function testKeysOnAnUnprefixedStoreSeesTheWholeDatabase(): void
    {
        RedisPool::store(TestRedisConfig::class)->set('bare', 'x');
        $this->store->set('mine', 'x');

        $keys = (new UnprefixedStore())->keys();
        sort($keys);

        self::assertSame(['bare', 'session:mine'], $keys, 'no prefix, no scoping — by definition');
    }

    public function testFlushIsRefusedWithoutAPrefix(): void
    {
        $store = new UnprefixedStore();
        $store->set('untouched', 'x');

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('$prefix');

        $store->flush();
    }

    public function testRawGivesTheUnprefixedClient(): void
    {
        $this->store->raw()->set('session:direct', 'x');

        self::assertSame('x', $this->store->get('direct'));
        self::assertInstanceOf(Redis::class, $this->store->raw());
    }

    public function testTransactionRunsAgainstOneConnection(): void
    {
        $store = $this->store;

        $result = $store->transaction(function (Redis $redis) use ($store): array {
            $redis->multi();
            $redis->incr($store->key('a'));
            $redis->incr($store->key('b'));
            return $redis->exec();
        });

        self::assertSame([1, 1], $result);
        self::assertSame('1', $store->get('a'));
    }

    public function testStoringAnythingButAStringNeedsASerializingConfig(): void
    {
        $plain      = $this->store;                 // SERIALIZER_NONE
        $serialized = new SerializedStore();        // SERIALIZER_PHP

        $serialized->set('list', ['a' => 1, 'b' => [2, 3]]);
        self::assertSame(['a' => 1, 'b' => [2, 3]], $serialized->get('list'));

        // The serializer is a property of the connection, so the same call against a
        // SERIALIZER_NONE endpoint does not fail — it stores the string "Array" and a
        // PHP warning is all you get. Which is why the choice belongs in the config,
        // where it is made once and visibly.
        @$plain->set('list', ['a' => 1]);
        self::assertSame('Array', $plain->get('list'));
    }

    public function testConfigClassIsExposed(): void
    {
        self::assertSame(TestRedisConfig::class, $this->store->configClass());
    }
}
