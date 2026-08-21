# Winter Redis

[![Latest Version on Packagist](https://img.shields.io/packagist/v/flytachi/winter-redis.svg)](https://packagist.org/packages/flytachi/winter-redis)
[![PHP Version Require](https://img.shields.io/packagist/php-v/flytachi/winter-redis.svg?style=flat-square)](https://packagist.org/packages/flytachi/winter-redis)
[![Software License](https://img.shields.io/badge/license-MIT-brightgreen.svg)](LICENSE)

📖 **[Documentation](https://winterframe.net/docs/redis)** · [Configuration](https://winterframe.net/docs/redis-configuration) · [Stores](https://winterframe.net/docs/redis-stores) · [Streams](https://winterframe.net/docs/redis-streams)

Pooled Redis for PHP that stays resident.

A Redis connection is one socket with a sequential protocol. In classic PHP that never
mattered: a process served one request and died. A worker that lives for weeks and serves
many requests at once cannot share a socket between them, and cannot afford to open one
per request either. This package puts a pool in between, and gives the application stores
and typed handles instead of a raw client.

Built on [flytachi/winter-cpool](https://github.com/flytachi/winter-cpool): under Swoole
every coroutine gets its own connection and returns it automatically; everywhere else a
single self-maintaining connection does the same job. The calling code is identical.

---

## Installation

```bash
composer require flytachi/winter-redis
```

Requires PHP **8.4+** and `ext-redis`. `ext-swoole` is optional: with it you get real
pooling, without it the same code keeps working through one connection.

---

## Quick start

Declare an endpoint:

```php
use Flytachi\Winter\Redis\Config\RedisConfig;

class MainRedisConfig extends RedisConfig
{
    public function setUp(): void
    {
        $this->host     = getenv('REDIS_HOST') ?: 'localhost';
        $this->password = getenv('REDIS_PASS') ?: '';
    }
}
```

Declare a slice of it:

```php
use Flytachi\Winter\Redis\Store\RedisStore;

class SessionStore extends RedisStore
{
    protected string $redisConfigClassName = MainRedisConfig::class;
    protected string $prefix               = 'session:';
}
```

Use it:

```php
$sessions = new SessionStore();

$sessions->set('42', $token, ttl: 3600);
$sessions->get('42');                       // $token | null
$sessions->increment('logins');             // atomic
$sessions->keys();                          // ['logins', '42'] — SCAN order, not insertion
$sessions->flush();                         // this store only, never the database
```

No connection to obtain, none to return, no `select()`. The connection is taken on the
first command and goes back to the pool when the request ends.

---

## What you get from it

**A connection per unit of work.** Two coroutines never share a socket, so commands never
interleave. The return is automatic — a coroutine `defer`, not a `finally` you have to
remember.

**A bounded number of connections.** A thousand concurrent requests do not become a
thousand connections: they queue, and a request fails on a timeout instead of the database
answering `max number of clients reached`.

**Connections that heal.** An idle connection is probed before it is handed over, an aged
one is rotated. A Redis restart stops poisoning the worker for the rest of its life.

**A key prefix that means something.** Several stores share one database without
colliding, `keys()` and `flush()` are scoped to the store, and `flush()` refuses outright
when there is no prefix to scope it to.

**Refusals that look like refusals.** phpredis reports a rejected command by returning
`false` and parking the message on the connection; cast into a return type that becomes a
counter reading `0`. Here it is an exception, and the connection goes back clean.

---

## A taste of the API

```php
// strings, counters, keys
$store->set('key', $value, ttl: 60);   $store->ttl('key');
$store->increment('hits', 5);          $store->keys('user:*');

// hashes — fields, with their own lifetimes on Redis 8.0+
$cart = $store->hash('cart:42');
$cart->setAll(['qty' => '2', 'sku' => 'A-1']);
$cart->set('lock', '1', ttl: 30);      // HSETEX: the field expires, not the hash
$cart->increment('qty');               $cart->all();

// lists — queues, capped logs, blocking reads on their own connection
$jobs = $store->list('jobs');
$jobs->push($payload, cap: 10_000);    $jobs->consume(timeout: 5);
$jobs->moveTo($processing);            // LMOVE: never in neither, never in both

// streams — history, and delivery tracking the server keeps
$events = $store->stream('events');
$events->add(['type' => 'signup'], cap: 100_000);
$events->ensureGroup('mailers');

$group = $events->group('mailers', consumer: 'worker-1');
foreach ($group->consume(count: 10, timeout: 5) as $entry) {
    $this->handle($entry);
    $group->ack($entry);               // acknowledging is never automatic
}
foreach ($group->claimStale(idle: 60_000) as $entry) { /* a worker died holding these */ }

// anything not wrapped
$store->raw()->zAdd($store->key('leaders'), 100, 'user:1');
```

---

## Fork safety and shutdown

```php
use Flytachi\Winter\Kernel\Process\ForkReset;
use Flytachi\Winter\Redis\RedisPool;

RedisPool::setLogger(LoggerFactory::getLogger(RedisPool::class));
ForkReset::register(static fn() => RedisPool::reset());
```

`reset()` forgets inherited connections **without closing them** — a fork copies
descriptors, and closing would tear down the parent's socket. `shutdown()` is the opposite
and belongs at worker exit; it is only required if background housekeeping is enabled,
because a live timer keeps the reactor from draining.

Under the [Winter kernel](https://github.com/flytachi/winter-kernel) both lines are already
there: `Kernel::init()` registers them when it sees the package, and `shutdown()` runs on
`workerExit`. The snippet above is for using this package on its own.

---

## Observability

```php
RedisPool::stats();
// ['Main\Configurations\MainRedisConfig' => ['total' => 4, 'idle' => 3, 'active' => 1, 'maximum' => 10]]

$config->pingDetail();   // ['status' => true, 'latency' => 0.32, 'error' => null]
```

The numbers are per worker: each worker holds its own pool. `active` sitting at `maximum`
while borrowers wait is the signal to raise the ceiling — or to find the request holding a
connection while it waits on something else.

`stats()` reports coroutine pools, so outside Swoole it is empty by design: there is one
self-maintaining connection per config and nothing to distribute. `pingDetail()` answers
in both runtimes and is what a health endpoint should call.

---

## Documentation

The user-facing documentation lives at
**[winterframe.net/docs/redis](https://winterframe.net/docs/redis)** (the link picks your
language; RU and EN are both complete).

**Start here**

| Page | What it answers |
|------|-----------------|
| [Redis](https://winterframe.net/docs/redis) | Why a resident application needs a pool, and what this package is made of |
| [Configuration](https://winterframe.net/docs/redis-configuration) | Endpoints, databases, timeouts, TLS, ACL users, serialization, pool size |
| [Stores](https://winterframe.net/docs/redis-stores) | Prefixes, values, lifetimes, `keys()`/`flush()`, `raw()`, transactions |

**Structures**

| Page | What it answers |
|------|-----------------|
| [Hashes](https://winterframe.net/docs/redis-hashes) | Fields, per-field lifetimes and the server version they need |
| [Lists](https://winterframe.net/docs/redis-lists) | Queues and stacks, capped logs, blocking reads, `LMOVE` recovery |
| [Streams](https://winterframe.net/docs/redis-streams) | Event logs, consumer groups, pending entries, claiming stuck work |

**Operating it**

| Page | What it answers |
|------|-----------------|
| [Connection pool](https://winterframe.net/docs/redis-pooling) | How connections are handed out, how to size the pool, what each error means |
| [CPool](https://winterframe.net/packages/cpool) | The pool underneath, if you need to plug another driver into it |

---

## Contributing

Internal technical notes — the connection model, the driver behaviours the code works
around, and the rules for adding a structure — live in [`docs/`](docs/README.md). Read
those before changing how a connection is obtained or returned.

```bash
XDEBUG_MODE=off composer test   # phpunit against a live Redis; skips when there is none
composer test-detail            # phpunit --testdox
composer cs-check               # phpcs
composer cs-fix                 # phpcbf
```

`XDEBUG_MODE=off` matters: Xdebug's function observers do not survive coroutine stacks, so
a green run can still exit 139.

- Setup, the testing philosophy and the documentation rules: [CONTRIBUTING.md](CONTRIBUTING.md)
- Changes and upgrade notes: [CHANGELOG.md](CHANGELOG.md)
- Reporting a vulnerability, and what the package does and does not guarantee: [SECURITY.md](SECURITY.md)

---

## License

MIT License. See [LICENSE](LICENSE).
