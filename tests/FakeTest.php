<?php

declare(strict_types=1);

namespace MyBeast\Audit\Laravel\Tests;

use Illuminate\Support\Facades\Http;
use MyBeast\Audit\Laravel\Events\Actor;
use MyBeast\Audit\Laravel\Events\InvalidAuditEvent;
use MyBeast\Audit\Laravel\Events\Target;
use MyBeast\Audit\Laravel\Facades\Audit;
use PHPUnit\Framework\AssertionFailedError;

/** Audit::fake() for a host's own tests: events in memory, nothing written or sent, with assertions. */
final class FakeTest extends TestCase
{
    public function test_a_faked_audit_keeps_events_in_memory_with_assertions(): void
    {
        Http::fake();
        $fake = Audit::fake();

        Audit::record('customer.viewed', Actor::operator(7), Target::of('customer', 1), ['via' => 'console'], subject: 'u-1');
        Audit::record('customer.viewed', Actor::operator(7), Target::of('customer', 2));

        $fake->assertRecorded('customer.viewed');
        $fake->assertRecorded('customer.viewed', fn (array $event): bool => ($event['subject'] ?? null) === 'u-1' && $event['context'] === ['via' => 'console']);
        $fake->assertRecordedTimes('customer.viewed', 2);
        $fake->assertNotRecorded('key.revealed');
        $this->assertSame(['1', '2'], array_column(array_column($fake->recorded('customer.viewed'), 'target'), 'id'));

        $this->assertSame([], $this->outbox());
        Http::assertNothingSent();

        $this->expectException(AssertionFailedError::class);
        $fake->assertNothingRecorded();
    }

    public function test_a_faked_audit_still_refuses_a_wrong_event(): void
    {
        Audit::fake();

        $this->expectException(InvalidAuditEvent::class);
        Audit::record('customer.viewed', Actor::person('anna@example.com'));
    }
}
