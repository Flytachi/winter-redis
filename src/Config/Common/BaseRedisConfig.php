<?php

declare(strict_types=1);

namespace Flytachi\Winter\Redis\Config\Common;

use Redis;
use RedisException;
use Throwable;

/**
 * The connection mechanics shared by every config: one lazy socket, authentication,
 * database selection, and an honest liveness probe.
 *
 * Subclasses supply the credentials — {@see \Flytachi\Winter\Redis\Config\RedisConfig}
 * by declaring them in `setUp()`, {@see \Flytachi\Winter\Redis\Config\Call\RedisCall}
 * by taking them as constructor arguments.
 */
abstract class BaseRedisConfig implements RedisConfigInterface
{
    /**
     * Server address. A scheme the driver understands may be prepended — `tls://` for a
     * TLS endpoint, `unix://` for a socket path.
     */
    protected string $host = 'localhost';

    protected int $port = 6379;

    /**
     * ACL user name (Redis 6+). Empty means the `default` user, which is what a plain
     * password authenticates as.
     */
    protected string $username = '';

    protected string $password = '';

    protected int $databaseIndex = 0;

    /**
     * Stream context handed to the driver — where TLS material goes: a CA bundle, a
     * client certificate, peer verification.
     *
     * ```php
     * $this->context = ['stream' => ['cafile' => '/etc/ssl/redis-ca.pem']];
     * ```
     *
     * @var array<string, mixed>
     */
    protected array $context = [];

    /** Seconds to wait for the socket to open. */
    protected float $timeout = 1.5;

    /** Seconds to wait for a reply once connected. */
    protected float $readTimeout = 2.0;

    /**
     * How values are encoded on the wire — one of the `Redis::SERIALIZER_*` constants.
     *
     * `SERIALIZER_NONE` (the default) stores exactly the bytes you pass, which is the
     * only form other languages and `redis-cli` can read. `SERIALIZER_JSON` and
     * `SERIALIZER_PHP` let you store arrays and objects directly, at the cost of a
     * format your data is then stuck with: changing this on a populated database makes
     * every existing key unreadable.
     */
    protected int $serializer = Redis::SERIALIZER_NONE;

    private ?Redis $store = null;

    final public function connect(): void
    {
        if ($this->store !== null) {
            return;
        }

        $store = new Redis();
        $store->connect(
            $this->host,
            $this->port,
            $this->timeout,
            context: $this->context === [] ? null : $this->context,
        );
        $store->setOption(Redis::OPT_READ_TIMEOUT, $this->readTimeout);
        $store->setOption(Redis::OPT_SERIALIZER, $this->serializer);

        if ($this->password !== '' || $this->username !== '') {
            // A bare password authenticates as `default`; an ACL user needs the pair.
            $store->auth($this->username === '' ? $this->password : [$this->username, $this->password]);
        }
        if ($this->databaseIndex !== 0) {
            $store->select($this->databaseIndex);
        }

        // Published only once fully set up: a half-configured client must never become
        // visible, or a concurrent borrower could use it before `select()` ran.
        $this->store = $store;
    }

    final public function disconnect(): void
    {
        if ($this->store === null) {
            return;
        }

        try {
            $this->store->close();
        } catch (RedisException) {
            // Already gone at the other end — nothing left to close.
        }
        $this->store = null;
    }

    final public function reconnect(): void
    {
        $this->disconnect();
        $this->connect();
    }

    final public function connection(): Redis
    {
        $this->connect();
        return $this->store;
    }

    /**
     * Answers whether the server responded, and nothing more.
     *
     * `Redis::ping()` returns `true` on a modern phpredis but `'+PONG'` on older
     * builds and under some option combinations, so both are accepted. Anything else —
     * including a thrown exception — counts as dead: the point of the probe is that the
     * round trip completed, not what it looked like.
     */
    final public function ping(): bool
    {
        try {
            $this->connect();
            $reply = $this->store->ping();
            return $reply === true || $reply === '+PONG' || $reply === 'PONG';
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * @return array{status: bool, latency: float|null, error: string|null}
     */
    final public function pingDetail(): array
    {
        $start  = microtime(true);
        $error  = null;
        $status = false;

        try {
            $this->connect();
            $reply  = $this->store->ping();
            $status = $reply === true || $reply === '+PONG' || $reply === 'PONG';
        } catch (Throwable $e) {
            $error = $e->getMessage();
        }

        return [
            'status'  => $status,
            'latency' => round((microtime(true) - $start) * 1000, 2),
            'error'   => $error,
        ];
    }

    final public function getHost(): string
    {
        return $this->host;
    }

    final public function getPort(): int
    {
        return $this->port;
    }

    /** ACL user name, or an empty string when authenticating as `default`. */
    final public function getUsername(): string
    {
        return $this->username;
    }

    final public function getDatabaseIndex(): int
    {
        return $this->databaseIndex;
    }

    final public function getSerializer(): int
    {
        return $this->serializer;
    }

    /** Host, port and database — never the password. Safe to log. */
    final public function getDsn(): string
    {
        return "redis://{$this->host}:{$this->port}/{$this->databaseIndex}";
    }
}
