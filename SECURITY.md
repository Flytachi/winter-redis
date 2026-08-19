# Security Policy

## Supported versions

| Version | Supported |
| --- | --- |
| 1.x | ✅ |

## Reporting a vulnerability

Please report privately, not through a public issue: **jasur.rakhmatov03@gmail.com**.
Include the version, a description and — if you have one — a reproducing snippet. You will
get an acknowledgement within a few days.

## Security model

The package's job is connections and their state. That shapes what it can and cannot
promise.

### What it guarantees

**Credentials do not leak into diagnostics.** `getDsn()` is built from host, port and
database only; neither the password nor the ACL user appears in it, and that is the string
the pool logs. Nothing else in the package writes credentials anywhere.

**A connection carries no state between borrowers.** The database is fixed by the config
and never switched at runtime, so a connection cannot arrive at the next request pointing
somewhere else. Driver errors are cleared on both sides of the calls that read them, so
one request's failure is not visible to the next.

**Concurrent requests never share a socket.** Under Swoole each coroutine borrows its own
connection and returns it; two requests cannot read each other's replies.

### What the caller is responsible for

**The key prefix is isolation, not a boundary.** Two stores on one database do not collide,
but nothing stops either of them from reaching the other's keys through `raw()`. If two
parts of a system must not see each other's data, separate them with an ACL user or a
server — not with a prefix.

**`SERIALIZER_PHP` deserializes whatever is in the value.** With that serializer the value
on the wire is PHP's own format (`a:1:{s:1:"a";i:1;}`), and reading it calls
`unserialize()`. Anyone able to write those keys can therefore hand your process arbitrary
objects to instantiate — the classic object-injection route to code execution. Use it only
where every writer is your own code; prefer `SERIALIZER_JSON` when that is not certain,
and never point it at a Redis shared with something you do not control.

**Dedicated connections are outside the pool's ceiling.** `RedisPool::dedicated()` and the
blocking APIs built on it (`consume()`, `follow()`) open connections the pool neither
counts nor bounds. A consumer started per request rather than per worker will exhaust the
server's `maxclients` — a self-inflicted denial of service that the pool cannot prevent
because it never sees those connections. Take one per loop, close it when the loop ends.

**A blocking read with no timeout waits forever.** `consume()` and `follow()` with
`timeout: 0` set the connection's read timeout to unlimited, by necessity. A server that
accepts the connection and then goes silent will hold that consumer indefinitely. Give
long-running consumers a timeout and let the loop decide.

**Untrusted input is still untrusted.** The package passes values through unchanged. Key
names built from user input can collide with your own namespace or with another store's;
validate them where they enter, as you would with a filesystem path.

**Transport security is the config's business.** TLS is available through the host scheme
and `$context`, but a failed handshake arrives from the driver as a **PHP warning** while
the connection may still be considered established. Verify TLS with an explicit `ping()`
when deploying, rather than concluding from the absence of exceptions.

### What it is not

Not a trust boundary, not an authorisation layer, not an audit log. It authenticates as
whoever the config says and executes what the caller asks. Access control belongs in
Redis ACLs and in the network between the application and the server.
