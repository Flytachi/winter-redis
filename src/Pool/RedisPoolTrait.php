<?php

declare(strict_types=1);

namespace Flytachi\Winter\Redis\Pool;

/**
 * Default implementation of {@see RedisPoolConfigInterface}.
 *
 * It declares methods only, never properties, so a config class is free to declare the
 * backing properties with any value — a trait that declared them too would collide.
 *
 * @property int   $poolMaxConnections Ceiling on pooled connections (default: 10).
 * @property float $poolWaitTimeout    Seconds to wait for a free connection (default: 3.0).
 * @property float $keepaliveTime      Background probe of idle connections; 0 = off (default: 0.0).
 * @property float $idleTimeout        Close connections idle longer than this; 0 = never (default: 0.0).
 * @property int   $minimumIdle        Warm connection floor; 0 = fully lazy (default: 0).
 */
trait RedisPoolTrait
{
    public function getPoolMaxConnections(): int
    {
        return $this->poolMaxConnections ?? 10;
    }

    public function getPoolWaitTimeout(): float
    {
        return $this->poolWaitTimeout ?? 3.0;
    }

    public function getKeepaliveTime(): float
    {
        return $this->keepaliveTime ?? 0.0;
    }

    public function getIdleTimeout(): float
    {
        return $this->idleTimeout ?? 0.0;
    }

    public function getMinimumIdle(): int
    {
        return $this->minimumIdle ?? 0;
    }
}
