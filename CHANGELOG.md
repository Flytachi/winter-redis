# Changelog

All notable changes to this project are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the project adheres to
[Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.0.0] — Unreleased

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

[1.0.0]: https://github.com/flytachi/winter-redis/releases/tag/v1.0.0
