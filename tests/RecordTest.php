<?php

declare(strict_types=1);

namespace MyBeast\Audit\Laravel\Tests;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use JsonSchema\Validator;
use MyBeast\Audit\Laravel\Events\Actor;
use MyBeast\Audit\Laravel\Events\InvalidAuditEvent;
use MyBeast\Audit\Laravel\Events\Target;
use MyBeast\Audit\Laravel\Facades\Audit;
use RuntimeException;

final class RecordTest extends TestCase
{
    public function test_an_event_is_written_to_the_outbox_as_the_service_takes_it(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-27 09:15:02.120', 'Europe/Helsinki'));

        $id = Audit::record('customer.viewed', Actor::operator(7), Target::of('customer', 4512), ['fields' => ['iban'], 'via' => 'console'],
            subject: 'u_88231', account: 301, reason: '  Ticket 7781  ');

        $row = $this->outbox()[0];
        $this->assertSame($id, $row->event_id);
        $this->assertSame(0, (int) $row->attempts);

        $event = json_decode($row->payload, true);
        $this->assertSame([
            'event_id' => $id,
            'occurred_at' => '2026-10-27T07:15:02.120Z',
            'action' => 'customer.viewed',
            'actor' => ['type' => 'operator', 'id' => '7'],
            'target' => ['type' => 'customer', 'id' => '4512'],
            'subject' => 'u_88231',
            'account' => '301',
            'reason' => 'Ticket 7781',
            'context' => ['fields' => ['iban'], 'via' => 'console'],
        ], $event);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-/', $id, 'A UUID v7: ordered by time.');

        // What the outbox holds is what the contract allows.
        $validator = new Validator;
        Audit::record('job.ran', Actor::system('scheduler'));
        $batch = json_decode('{"events":['.implode(',', array_map(fn (object $row): string => $row->payload, $this->outbox())).']}');
        $validator->validate($batch, (object) ['$ref' => 'file://'.dirname(__DIR__).'/schemas/v1/events.schema.json']);
        $this->assertTrue($validator->isValid(), json_encode($validator->getErrors()));
    }

    public function test_the_record_commits_and_rolls_back_with_the_action(): void
    {
        DB::transaction(fn () => Audit::record('lease.signed', Actor::person('u-1'), Target::of('lease', 9)));

        try {
            DB::transaction(function (): void {
                Audit::record('lease.ended', Actor::person('u-1'), Target::of('lease', 9));

                throw new RuntimeException('The action failed.');
            });
        } catch (RuntimeException) {
        }

        $this->assertSame(['lease.signed'], array_map(fn (object $row): string => json_decode($row->payload, true)['action'], $this->outbox()));
    }

    public function test_a_wrong_event_throws_where_it_is_made(): void
    {
        $wrong = [
            fn () => Audit::record('Customer Viewed', Actor::operator(1)),
            fn () => Audit::record('customer.viewed', Actor::person('anna@example.com')),
            fn () => Audit::record('customer.viewed', Actor::operator(1), Target::of('Customer', 1)),
            fn () => Audit::record('customer.viewed', Actor::operator(1), Target::of('customer', 'has spaces')),
            fn () => Audit::record('customer.viewed', Actor::operator(1), context: ['to' => 'anna@example.com']),
            fn () => Audit::record('customer.viewed', Actor::operator(1), context: ['a', 'b']),
            fn () => Audit::record('customer.viewed', Actor::operator(1), context: ['a' => ['b' => ['c' => ['d' => ['e' => 1]]]]]),
            fn () => Audit::record('customer.viewed', Actor::operator(1), context: ['blob' => str_repeat('x', 9000)]),
            fn () => Audit::record('customer.viewed', Actor::operator(1), subject: str_repeat('x', 65)),
        ];

        foreach ($wrong as $i => $call) {
            try {
                $call();
                $this->fail("Call {$i} recorded a wrong event.");
            } catch (InvalidAuditEvent) {
                $this->addToAssertionCount(1);
            }
        }

        $this->assertSame([], $this->outbox());
    }

    public function test_an_email_typed_into_a_reason_is_taken_out(): void
    {
        Audit::record('customer.viewed', Actor::operator(1), reason: 'Asked by anna.virtanen@example.com on the phone');

        $this->assertSame('Asked by [e-mail removed] on the phone', json_decode($this->outbox()[0]->payload, true)['reason']);
    }

    public function test_switched_off_it_checks_and_writes_nothing(): void
    {
        config(['mybeast-audit.enabled' => false]);

        $this->assertIsString(Audit::record('customer.viewed', Actor::operator(1)));
        $this->assertSame([], $this->outbox());

        $this->expectException(InvalidAuditEvent::class);
        Audit::record('Not An Action', Actor::operator(1));
    }

    public function test_without_the_url_or_secret_events_still_wait(): void
    {
        config(['mybeast-audit.url' => null, 'mybeast-audit.secret' => null]);

        Audit::record('customer.viewed', Actor::operator(1));

        $this->assertCount(1, $this->outbox());
        $this->artisan('audit:ship')->expectsOutputToContain('Not sending')->assertSuccessful();
        $this->assertCount(1, $this->outbox());
    }

    public function test_an_event_carries_the_id_of_the_request_it_was_recorded_in(): void
    {
        Route::get('/customers/{id}', fn (string $id): string => Audit::record('customer.viewed', Actor::operator(1), Target::of('customer', $id)));
        $requestOf = fn (int $index): ?string => json_decode($this->outbox()[$index]->payload, true)['request_id'] ?? null;

        // A proxy's X-Request-Id.
        $this->get('/customers/1', ['X-Request-Id' => '01JBX4Q2M8T0F3V6R9C2D5H7KA'])->assertOk();
        $this->assertSame('01JBX4Q2M8T0F3V6R9C2D5H7KA', $requestOf(0));

        // mybeast/logs-laravel's, when it runs: the id on the request's log lines (a job queued by it gets it too).
        Context::addHidden('mybeast_logs_request_id', 'logs-01JBX4Q2');
        $this->get('/customers/2', ['X-Request-Id' => 'from-the-proxy'])->assertOk();
        $this->assertSame('logs-01JBX4Q2', $requestOf(1));
        Context::forgetHidden('mybeast_logs_request_id');

        // One that does not fit the contract is left off; the event still goes.
        $this->get('/customers/3', ['X-Request-Id' => 'has spaces in it'])->assertOk();
        $this->assertNull($requestOf(2));

        // Named by the host itself.
        Audit::record('customer.viewed', Actor::operator(1), request: 'req-42');
        $this->assertSame('req-42', $requestOf(3));

        // No header read when the config says none, and none outside a request.
        config(['mybeast-audit.request_id_header' => '']);
        $this->get('/customers/4', ['X-Request-Id' => 'from-the-proxy'])->assertOk();
        Audit::record('job.ran', Actor::system('scheduler'));
        $this->assertNull($requestOf(4));
        $this->assertNull($requestOf(5));
    }
}
