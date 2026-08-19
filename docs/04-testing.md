# 04 — Testing

## Against a live server, on purpose

The tests talk to a real Redis and skip when there is none. There is no mock layer and
there should not be one: everything worth testing here is protocol behaviour — a socket
that dies mid-request, a connection handed to a second coroutine, a `SCAN` slice that
comes back empty, a driver that reconnects without telling anyone. A double would replay
the assumptions the implementation already makes, and pass while the code was wrong.

Several real defects in this package were found by tests that could not have failed
against a mock:

- `flush()` stopped at the first empty `SCAN` slice, leaving keys behind;
- the non-coroutine path leaked a raw `PoolException` where the coroutine path wrapped it;
- `follow()` lost entries written between two iterations of a loop;
- `xReadGroup` returned `false` for a blocking argument the driver documents as valid.

## Pointing the suite somewhere

```bash
XDEBUG_MODE=off composer test                        # 127.0.0.1:6379, database 0
XDEBUG_MODE=off REDIS_TEST_PORT=6399 composer test   # elsewhere
XDEBUG_MODE=off composer test-ci                     # skips fail the run
```

`REDIS_TEST_HOST`, `REDIS_TEST_PORT`, `REDIS_TEST_DB`. `RedisTestCase` skips the whole
class when nothing answers, so a machine without Redis reports skips rather than failures.

That is the right trade on a laptop and the wrong one in a pipeline: with no server
reachable the suite runs 7 tests out of 124, makes 13 assertions and still prints `OK`.
`test-ci` adds `--fail-on-skipped` so that run exits **1** instead. The printed summary is
identical either way — the exit code is the whole difference, so a pipeline must check it
rather than grep the output.

**`XDEBUG_MODE=off` is not optional** when coroutine tests run. Xdebug's function
observers do not survive coroutine stacks: the report says `OK` and the process then exits
139. The tests were green; the process was not.

## Servers built for the test

Two situations cannot be produced against a shared Redis, so those tests bring their own
server and skip when `redis-server` is not on `PATH`.

**An outage.** `RedisOutageTest` starts a server on a spare port, stops it mid-test and
starts it again. That is the scenario the pool exists for — a database that goes away and
comes back — and the only way to prove the process heals without a restart.

**An old server.** `RedisHashFeatureTest` starts one with
`--rename-command HSETEX "" --rename-command HTTL "" --rename-command HPERSIST ""`. The
server then answers `ERR unknown command` for exactly those, which is what a pre-8.0 Redis
does. It is a faithful stand-in for a version we cannot install, and it keeps the refusal
path — the one users on older servers will hit — under test.

## What a test should pin

- **A promise from a docblock.** If the documentation says nothing is lost between calls,
  a test writes something between calls and demands it back. Both `follow()` and
  `consume()` have one.
- **Driver behaviour we depend on.** Transparent reconnect, the `false`-plus-`getLastError`
  convention, the blocking-argument shapes. When a driver upgrade changes them, the change
  should be a red test rather than a production incident.
- **The empty case.** Every reader is asked about a key that does not exist. `null`, `[]`
  or `0` — never an exception, never `false`.
- **Concurrency with real coroutines.** `Coroutine\run()` with several coroutines, not a
  simulation. The interesting failures live in the interleaving.

## Regression tests

A test added with a fix must fail **before** the fix. A test that passes against the
broken code is worse than no test: it will be trusted.

## Before publishing

```bash
XDEBUG_MODE=off composer test
composer cs-check
composer validate --no-check-publish
```

Then the documentation checks, which are not scripted in this repository but have caught
real breakage every time: every `@link` resolves through the language-agnostic redirector,
its anchor exists in **both** locales, and the RU and EN pages of the user documentation
match in structure. Anchors move when a heading is renamed, and a heading is renamed
whenever a page is improved.
