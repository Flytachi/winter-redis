# Internal documentation

Technical notes for **changing** this package. If you are using it, the documentation you
want is at [winterframe.net/docs/redis](https://winterframe.net/docs/redis) — it covers
configuration, stores, hashes, lists, streams and the pool from a user's point of view,
and it is complete.

What lives here is the other half: why the code is shaped the way it is, which driver
behaviours it works around, and what must stay true when it changes.

## Where to look

| Page | Read it when… |
| --- | --- |
| [01 — Connection model](01-connection-model.md) | you touch how a connection is obtained, returned, reset or shared |
| [02 — Driver behaviour](02-driver-behaviour.md) | phpredis does something surprising, or you are about to trust its documentation |
| [03 — Handles](03-handles.md) | you add a structure (sets, sorted sets) or a method to an existing one |
| [04 — Testing](04-testing.md) | a test needs a Redis that is broken, old, or slow on purpose |
| [recipes/](recipes/psr-16-adapter.md) | code that was written, measured and deliberately left out |

## Invariants

Five things the package promises. Everything else is negotiable; these are not, and a
change that breaks one is a change of contract.

**1. One config instance owns exactly one socket.** The pool holds config instances, not
raw `\Redis` objects, precisely so that `close()` drops a socket deterministically and
`validate()` can reuse the config's own probe. Anything that makes a config share or swap
sockets breaks pooling.

**2. A pooled connection belongs to one unit of work at a time.** Under Swoole it is
borrowed on first use in a coroutine and returned by a `defer`; nothing else may hold a
reference past that point. This is why stores hold no connection and handles re-ask on
every call.

**3. Connection state never travels between borrowers.** No `SELECT` at runtime — the
database is a property of the config. No error left in `getLastError()` — every code path
that reads it clears it. A borrower must not be able to tell who had the connection before.

**4. Blocking commands do not use the pool.** `BLPOP`, `XREAD BLOCK`, `XREADGROUP BLOCK`
occupy their connection for the whole wait; they go through `RedisPool::dedicated()`.
A blocking command on a pooled connection is a bug, not a trade-off.

**5. A refusal is never returned as an answer.** phpredis reports server refusals as
`false` plus a message parked on the connection. Cast to a return type that becomes `0`
from a counter or `null` from a read. Every wrapper either surfaces it as an exception or
interprets it deliberately and says so in its docblock.

## Examples must run

Every code sample in this directory, in the README and in the web documentation is
expected to work as written, against the Redis the tests use. Several of the sharper
observations here exist because a sample was run and did something else — the `follow()`
cursor and the `xReadGroup` block parameter both came from that.

When you change behaviour, run the samples that describe it. A documentation page that
lies is worse than one that is missing: it is believed.
