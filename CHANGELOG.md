# Changelog

All notable changes to this project are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the project adheres to
[Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [1.0.4] — 2026-10-07

### Fixed

**An abandoned `MULTI` no longer leaks into the next request.** A request that called
`multi()` and never reached `exec()`/`discard()` — an exception, an early return, a request
timeout — returned its connection to the pool still in `MULTI`. The next borrower's reads came
back as queued `Redis` objects instead of values, its writes never reached the server, and the
next `exec()` on that connection **published the writes the failed request meant to abandon**.
Reproduced against the real pool and a live Redis before the fix. The same held for an
unfinished `pipeline()` and for a `select()` on a pooled connection (the next borrower wrote
into the other database).

`RedisConnectionFactory` now implements `ResettableConnectionFactory`: on return it discards
an open `MULTI`/`pipeline()` and selects the config's database back, logging **ERROR** with the
config and the coroutine; a connection that cannot be reset is retired. Discard, not exec —
executing would publish unfinished work. The check costs no round trip: mode and database are
phpredis's client-side state.

**`RedisStore::transaction()` cleans up when its callback throws.** Before the exception
propagates, an open `MULTI`/`pipeline()` is discarded (which also drops its `WATCH`), otherwise
a `WATCH` is cleared with `UNWATCH`. A stale `WATCH` aborted the next borrower's `EXEC` — it got
`false` for a transaction that never watched anything. The cleanup costs one round trip on the
failure path only; a block that completes pays nothing. This is where `WATCH` is covered: the
client keeps no trace of it, so clearing it at return time would mean an `UNWATCH` round trip
on every release. A `watch()` on `raw()` outside `transaction()` remains the caller's to close.

Not covered: the non-coroutine `SingleConnection` path.

### Tests

The coroutine tests (`RedisPoolCoroutineTest`, `RedisListTest`, `RedisStreamGroupTest`) hung
forever against a live Redis since `winter-cpool` 1.1.0 turned pool housekeeping on by default:
its repeating timer kept `Coroutine\run()` from returning, and nothing shut the pools down
inside it. They now run through `RedisTestCase::runCoroutines()`, which waits for the
coroutines the test started and then shuts the pools down, as `workerExit` does for a worker.
The whole suite runs again: 133 tests, about 4 s.

Requires `flytachi/winter-cpool` `^1.2` (the constraint is raised accordingly).

## [1.0.3] — 2026-09-22

### Changed

**Pool housekeeping is on by default.** `RedisPoolTrait` now answers `keepaliveTime` with
`120.0` (was `0.0`) and `idleTimeout` with `600.0` (was `0.0`); `minimumIdle` stays `0`, and
`poolMaxConnections` / `poolWaitTimeout` are untouched. A config that declares the property
itself is unaffected — the trait only supplies what was not set.

Redis makes this sharper than the database case: the default pool is ten connections per
worker, so a pool that sat idle while the server (or a firewall) dropped its sockets left ten
corpses for the next request to find, and before the matching `winter-cpool` fix that cost
the first two requests outright. Redis's own `timeout` directive closes idle clients on many
managed offerings, which is exactly what a two-minute ping prevents; the ten-minute
`idleTimeout` then hands the connections back rather than holding `worker_num × 10` of them
overnight.

The numbers are HikariCP's and keep its ordering, `keepaliveTime < idleTimeout < maxLifetime`.

### Added

- `RedisPoolTraitTest` — the trait's defaults and property overrides had no coverage.

## [1.0.0] — 2026-08-19

First release. Pooled Redis for long-running PHP, built on
[flytachi/winter-cpool](https://github.com/flytachi/winter-cpool).

### Added

**Configuration** — `RedisConfig` for an application-declared endpoint, `RedisCall` for
one-off work. Host accepts a scheme (`tls://`, a socket path); TLS material goes through a
stream `$context`; ACL users (Redis 6+) alongside plain passwords; the database index and
the value serializer belong to the config, never to a call.

**Pool** — `RedisPool` keeps one pool per config class. Under Swoole a connection is
borrowed on first use in a coroutine and returned by a `defer`; elsewhere a single
self-maintaining connection serves the process. `dedicated()` opens a connection outside
the pool for commands that block. `reportFailure()` decides by probing, not by matching
error text. `reset()` for a forked child, `shutdown()` at worker exit, `stats()` per
worker.

**Stores** — `RedisStore` with a key prefix: `get`, `set` (with `ttl`), `has`, `delete`,
`increment`, `decrement`, `ttl`, `keys`, `flush`, `key`, `raw`, `transaction`. `keys()`
and `flush()` scan rather than block, and `flush()` refuses outright without a prefix.

**Hashes** — `RedisHash`: fields, bulk reads and writes, counters, and per-field lifetimes
— `HTTL` and `HPERSIST` on Redis 7.4+, `HSETEX` on 8.0+ — with a refusal on older servers
that names the command, the version it needs and the one that is running.

**Lists** — `RedisList`: queues and stacks, `push(cap:)` as one atomic `MULTI(RPUSH,
LTRIM)`, `consume()` blocking on a dedicated connection, and `moveTo()` for a queue that
does not lose work when a worker dies.

**Streams** — `RedisStream` and `RedisStreamGroup`: append, read by range or position,
`follow()` with a remembered cursor, trimming by count or by age, consumer groups with
explicit acknowledgement, pending entries with delivery counts, and `claimStale()` for
work stuck behind a dead consumer. `StreamEntry` and `PendingEntry` carry the results.

**Errors** — `RedisPoolException` (no connection), `RedisCommandException` (the server
refused), `RedisFeatureException` (the command is newer than the server). A refusal is
never returned as a plausible value, and no driver error is left on a pooled connection.

### Notes

`ext-swoole` is a suggestion, not a requirement: without it the package uses one
self-maintaining connection per config with the same probing and lifetime logic.

A PSR-16 adapter was written, measured and deliberately left out — nothing in the
ecosystem asks for it, adding it later is a minor release while removing it would be a
major one. The code is kept as a recipe in
[`docs/recipes/psr-16-adapter.md`](docs/recipes/psr-16-adapter.md).

[Unreleased]: https://github.com/flytachi/winter-redis/compare/v1.0.4...HEAD
[1.0.4]: https://github.com/flytachi/winter-redis/releases/tag/v1.0.4
[1.0.3]: https://github.com/flytachi/winter-redis/releases/tag/v1.0.3
[1.0.0]: https://github.com/flytachi/winter-redis/releases/tag/v1.0.0
