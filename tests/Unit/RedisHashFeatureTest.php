<?php

declare(strict_types=1);

namespace Flytachi\Winter\Redis\Tests\Unit;

use Flytachi\Winter\Redis\RedisFeatureException;
use Flytachi\Winter\Redis\RedisPool;
use Flytachi\Winter\Redis\Store\RedisHash;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * What happens when the server is older than the feature.
 *
 * Per-field lifetimes need Redis 8.0; most deployments are not there yet, so the
 * refusal is part of the API and is tested like the rest of it. The old server is
 * simulated by starting one with those commands renamed away, which produces the very
 * same `ERR unknown command` an old Redis answers — the condition the code reads.
 */
#[CoversClass(RedisHash::class)]
final class RedisHashFeatureTest extends TestCase
{
    private const int PORT = 6397;

    protected function setUp(): void
    {
        if (trim((string) shell_exec('command -v redis-server')) === '') {
            self::markTestSkipped('redis-server is not on PATH.');
        }

        putenv('REDIS_LEGACY_PORT=' . self::PORT);
        RedisPool::shutdown();

        shell_exec(sprintf(
            'redis-server --port %d --save "" --appendonly no --daemonize yes '
            . '--rename-command HSETEX "" --rename-command HTTL "" --rename-command HPERSIST "" 2>/dev/null',
            self::PORT,
        ));

        for ($i = 0; $i < 100; $i++) {
            $socket = @fsockopen('127.0.0.1', self::PORT, $errno, $error, 0.1);
            if ($socket !== false) {
                fclose($socket);
                return;
            }
            usleep(20_000);
        }

        self::markTestSkipped('Could not start a server without the hash-lifetime commands.');
    }

    protected function tearDown(): void
    {
        RedisPool::shutdown();
        shell_exec(sprintf('redis-cli -p %d shutdown nosave 2>/dev/null', self::PORT));
        putenv('REDIS_LEGACY_PORT');
    }

    public function testAFieldLifetimeIsRefusedWithAnExplanation(): void
    {
        $hash = (new LegacyStore())->hash('cart');

        try {
            $hash->set('lock', '1', ttl: 60);
            self::fail('an old server cannot do per-field lifetimes');
        } catch (RedisFeatureException $e) {
            self::assertStringContainsString('HSETEX', $e->getMessage());
            self::assertStringContainsString('8.0', $e->getMessage(), 'says what is required');
            self::assertStringContainsString('expireKey()', $e->getMessage(), 'and what to do instead');
        }
    }

    public function testTheRefusalLeavesNoErrorOnTheConnection(): void
    {
        $store = new LegacyStore();

        try {
            $store->hash('cart')->set('lock', '1', ttl: 60);
        } catch (RedisFeatureException) {
            // expected
        }

        // The connection goes back to the pool after this request; a driver error left
        // parked on it would surface as someone else's failure later.
        self::assertNull($store->raw()->getLastError());
    }

    public function testReadingAFieldLifetimeIsRefusedToo(): void
    {
        $this->expectException(RedisFeatureException::class);

        (new LegacyStore())->hash('cart')->ttl('lock');
    }

    public function testPersistIsRefusedToo(): void
    {
        $this->expectException(RedisFeatureException::class);

        (new LegacyStore())->hash('cart')->persist('lock');
    }

    public function testEverythingElseKeepsWorkingOnSuchAServer(): void
    {
        $hash = (new LegacyStore())->hash('cart');

        $hash->setAll(['qty' => '2', 'sku' => 'A-1']);

        self::assertSame(['qty' => '2', 'sku' => 'A-1'], $hash->all());
        self::assertSame(3, $hash->increment('qty', 1));
        self::assertTrue($hash->expireKey(120), 'a lifetime for the whole hash needs nothing new');
        self::assertSame(120, $hash->keyTtl());
    }
}
