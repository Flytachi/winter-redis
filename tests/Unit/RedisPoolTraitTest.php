<?php

declare(strict_types=1);

namespace Flytachi\Winter\Redis\Tests\Unit;

use Flytachi\Winter\Redis\Pool\RedisPoolTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * RedisPoolTrait supplies a default for every pool knob, so a config that declares none
 * of the backing properties still answers the interface — and honours the ones it does
 * declare.
 */
#[CoversClass(RedisPoolTrait::class)]
final class RedisPoolTraitTest extends TestCase
{
    public function test_defaults_when_no_properties_declared(): void
    {
        $config = new class {
            use RedisPoolTrait;
        };

        self::assertSame(10, $config->getPoolMaxConnections());
        self::assertSame(3.0, $config->getPoolWaitTimeout());
        self::assertSame(120.0, $config->getKeepaliveTime(), 'idle connections are kept alive by default');
        self::assertSame(600.0, $config->getIdleTimeout(), 'and released after ten idle minutes');
        self::assertSame(0, $config->getMinimumIdle(), 'no warm floor — the pool multiplies by worker');
    }

    public function test_property_overrides(): void
    {
        $config = new class {
            use RedisPoolTrait;

            public int $poolMaxConnections = 20;
            public float $poolWaitTimeout = 5.0;
            public float $keepaliveTime = 30.0;
            public float $idleTimeout = 300.0;
            public int $minimumIdle = 4;
        };

        self::assertSame(20, $config->getPoolMaxConnections());
        self::assertSame(5.0, $config->getPoolWaitTimeout());
        self::assertSame(30.0, $config->getKeepaliveTime());
        self::assertSame(300.0, $config->getIdleTimeout());
        self::assertSame(4, $config->getMinimumIdle());
    }
}
