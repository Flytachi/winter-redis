<?php

declare(strict_types=1);

namespace Flytachi\Winter\Redis\Store;

use Flytachi\Winter\Redis\RedisCommandException;
use Redis;

/**
 * Turns a refusal parked in `getLastError()` into an exception, and leaves the
 * connection clean either way.
 *
 * Two problems are solved at once. A refused command comes back as `false`, which the
 * store's own return types then flatten into an ordinary-looking answer — `0` from a
 * counter, `null` from a read. And the message stays on the connection: since the
 * connection is pooled, the next borrower would find it in `getLastError()` and could
 * reasonably blame its own command.
 */
trait ChecksCommandErrors
{
    /**
     * @throws RedisCommandException When the last command was refused by the server.
     */
    private function assertCommandSucceeded(Redis $redis): void
    {
        $error = (string) $redis->getLastError();

        if ($error === '') {
            return;
        }

        $redis->clearLastError();

        throw new RedisCommandException($error);
    }

    /** Runs a command with the error slate clean, so the check that follows is about it alone. */
    private function runChecked(Redis $redis, callable $command): mixed
    {
        $redis->clearLastError();
        $result = $command($redis);
        $this->assertCommandSucceeded($redis);

        return $result;
    }
}
