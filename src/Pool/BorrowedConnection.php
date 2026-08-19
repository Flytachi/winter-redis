<?php

declare(strict_types=1);

namespace Flytachi\Winter\Redis\Pool;

use Flytachi\Winter\CPool\PoolEntry;

/**
 * The connection the current coroutine holds, plus whether it was found dead while in
 * use.
 *
 * It exists so {@see \Flytachi\Winter\Redis\RedisPool::reportFailure()} and the `defer`
 * that returns the connection can agree without reading the coroutine context during
 * teardown: the defer captures this object directly, and reporting a failure flips
 * {@see $dead} so the connection is evicted instead of pushed back into the pool.
 */
final class BorrowedConnection
{
    /** Set when the connection was found dead in use — the defer evicts, never releases. */
    public bool $dead = false;

    public function __construct(public readonly PoolEntry $entry)
    {
    }
}
