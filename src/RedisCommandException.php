<?php

declare(strict_types=1);

namespace Flytachi\Winter\Redis;

use RuntimeException;

/**
 * Thrown when the server refused a command — a wrong type for the key, a value that is
 * not a number, an impossible expiry.
 *
 * It exists because phpredis reports these by returning `false` and parking the text in
 * `getLastError()`. Passed through unchanged, that turns a refusal into a plausible
 * answer: a counter that "is now 0", a value that "is not there". Worse, the message
 * stays on the connection, which goes back to the pool for someone else to find.
 *
 * The message is the server's own, verbatim.
 */
class RedisCommandException extends RuntimeException
{
}
