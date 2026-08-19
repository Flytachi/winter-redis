<?php

declare(strict_types=1);

namespace Flytachi\Winter\Redis\Tests\Unit;

use Flytachi\Winter\Redis\RedisPool;
use Flytachi\Winter\Redis\Store\RedisHash;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(RedisHash::class)]
final class RedisHashTest extends RedisTestCase
{
    private SessionStore $store;

    protected function setUp(): void
    {
        parent::setUp();
        $this->store = new SessionStore();
    }

    public function testTheHandleCarriesThePrefixedKey(): void
    {
        $hash = $this->store->hash('cart:42');

        self::assertSame('session:cart:42', $hash->name());
    }

    public function testCreatingAHandleTalksToNoServer(): void
    {
        $this->store->hash('cart:42');

        self::assertSame([], $this->store->keys(), 'a view, not a write');
    }

    public function testSetAndGet(): void
    {
        $cart = $this->store->hash('cart:42');

        self::assertTrue($cart->set('qty', '2'));
        self::assertSame('2', $cart->get('qty'));
        self::assertNull($cart->get('absent'), 'a missing field reads as null');
        self::assertNull($this->store->hash('no-such-hash')->get('qty'));
    }

    public function testSetAllAndAll(): void
    {
        $cart = $this->store->hash('cart:42');
        $cart->setAll(['qty' => '2', 'sku' => 'A-1']);

        self::assertSame(['qty' => '2', 'sku' => 'A-1'], $cart->all());
        self::assertTrue($cart->setAll([]), 'writing nothing is not a failure');
    }

    public function testGetManyAlwaysReturnsEveryFieldAsked(): void
    {
        $cart = $this->store->hash('cart:42');
        $cart->setAll(['qty' => '2', 'sku' => 'A-1']);

        self::assertSame(
            ['qty' => '2', 'missing' => null],
            $cart->getMany('qty', 'missing'),
        );
        self::assertSame([], $cart->getMany());
    }

    public function testFieldsValuesAndCount(): void
    {
        $cart = $this->store->hash('cart:42');
        $cart->setAll(['qty' => '2', 'sku' => 'A-1']);

        self::assertSame(['qty', 'sku'], $cart->fields());
        self::assertSame(['2', 'A-1'], $cart->values());
        self::assertSame(2, $cart->count());
        self::assertTrue($cart->has('qty'));
        self::assertFalse($cart->has('nope'));
        self::assertSame(0, $this->store->hash('empty')->count());
    }

    public function testCounters(): void
    {
        $cart = $this->store->hash('cart:42');

        self::assertSame(1, $cart->increment('qty'));
        self::assertSame(4, $cart->increment('qty', 3));
        self::assertSame(3, $cart->decrement('qty'));
    }

    public function testDeleteReportsHowManyFieldsExisted(): void
    {
        $cart = $this->store->hash('cart:42');
        $cart->setAll(['a' => '1', 'b' => '2']);

        self::assertSame(2, $cart->delete('a', 'b', 'never-there'));
        self::assertSame(0, $cart->delete());
        self::assertSame(0, $cart->count());
    }

    public function testAHashWithNoFieldsLeftStopsExisting(): void
    {
        $cart = $this->store->hash('cart:42');
        $cart->set('only', '1');
        self::assertSame(['cart:42'], $this->store->keys());

        $cart->delete('only');

        self::assertSame([], $this->store->keys(), 'Redis keeps no empty containers');
    }

    public function testTtlAppliesToTheFieldNotTheHash(): void
    {
        $cart = $this->store->hash('cart:42');

        $cart->set('lock', '1', ttl: 60);
        $cart->set('sku', 'A-1');

        self::assertSame(60, $cart->ttl('lock'));
        self::assertNull($cart->ttl('sku'), 'the neighbouring field lives on');
        self::assertNull($cart->keyTtl(), 'and the key itself was never given a lifetime');
    }

    public function testSetAllAppliesOneLifetimeToEveryFieldWritten(): void
    {
        $cart = $this->store->hash('cart:42');
        $cart->setAll(['a' => '1', 'b' => '2'], ttl: 45);

        self::assertSame(45, $cart->ttl('a'));
        self::assertSame(45, $cart->ttl('b'));
    }

    public function testTtlOfAFieldThatIsNotThereIsNull(): void
    {
        self::assertNull($this->store->hash('cart:42')->ttl('nothing'));
    }

    public function testPersistRemovesAFieldLifetime(): void
    {
        $cart = $this->store->hash('cart:42');
        $cart->set('lock', '1', ttl: 60);

        self::assertTrue($cart->persist('lock'));
        self::assertNull($cart->ttl('lock'));
        self::assertFalse($cart->persist('lock'), 'nothing left to remove');
    }

    public function testExpireKeyCoversTheWholeHash(): void
    {
        $cart = $this->store->hash('cart:42');
        $cart->setAll(['a' => '1', 'b' => '2']);

        self::assertTrue($cart->expireKey(120));

        self::assertSame(120, $cart->keyTtl());
        self::assertNull($cart->ttl('a'), 'the fields themselves still have no lifetime of their own');
    }

    public function testDeleteKeyRemovesEverything(): void
    {
        $cart = $this->store->hash('cart:42');
        $cart->setAll(['a' => '1', 'b' => '2']);

        self::assertTrue($cart->deleteKey());
        self::assertSame(0, $cart->count());
    }

    public function testAHashLivesInsideItsStore(): void
    {
        $this->store->hash('shared')->set('field', 'session');
        (new QueueStore())->hash('shared')->set('field', 'queue');

        self::assertSame('session', $this->store->hash('shared')->get('field'));
        self::assertSame('queue', (new QueueStore())->hash('shared')->get('field'));
        self::assertSame(['shared'], $this->store->keys(), 'and flush() would see it');
    }

    public function testTheHandleDoesNotHoldAConnection(): void
    {
        $cart = $this->store->hash('cart:42');
        $cart->set('qty', '2');

        RedisPool::shutdown();          // every socket this process owned is gone

        self::assertSame('2', $cart->get('qty'), 'the handle asks the store again');
    }
}
