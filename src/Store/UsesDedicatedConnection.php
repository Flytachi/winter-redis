<?php

declare(strict_types=1);

namespace Flytachi\Winter\Redis\Store;

use Flytachi\Winter\Redis\Config\Common\RedisConfigInterface;
use Flytachi\Winter\Redis\RedisPool;
use Redis;

/**
 * A connection of the handle's own, for commands that wait.
 *
 * Blocking commands — `BLPOP`, `XREAD BLOCK`, `XREADGROUP BLOCK` — occupy their
 * connection for the entire wait. Taking one from the pool means holding a pool slot
 * just as long, and a handful of consumers can empty the pool while Redis itself sits
 * idle; the incident then reads as "Redis is slow".
 *
 * The connection is opened on first use and reused, so a consumer loop costs one socket
 * rather than one per iteration — provided the handle is kept for the loop.
 *
 * The using class must expose `configClass()`.
 */
trait UsesDedicatedConnection
{
    private ?RedisConfigInterface $dedicated = null;

    /**
     * The handle's own connection, with its read timeout set to cover the wait.
     *
     * That last part is not optional. A connection gives up on a silent server after
     * `readTimeout` seconds, and a blocking read is a deliberately silent server:
     * measured, the default two seconds kills a five-second wait with `read error on
     * connection`. `-1` removes the limit entirely — `0` does not, it means "do not
     * wait at all".
     *
     * @param float $blockingSeconds How long the coming command may wait; `0` = forever.
     */
    private function dedicatedConnection(float $blockingSeconds): Redis
    {
        $this->dedicated ??= RedisPool::dedicated($this->configClass());
        $redis = $this->dedicated->connection();
        $redis->setOption(Redis::OPT_READ_TIMEOUT, $blockingSeconds > 0 ? $blockingSeconds + 1.0 : -1);

        return $redis;
    }

    /**
     * Closes the connection this handle opened, if any.
     *
     * Always safe to call: a handle that never blocked has nothing to close. Dropping
     * the last reference to the handle closes it too, but a long-running daemon should
     * not rely on that.
     */
    public function close(): void
    {
        $this->dedicated?->disconnect();
        $this->dedicated = null;
    }
}
