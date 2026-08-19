# 02 — Driver behaviour

Things `ext-redis` does that its documentation does not lead you to expect. Each one was
measured on the version this package targets (phpredis 6.3, Redis 8.10), and each one
shaped a piece of the code. Re-measure before trusting any of it on another version.

## It reconnects underneath you

After an explicit `close()`, and after the server kills the connection with
`CLIENT KILL`, the **next command silently opens a new socket** and succeeds. `AUTH` and
the selected database are restored.

```
close();  isConnected() → false;  ping() → true       // reconnected
CLIENT KILL;  get('k') → 'v'                           // no exception at all
SELECT 7;  CLIENT KILL;  get(...) → still database 7
```

Consequences:

- **A dropped socket is not a dead connection.** Evicting on the error text alone would
  throw away healthy connections. This is why `reportFailure()` probes.
- **The database cannot silently drift.** The restore is the driver's, not ours — but the
  test `testAClosedConnectionIsNotEvictedBecausePhpredisReopensIt` pins the behaviour so a
  driver change surfaces as a red test rather than as data in the wrong database.

## Refusals come back as `false`, with the message parked

A server refusal — `WRONGTYPE`, `ERR value is not an integer`, `ERR unknown command`,
`BUSYGROUP` — does **not** throw. The call returns `false` and the message goes into
`getLastError()`, where it stays until something clears it.

Two problems follow, and `ChecksCommandErrors` exists for both:

1. **Cast into a return type, a refusal reads as an answer.** `(int) false` is `0`, so a
   counter that refused looks like a counter that reset.
2. **The message rides the connection back into the pool.** The next borrower finds
   someone else's error in `getLastError()` and can reasonably blame its own command.

Every wrapper therefore clears the slate before the command and inspects it after —
except where one specific refusal is expected and interpreted (`BUSYGROUP` in
`ensureGroup()`, index-out-of-range in `RedisList::set()`), which is stated in the
docblock.

## `OPT_READ_TIMEOUT` kills blocking reads

A connection gives up on a silent server after `readTimeout` seconds. A blocking command
is a deliberately silent server, so the two collide:

| `OPT_READ_TIMEOUT` | `blPop(timeout: 3)` |
| --- | --- |
| `2.0` (the package default) | dies at 2.00s with `read error on connection` |
| `0` | dies immediately — zero is not "unlimited" |
| `-1` | waits correctly, returned at 3.08s |
| `5` (longer than the block) | waits correctly |

`UsesDedicatedConnection::dedicatedConnection()` sets `timeout + 1.0`, or `-1` for an
indefinite wait. Any new blocking API must do the same, and `0` is never the right value.

## The BLOCK argument is not consistent between commands

| Command | "Do not block" | Note |
| --- | --- | --- |
| `xRead` | `block = -1` | the parameter's own default |
| `xReadGroup` | **omit the argument** | `-1` returns `false` with no error to explain it |

Measured: `xReadGroup(..., block: -1)` → `false`, `getLastError()` empty; without the
argument → `[]` in 0.00s; with `300` → `[]` in 0.31s. `RedisStreamGroup::read()` therefore
passes `?int $block` and calls a different arity for `null`.

## `$` cannot be a cursor between calls

`XREAD` with `$` means "entries added after **this call** begins". A loop that passes `$`
every iteration loses everything written between two iterations — silently, since a gap in
a stream leaves no trace.

`RedisStream::follow()` resolves the position once (one `XREVRANGE` for the last
identifier, `0-0` for a stream that does not exist) and then advances through concrete
identifiers.

## Versions of stream and hash features

| Feature | Needs | Behaviour on an older server |
| --- | --- | --- |
| `HTTL`, `HPERSIST` (field lifetimes) | Redis 7.4 | `RedisFeatureException` |
| `HSETEX` (write with a field lifetime) | Redis 8.0 | `RedisFeatureException` |
| `XAUTOCLAIM` | Redis 6.2 | driver-level failure; not currently translated |
| Streams at all | Redis 5.0 | — |

The version is only looked up on the failure path, so the ordinary case pays nothing for
the better message. `hash-max-listpack-entries` defaults to **512** on Redis 8.x — it was
128 on older versions, and the web documentation quotes the measured number.

## Odds and ends worth knowing

- `auth()` accepts a string (the `default` user) or `[user, password]` for an ACL user.
  Both paths are exercised by `ConfigTest`.
- `connect()` understands a scheme in the host (`tls://`, a socket path) and takes a
  stream `$context` for TLS material. A failed TLS handshake arrives as a **PHP warning**,
  and the connection may still be considered established — verify with `ping()`.
- `Redis::ping()` returns `true` on this version and `'+PONG'` on older ones; the config
  accepts both.
- An approximate `XTRIM`/`MAXLEN ~` may remove **nothing** on a short stream. Correct, and
  surprising enough to be in the user documentation.
