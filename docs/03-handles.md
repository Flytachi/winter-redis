# 03 — Handles

`RedisHash`, `RedisList`, `RedisStream` and `RedisStreamGroup` are handles: small objects
bound to one key, obtained from a store. This page is the reasoning behind them and the
rules for adding another.

## Why handles instead of methods on the store

The alternative was flat methods — `$store->hSet('cart:42', 'qty', '2')`. It loses on two
counts.

**The prefix.** A flat method takes the key on every call, so every call is a chance to
forget `key()`. Forgetting it is silent: the write lands outside the store, and only later
does `keys()` not see it and `flush()` not remove it. A handle applies the prefix once,
when it is taken, and the key is never named again.

**The names.** Bound to a hash, `set()` already means "a field"; `hSet` would repeat the
type in the name of every method. The type belongs in the object, not in the identifier.

The cost is one small object per call site. It talks to no server on construction, so the
cost is an allocation.

## What a handle may hold

- **The store** — to ask for the connection of the current unit of work, on every call.
- **The prefixed key** — resolved once, immutable.
- **A dedicated connection**, only if it blocks (see
  [01 — Connection model](01-connection-model.md)).
- **A cursor**, only if it must (`RedisStream::follow()`).

A handle must **not** hold a `\Redis`. Handles outlive requests in practice — code keeps
them in local variables across awaits — and a cached client is the exact bug the pool
exists to prevent.

## Naming

Method names describe intent; the Redis command goes in the docblock and in the mapping
table of the user documentation. `push()` rather than `rPush()`, `consume()` rather than
`blPop()`.

Two rules keep this from becoming a guessing game:

- **The verb is about the structure, not about Redis.** A hash `set()`s a field, a list
  `push()`es an element, a stream `add()`s an entry.
- **Operations on the key as a whole carry `Key`.** `ttl()` is a hash field's lifetime,
  `keyTtl()` is the hash's. Without that suffix the two would be one word apart and
  impossible to tell in review.

Where a wrapper name would collide with a Redis command of different meaning, say so
loudly: `RedisStore::keys()` runs `SCAN`, not `KEYS`, and the mapping table marks it in
bold for exactly that reason.

## What a handle wraps, and what it does not

Wrap what is used constantly and what is easy to get wrong. Leave the rest to `raw()` —
Redis has hundreds of commands and wrapping them all is an endless job, while wrapping
half of them makes people guess which half.

A method earns its place when at least one is true:

- it is on the common path (`get`, `set`, `push`, `pop`, `add`, `ack`);
- the wrapper adds a real guarantee (`push(cap:)` is `MULTI(RPUSH, LTRIM)`, not two calls);
- the raw command has a trap the wrapper removes (`flush()` refusing without a prefix,
  `keys()` scanning instead of blocking).

If a method only forwards arguments and renames the command, it is noise: `raw()` plus
`name()` says the same thing without a new name to learn.

## Return shapes

- **A miss is `null`**, not `false`. The driver cannot tell "missing" from a stored
  `false`; the wrapper picks the unambiguous half and documents the loss.
- **Counts come back as `int`** — how many existed, how many were removed — because those
  are the numbers callers branch on.
- **Collections come back as `list<...>`**, re-indexed, never as the driver's map keyed by
  identifier. `StreamEntry` and `PendingEntry` exist for that reason.
- **A refusal is an exception**, never a plausible value. See
  [02 — Driver behaviour](02-driver-behaviour.md).

## Adding a structure

The shape to copy, in order:

1. A handle class with `name()` and `configClass()`, `use ChecksCommandErrors`.
2. `RedisStore::<structure>()` returning it, applying `key()` — and nothing else.
3. Key-level operations named with `Key`, mirroring the existing handles.
4. `UsesDedicatedConnection` **only** if something blocks.
5. Tests against a live server, including the empty-key case for every reader.
6. A user documentation page in the reference format, with the command mapping table.
7. `@link` on the class and on every method whose anchor is identical in both locales.

Sets and sorted sets are the obvious next candidates. Neither blocks, so both are simpler
than what is already here.
