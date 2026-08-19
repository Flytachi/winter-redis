# Contributing

Thanks for taking the time. This package is small and the rules are few, but two of them
are unusual enough to state up front: the tests need a real Redis, and behavioural claims
need a measurement.

## Environment

| Requirement | For | Why |
| --- | --- | --- |
| PHP **8.4+** | using and developing | the package's floor |
| `ext-redis` | using and developing | the driver |
| `ext-swoole` | **developing** | concurrency is tested with live coroutines |
| `redis-server` on `PATH` | **developing** | two test classes start their own server |

`ext-swoole` is deliberately absent from `require`: the package works without it through a
single self-maintaining connection. Developing it is another matter — the coroutine tests
cannot run at all without the extension, and they cover the part most likely to break.

## Running the checks

```bash
XDEBUG_MODE=off composer test        # phpunit
XDEBUG_MODE=off composer test-ci     # the same, but skips fail the run
composer test-detail                 # phpunit --testdox
composer cs-check                    # phpcs, PSR-12
composer cs-fix                      # phpcbf
composer validate --no-check-publish
```

Point the suite at another server with `REDIS_TEST_HOST`, `REDIS_TEST_PORT`,
`REDIS_TEST_DB`. With no Redis answering, the suite skips instead of failing — which is
right for a laptop and wrong for CI, where a run that tested nothing would report success.

`test-ci` is the same suite with `--fail-on-skipped`, and it is what a pipeline should
call. Watch the exit code rather than the summary: with everything skipped the text still
reads `OK, but some tests were skipped!` while the process exits **1**.

```
no server, composer test      → 124 tests, 13 assertions, 117 skipped, exit 0
no server, composer test-ci   → the same output,                        exit 1
with a server                 → 124 tests, 385 assertions,              exit 0
```

The seven that run without a server are the two classes that start one themselves.

### `XDEBUG_MODE=off` is not optional

Xdebug's function observers do not survive coroutine stacks. The symptom is confusing:
every test passes, the report says `OK`, and the process then exits **139**. The tests were
green; the process was not. Set the variable, or accept a segfault you will spend an hour
attributing to Swoole.

## Tests

**Against a live server, and no mocks.** Everything worth testing here is protocol
behaviour: a socket that dies mid-request, a connection handed to a second coroutine, a
`SCAN` slice that comes back empty, a driver that reconnects without telling anyone. A
double replays the assumptions the implementation already makes, and passes while the code
is wrong. Four real defects in this package were found by tests that a mock could not have
failed.

**Concurrency with real coroutines.** `Coroutine\run()` with several coroutines, not a
simulation of one. The interesting failures live in the interleaving.

**A regression test must fail before the fix.** Check it: comment out the fix and watch it
go red. A test that passes against the broken code is worse than no test, because it will
be trusted.

**Every reader is asked about a key that does not exist.** The answer is `null`, `[]` or
`0` — never an exception, never `false`.

**A promise in a docblock deserves a test.** If the documentation says nothing is lost
between two calls, write something between two calls and demand it back. That is exactly
how the `follow()` cursor bug was found — after the docblock had already promised
otherwise.

Two test classes start a disposable `redis-server` because the situation cannot be
produced otherwise: `RedisOutageTest` stops the server mid-test, and
`RedisHashFeatureTest` starts one with the newer commands renamed away, which is a
faithful stand-in for a Redis older than 8.0.

## Claims about the driver need a measurement

`ext-redis` does several things its documentation does not lead you to expect — it
reconnects silently, it reports refusals as `false` with the message parked on the
connection, and its `BLOCK` argument does not mean the same thing in `xRead` and
`xReadGroup`. All of that is written down in
[`docs/02-driver-behaviour.md`](docs/02-driver-behaviour.md), with the numbers.

If a change rests on the driver behaving some way, measure it and add the result there.
"The documentation says" is not evidence; this package exists partly because that turned
out to be true four separate times.

## Documentation

- **Internal notes** live in [`docs/`](docs/README.md) — the connection model, driver
  behaviour, the rules for handles, testing. They are for people changing the code.
- **User documentation** lives in the framework docs site under `docs/redis*` and is
  written in a reference format: every method with its arguments, return values and
  examples. Both RU and EN pages must be kept in step.
- **`@link` in code** points at the language-agnostic URL without a version
  (`https://winterframe.net/docs/redis-lists#push`). An anchor may only be used when it is
  **identical in both locales** — translated headings produce different slugs, and the link
  would break in exactly one language.

Every example is expected to run as written. Several of the sharper notes in the
documentation exist because an example was executed and did something else.

## Style

PSR-12, enforced by phpcs; `composer cs-fix` settles the mechanical part. Beyond that:

- **Comments explain the decision, not the syntax.** Why a probe instead of matching an
  error message; why the position is resolved instead of using `$`. What the line does is
  visible.
- **Docblocks in English**, one space between type, name and description.
- **A refusal is never returned as an answer.** New wrappers either surface a server
  refusal as an exception or interpret one specific case deliberately and say so.
