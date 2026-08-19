<?php

declare(strict_types=1);

namespace Flytachi\Winter\Redis;

use RuntimeException;

/**
 * Thrown when the API is asked for something the server it is talking to cannot do —
 * a command introduced by a later Redis than the one answering.
 *
 * It exists so a version mismatch reads as a version mismatch. Left alone, the driver
 * reports `ERR unknown command`, which sends people looking for a typo in their own
 * code; this names the command, the version it needs and the version that answered.
 */
class RedisFeatureException extends RuntimeException
{
}
