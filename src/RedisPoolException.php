<?php

declare(strict_types=1);

namespace Flytachi\Winter\Redis;

use RuntimeException;

/**
 * Thrown when no usable connection could be obtained for a config — the pool was
 * exhausted within its wait timeout, or the connection could not be opened at all.
 *
 * The originating {@see \Flytachi\Winter\CPool\PoolException} is kept as `getPrevious()`.
 */
class RedisPoolException extends RuntimeException
{
}
