<?php

declare(strict_types=1);

namespace Flytachi\Winter\Redis\Tests\Unit;

use Flytachi\Winter\Redis\RedisCommandException;
use Flytachi\Winter\Redis\Store\RedisStore;
use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * A command the server refuses must not come back looking like an answer.
 *
 * phpredis reports a refusal by returning `false` and leaving the text in
 * `getLastError()`. Cast to the store's return types that becomes a counter reading `0`
 * or a value that "is not there" — and the message rides the connection back into the
 * pool.
 */
#[CoversClass(RedisStore::class)]
final class CommandErrorTest extends RedisTestCase
{
    private SessionStore $store;

    protected function setUp(): void
    {
        parent::setUp();
        $this->store = new SessionStore();
    }

    public function testIncrementingSomethingThatIsNotANumberFails(): void
    {
        $this->store->set('name', 'Alice');

        try {
            $this->store->increment('name');
            self::fail('a refusal must not be reported as a counter value');
        } catch (RedisCommandException $e) {
            self::assertStringContainsString('not an integer', $e->getMessage());
        }

        self::assertNull($this->store->raw()->getLastError(), 'and nothing is left on the connection');
    }

    public function testReadingAKeyOfTheWrongTypeFails(): void
    {
        $this->store->list('jobs')->push('a');

        $this->expectException(RedisCommandException::class);
        $this->expectExceptionMessage('WRONGTYPE');

        $this->store->get('jobs');
    }

    public function testIncrementingAHashFieldThatIsNotANumberFails(): void
    {
        $hash = $this->store->hash('cart');
        $hash->set('sku', 'A-1');

        $this->expectException(RedisCommandException::class);
        $this->expectExceptionMessage('not an integer');

        $hash->increment('sku');
    }

    public function testANonPositiveTtlIsRejectedBeforeItReachesTheServer(): void
    {
        try {
            $this->store->set('key', 'value', ttl: 0);
            self::fail('ttl: 0 is a caller mistake, not a value Redis can honour');
        } catch (LogicException $e) {
            self::assertStringContainsString('positive', $e->getMessage());
            self::assertStringContainsString('delete()', $e->getMessage(), 'says what to do instead');
        }

        self::assertNull($this->store->raw()->getLastError());
    }

    public function testANonPositiveFieldTtlIsRejectedToo(): void
    {
        $this->expectException(LogicException::class);

        $this->store->hash('cart')->set('lock', '1', ttl: -5);
    }

    public function testOrdinaryCommandsAreUnaffected(): void
    {
        self::assertTrue($this->store->set('counter', '5'));
        self::assertSame(6, $this->store->increment('counter'));
        self::assertSame('6', $this->store->get('counter'));
        self::assertNull($this->store->get('absent'), 'a miss is still a miss, not an error');
    }
}
