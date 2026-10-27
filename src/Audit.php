<?php

declare(strict_types=1);

namespace MyBeast\Audit\Laravel;

use DateTimeInterface;
use Illuminate\Contracts\Container\Container;
use MyBeast\Audit\Laravel\Events\Actor;
use MyBeast\Audit\Laravel\Events\EventBuilder;
use MyBeast\Audit\Laravel\Events\InvalidAuditEvent;
use MyBeast\Audit\Laravel\Events\Target;
use MyBeast\Audit\Laravel\Outbox\Outbox;
use MyBeast\Audit\Laravel\Testing\AuditFake;
use MyBeast\Audit\Laravel\Transport\Shipper;
use MyBeast\Audit\Laravel\Transport\ShipReport;

/**
 * The SDK's one door (the Audit facade):
 *
 *     DB::transaction(function () use ($customer, $operator, $reason) {
 *         // … show or change the customer …
 *         Audit::record('customer.viewed', Actor::operator($operator->id), Target::of('customer', $customer->id),
 *             ['fields' => ['iban']], subject: $customer->user_id, account: $customer->workspace_id, reason: $reason);
 *     });
 *
 * The event joins the outbox in the same transaction: if the action rolls back, so does its record; if it commits,
 * the record is there to ship, whatever the service is doing.
 */
final readonly class Audit
{
    public function __construct(
        private Container $app,
    ) {}

    /**
     * @param  array<string, mixed>  $context  ids and short facts, never names or e-mails
     * @param  string|int|null  $subject  the person the data is about (their access report)
     * @param  string|null  $reason  the operator's reason, when you asked one (an e-mail address in it is taken out)
     * @return string the event's id
     *
     * @throws InvalidAuditEvent
     */
    public function record(
        string $action,
        Actor $actor,
        ?Target $target = null,
        array $context = [],
        string|int|null $subject = null,
        string|int|null $account = null,
        ?string $reason = null,
        ?DateTimeInterface $at = null,
    ): string {
        $event = EventBuilder::build($action, $actor, $target, $context, $subject, $account, $reason, $at);
        $this->app->make(Recorder::class)->put($event);

        return $event['event_id'];
    }

    /** Events kept in memory from now on, with assertions: nothing is written or sent. */
    public function fake(): AuditFake
    {
        $fake = new AuditFake;
        $this->app->instance(Recorder::class, $fake);

        return $fake;
    }

    /** What audit:ship does: the outbox to the service, in order. */
    public function ship(): ShipReport
    {
        return $this->app->make(Shipper::class)->ship();
    }

    /** @return array{waiting: int, refused: int, oldest: string|null, last_error: string|null} */
    public function status(): array
    {
        return $this->app->make(Outbox::class)->status();
    }
}
