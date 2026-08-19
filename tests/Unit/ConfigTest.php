<?php

declare(strict_types=1);

namespace Flytachi\Winter\Redis\Tests\Unit;

use Flytachi\Winter\Redis\Config\Call\RedisCall;
use PHPUnit\Framework\Attributes\CoversClass;
use Redis;

#[CoversClass(\Flytachi\Winter\Redis\Config\Common\BaseRedisConfig::class)]
#[CoversClass(RedisCall::class)]
final class ConfigTest extends RedisTestCase
{
    private function call(int $db = 0): RedisCall
    {
        return new RedisCall(host: self::host(), port: self::port(), databaseIndex: $db ?: self::db());
    }

    public function testConnectsLazilyAndReusesTheSocket(): void
    {
        $config = $this->call();

        $first  = $config->connection();
        $second = $config->connection();

        self::assertSame($first, $second, 'one config owns exactly one socket');
    }

    public function testPingAnswersTrueForALiveServer(): void
    {
        self::assertTrue($this->call()->ping());
    }

    public function testPingAnswersFalseInsteadOfThrowingWhenNobodyIsListening(): void
    {
        $config = new RedisCall(host: '127.0.0.1', port: 6300, timeout: 0.2);

        self::assertFalse($config->ping(), 'a probe reports death, it does not raise it');
    }

    public function testPingDetailReportsLatencyAndError(): void
    {
        $ok = $this->call()->pingDetail();
        self::assertTrue($ok['status']);
        self::assertNull($ok['error']);
        self::assertIsFloat($ok['latency']);

        $dead = (new RedisCall(host: '127.0.0.1', port: 6300, timeout: 0.2))->pingDetail();
        self::assertFalse($dead['status']);
        self::assertNotNull($dead['error']);
    }

    public function testDisconnectIsIdempotent(): void
    {
        $config = $this->call();
        $config->connection();

        $config->disconnect();
        $config->disconnect();

        self::assertTrue($config->ping(), 'reconnects lazily after being closed');
    }

    public function testReconnectReplacesTheSocket(): void
    {
        $config = $this->call();
        $before = $config->connection();

        $config->reconnect();

        self::assertNotSame($before, $config->connection());
    }

    public function testDsnCarriesNoPassword(): void
    {
        $config = new RedisCall(host: 'db.internal', port: 6380, password: 's3cret', databaseIndex: 4);

        self::assertSame('redis://db.internal:6380/4', $config->getDsn());
        self::assertStringNotContainsString('s3cret', $config->getDsn());
    }

    public function testAnAclUserAuthenticatesAsThatUser(): void
    {
        $admin = $this->call()->connection();
        $admin->rawCommand('ACL', 'SETUSER', 'winter-test', 'on', '>s3cret', '~*', '+@all');

        try {
            $config = new RedisCall(
                host: self::host(),
                port: self::port(),
                password: 's3cret',
                username: 'winter-test',
            );

            self::assertSame('winter-test', $config->connection()->rawCommand('ACL', 'WHOAMI'));
            self::assertSame('winter-test', $config->getUsername());
        } finally {
            $admin->rawCommand('ACL', 'DELUSER', 'winter-test');
        }
    }

    public function testWithoutAUsernameTheDefaultUserIsUsed(): void
    {
        self::assertSame('default', $this->call()->connection()->rawCommand('ACL', 'WHOAMI'));
        self::assertSame('', $this->call()->getUsername());
    }

    public function testSerializerIsAppliedToTheConnection(): void
    {
        $config = new RedisCall(
            host: self::host(),
            port: self::port(),
            databaseIndex: self::db(),
            serializer: Redis::SERIALIZER_PHP,
        );

        self::assertSame(Redis::SERIALIZER_PHP, $config->getSerializer());
        self::assertSame(
            Redis::SERIALIZER_PHP,
            $config->connection()->getOption(Redis::OPT_SERIALIZER),
        );
    }
}
