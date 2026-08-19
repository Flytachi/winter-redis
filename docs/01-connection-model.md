# 01 — Connection model

How a connection is obtained, returned and forgotten. This is the part of the package
where mistakes are invisible in tests and expensive in production, so it is written down.

## The layers

```
RedisConfig            one endpoint, one socket per instance
   ↑
RedisConnectionFactory  create / validate / close, for CPool
   ↑
ConnectionPool          from flytachi/winter-cpool
   ↑
RedisPool               one pool per config class + the coroutine lease
   ↑
RedisStore              prefix + commands; holds no connection
   ↑
RedisHash / RedisList / RedisStream    handles bound to one key
```

Each layer knows only the one below it. A store never sees the pool's internals, a handle
never sees a connection it did not just ask for.

## Why the pooled resource is the config, not the `\Redis`

`RedisConnectionFactory::create()` builds a fresh `RedisConfig`, calls `setUp()`,
`connect()` and returns **the config**. The pool therefore stores config instances.

Two reasons, both practical:

- **`close()` becomes deterministic.** The config owns the socket and closes it on
  `disconnect()`. Pooling a bare `\Redis` would leave closing to garbage collection.
- **`validate()` costs nothing extra.** The config already has an honest `ping()` that
  swallows every `Throwable` and accepts both reply shapes phpredis produces.

The consequence to remember: `RedisPool::config()` returns the **registration** instance,
which is a different object from every pooled one. It exists to read settings. Connecting
through it would open a socket nobody manages.

## The coroutine lease

Under Swoole, `RedisPool::store()`:

1. looks in `Coroutine::getContext()` for `winter_redis_<base64 config>`;
2. on a miss, borrows from the pool, wraps the entry in `BorrowedConnection`, stores it in
   the context and registers `Coroutine::defer()`;
3. returns `$config->connection()`.

The defer captures the `BorrowedConnection` **directly** rather than reading it back from
the context, which may already be tearing down. It carries the `dead` flag, so
`reportFailure()` can turn a release into an eviction after the fact.

Outside a coroutine there is no lease: a `SingleConnection` per config lives for the
process. The liveness and lifetime logic is the same, which is what makes a long-running
CLI worker survive a Redis restart.

## Failure decisions are made by probing

`reportFailure()` does not parse error messages. It sends one `PING` through the
connection in question: answered means the command failed and the connection did not;
silence means eviction.

PPA cannot afford this — a database probe is a full round trip on a connection that may
be hanging — but a Redis `PING` is cheap, and asking is exact where matching strings is
guesswork. See [02 — Driver behaviour](02-driver-behaviour.md) for why message matching
would be especially wrong here: phpredis reconnects underneath you.

## Dedicated connections

`RedisPool::dedicated()` opens a connection **outside** the pool: not shared, not
returned, not counted in `stats()`, not bounded by `maximumPoolSize`. The caller owns it.

It exists for commands that occupy a connection for their whole duration. The arithmetic
that forces it: ten consumers blocking for thirty seconds on a pool of ten hold the
entire pool for thirty seconds, and every unrelated request fails with an exhausted pool —
while the server sits idle. The incident then reads as "Redis is slow", which sends people
looking in the wrong place.

`UsesDedicatedConnection` implements the lazy-open-and-reuse part, so `RedisList` and the
stream handles share one implementation. It also raises `OPT_READ_TIMEOUT` for the wait,
which is not optional — see the driver notes.

## Process lifecycle

| Situation | Call | Why |
| --- | --- | --- |
| Worker is shutting down | `shutdown()` | closes sockets **and** releases the housekeeping timer; a live `Timer::tick` keeps the reactor from draining |
| Child right after `fork()` | `reset()` | forgets connections **without** closing them; closing would tear down the parent's socket, since a fork copies descriptors |
| Anything else | nothing | connections are lazy; an untouched pool costs nothing |

`reset()` calls `abandon()` on each pool before dropping it: a housekeeping timer callback
holds a reference to its pool, so a pool that is merely dereferenced would stay alive and
keep maintaining connections this process no longer owns.

The kernel wires this for PPA automatically; for this package the application registers it
(`ForkReset::register(static fn() => RedisPool::reset())`) until the package moves into
the kernel.

## What must stay true

- A store or handle must never keep a `\Redis` in a property. They re-ask on every call
  precisely so a singleton store stays correct.
- Nothing may run a stateful command (`SELECT`, `MULTI`, `SUBSCRIBE`) on a pooled
  connection outside `transaction()` or a dedicated connection.
- Every new blocking API takes a dedicated connection. If it cannot, it is not blocking.
