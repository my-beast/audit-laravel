# mybeast/audit-laravel

The Laravel SDK of MyBeast audit (services.my-beast.com/audit): your app's audit events, kept in one tamper-evident
log.
PHP ≥ 8.2, Laravel 12 and 13.

**No action without its record.** `Audit::record()` writes the event to an outbox table in your own database, in the
same transaction as the action it records: if the action rolls back, so does its record; if it commits, the record is
there to ship whatever the service is doing. `php artisan audit:ship` (every minute, on one server) sends the outbox in
order and forgets only what the service confirmed. While the service is down, nothing is lost: events wait and go,
oldest first, when it is back.

## Install

```bash
composer require mybeast/audit-laravel
php artisan migrate            # the outbox table (audit_outbox; rename it in config first if your rules say so)
```

```dotenv
MYBEAST_AUDIT_URL=https://services.my-beast.com/audit
MYBEAST_AUDIT_SECRET=sk_….…    # the project's page there: Settings → Keys → Make server secret (shown once)
```

The scheduler must run (`schedule:work`, or cron with `schedule:run`): the SDK adds `audit:ship` every minute,
`onOneServer()` - the outbox is in your shared database, so one shipper is enough. Without the URL or the secret,
events still wait in the outbox.

## Record

```php
use MyBeast\Audit\Laravel\Events\Actor;
use MyBeast\Audit\Laravel\Events\Target;
use MyBeast\Audit\Laravel\Facades\Audit;

DB::transaction(function () use ($customer, $operator, $reason) {
    // … show or change the customer …

    Audit::record('customer.viewed', Actor::operator($operator->id), Target::of('customer', $customer->id),
        ['fields' => ['iban', 'address']],
        subject: $customer->user_id,        // the person the data is about: their access report lists it
        account: $customer->workspace_id,
        reason: $reason,                    // the operator's reason, when you asked one
    );
});
```

- **Ids only, never names or e-mails.** Who an id is, your console says (the project's link templates). An e-mail in an
  id or the context throws `InvalidAuditEvent`; one typed into a reason is replaced by `[e-mail removed]`.
- `Actor::person($id)`, `Actor::operator($id)`, `Actor::system('scheduler')`; `Target::of($type, $id)`,
  `Target::person($id)` (a person as the target is in their access report too).
- `action` and a target's type: lower-case letters and digits joined by `.`, `_` or `-` (`lease.exported`).
- `context`: keyed, at most 4 levels and 8 KB as JSON. `at:` for a time other than now.
- A wrong event throws at once, in your transaction - the mistake is seen where it is made.
- The outbox row joins your transaction only on the same connection: set `MYBEAST_AUDIT_CONNECTION` if your actions run
  on another one than the default.
- **Each event carries the id of the request it was recorded in**, so its page at the service opens that request's log
  lines in Logs and its errors in Errors: `mybeast/logs-laravel`'s id when it runs (a job carries the id of the request
  that queued it), else the request's `X-Request-Id` header (`MYBEAST_AUDIT_REQUEST_ID_HEADER`, empty for none).
  `request:` names one yourself. Neither SDK needs the other.

## Ship

```bash
php artisan audit:ship                   # what the scheduler runs: send, in order
php artisan audit:ship --status          # what waits, since when, the last error, what the service refused
php artisan audit:ship --retry-refused   # refused events back in line, after fixing the cause (a clock, …)
```

| The service says | The SDK |
|---|---|
| kept, or kept already (`duplicate`) | forgets the row |
| refused, with a reason | keeps the row aside (`refused_at`, `refusal`), logs an error, never resends it as it is; the ones after it go on |
| 413 too big | sends the same events in half batches |
| 401, 429, 5xx, no answer | stops; everything waits; the next run starts again from the same oldest event |

An event is sent once it is `ship.settle_seconds` old (2): a transaction that committed late with a lower id is not
overtaken by one that committed first.

## Test

```php
$audit = Audit::fake();             // in memory: nothing written, nothing sent; a wrong event still throws

// … the code under test …

$audit->assertRecorded('customer.viewed', fn (array $event) => $event['subject'] === 'u-1');
$audit->assertRecordedTimes('customer.viewed', 1);
$audit->assertNotRecorded('key.revealed');
$audit->assertNothingRecorded();
```

`MYBEAST_AUDIT_ENABLED=false` checks events and writes nothing (a checkout without audit).

## The contract

`schemas/` is a copy of the Audit module's in services (`../../services/schemas/audit`): `v1/events.schema.json` is what
the outbox holds and sends. `SchemaCopyTest` fails when the copy differs; change both together.

```bash
composer test           # PHPUnit (Orchestra Testbench)
./vendor/bin/pint       # format
```

## Releasing

Add the version to `CHANGELOG.md`, then tag it: `git tag v0.2.0 && git push --tags`. Packagist reads the tag through
its GitHub hook; there is no version in `composer.json`. The Tests workflow runs on the tag as on every push: PHP 8.2
to 8.4 against Laravel 12 and 13 (Laravel 11 has unfixed advisories, so Composer will not install it).
