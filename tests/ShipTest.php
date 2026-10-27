<?php

declare(strict_types=1);

namespace MyBeast\Audit\Laravel\Tests;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use MyBeast\Audit\Laravel\Events\Actor;
use MyBeast\Audit\Laravel\Events\Target;
use MyBeast\Audit\Laravel\Facades\Audit;

/** audit:ship: the outbox to the service in order, forgetting only what it confirmed - and nothing lost while it is down. */
final class ShipTest extends TestCase
{
    public function test_events_arrive_in_order_and_leave_the_outbox(): void
    {
        $ids = $this->record(3);
        $sent = [];
        Http::fake([self::URL.'/v1/events' => function (Request $request) use (&$sent) {
            $sent[] = $request;

            return Http::response(self::answer($request));
        }]);

        $this->artisan('audit:ship')->expectsOutputToContain('Sent 3 event(s). 0 waiting.')->assertSuccessful();

        $this->assertCount(1, $sent);
        $this->assertSame('Bearer '.self::SECRET, $sent[0]->header('Authorization')[0]);
        $this->assertSame($ids, array_column(self::eventsOf($sent[0]), 'event_id'));
        $this->assertSame([], $this->outbox());
    }

    public function test_nothing_is_lost_while_the_service_is_down_and_the_order_holds_after(): void
    {
        $first = $this->record(2);
        $down = true;
        $arrived = [];
        Http::fake([self::URL.'/v1/events' => function (Request $request) use (&$down, &$arrived) {
            if ($down) {
                throw new ConnectionException('Connection refused');
            }

            $arrived = [...$arrived, ...array_column(self::eventsOf($request), 'event_id')];

            return Http::response(self::answer($request));
        }]);

        $this->artisan('audit:ship')->expectsOutputToContain('The audit service could not be reached')->assertFailed();
        $later = $this->record(2);
        $this->artisan('audit:ship')->assertFailed();

        $rows = $this->outbox();
        $this->assertCount(4, $rows);
        $this->assertSame([2, 2, 1, 1], array_map(fn (object $row): int => (int) $row->attempts, $rows));
        $this->assertStringContainsString('Connection refused', (string) $rows[0]->last_error);

        // The service is back: everything arrives, oldest first.
        $down = false;
        $this->artisan('audit:ship')->expectsOutputToContain('Sent 4 event(s)')->assertSuccessful();
        $this->assertSame([...$first, ...$later], $arrived);
        $this->assertSame([], $this->outbox());
    }

    public function test_a_busy_or_unknowing_service_keeps_everything_waiting(): void
    {
        $this->record(2);
        $status = 503;
        Http::fake([self::URL.'/v1/events' => function () use (&$status) {
            return Http::response(['message' => 'no'], $status);
        }]);

        foreach ([[503, 'The service answered 503: no'], [429, 'busy (429)'], [401, 'does not know this secret']] as [$status, $said]) {
            $this->artisan('audit:ship')->expectsOutputToContain($said)->assertFailed();
            $this->assertCount(2, $this->outbox());
        }
    }

    public function test_a_refused_event_is_set_aside_and_the_others_go_on(): void
    {
        [$good, $bad, $after] = $this->record(3);
        $calls = 0;
        Http::fake([self::URL.'/v1/events' => function (Request $request) use ($bad, &$calls) {
            $calls++;

            return Http::response(self::answer($request, [$bad => 'refused']));
        }]);

        $this->artisan('audit:ship')->expectsOutputToContain('Sent 2 event(s); 1 refused')->assertSuccessful();

        $rows = $this->outbox();
        $this->assertSame([$bad], array_column($rows, 'event_id'));
        $this->assertNotNull($rows[0]->refused_at);
        $this->assertStringContainsString('future', (string) $rows[0]->refusal);

        // Never sent again as it is; shown; back in line on request.
        $this->artisan('audit:ship')->assertSuccessful();
        $this->assertSame(1, $calls);
        $this->artisan('audit:ship', ['--status' => true])->expectsOutputToContain('1 refused by the service:')->expectsOutputToContain('customer.viewed: occurred_at is in the future')->assertSuccessful();
        $this->artisan('audit:ship', ['--retry-refused' => true])->expectsOutputToContain('1 refused event(s) back in line.')->assertSuccessful();
        $this->assertSame(2, $calls);
    }

    public function test_a_kept_already_event_is_forgotten_too(): void
    {
        [$first, $second] = $this->record(2);
        Http::fake([self::URL.'/v1/events' => fn (Request $request) => Http::response(self::answer($request, [$first => 'duplicate']))]);

        $this->artisan('audit:ship')->expectsOutputToContain('Sent 2 event(s) (1 were there already)')->assertSuccessful();
        $this->assertSame([], $this->outbox());
    }

    public function test_batches_go_one_after_another_and_a_too_big_one_is_halved(): void
    {
        config(['mybeast-audit.ship.batch' => 4]);
        $ids = $this->record(10);
        $sizes = [];
        $arrived = [];
        Http::fake([self::URL.'/v1/events' => function (Request $request) use (&$sizes, &$arrived) {
            $events = self::eventsOf($request);
            $sizes[] = count($events);

            if (count($events) > 3) {
                return Http::response(['message' => 'too big'], 413);
            }

            $arrived = [...$arrived, ...array_column($events, 'event_id')];

            return Http::response(self::answer($request));
        }]);

        $this->artisan('audit:ship')->assertSuccessful();

        $this->assertSame([4, 2, 2, 2, 2, 2], $sizes);
        $this->assertSame($ids, $arrived);
        $this->assertSame([], $this->outbox());
    }

    public function test_an_event_waits_until_its_transaction_had_time_to_settle(): void
    {
        config(['mybeast-audit.ship.settle_seconds' => 5]);
        Http::fake([self::URL.'/v1/events' => fn (Request $request) => Http::response(self::answer($request))]);
        $this->record(1);

        $this->artisan('audit:ship')->expectsOutputToContain('Sent 0 event(s). 1 waiting.')->assertSuccessful();
        Carbon::setTestNow(Carbon::now()->addSeconds(6));
        $this->artisan('audit:ship')->expectsOutputToContain('Sent 1 event(s). 0 waiting.')->assertSuccessful();
    }

    public function test_one_shipper_at_a_time(): void
    {
        Http::fake();
        $this->record(1);
        $lock = Cache::lock('mybeast-audit:ship', 60);
        $lock->get();

        $this->artisan('audit:ship')->expectsOutputToContain('Another audit:ship is running.')->assertSuccessful();
        Http::assertNothingSent();
        $lock->release();
    }

    public function test_the_scheduler_ships_every_minute_on_one_server(): void
    {
        $event = collect(app(Schedule::class)->events())
            ->first(fn ($event): bool => str_contains((string) $event->command, 'audit:ship'));

        $this->assertNotNull($event);
        $this->assertSame('* * * * *', $event->expression);
        $this->assertTrue($event->onOneServer);
        $this->assertTrue($event->withoutOverlapping);
    }

    /** @return list<string> the event ids, in the order recorded */
    private function record(int $count): array
    {
        return array_map(fn (int $i): string => Audit::record('customer.viewed', Actor::operator('op-1'), Target::of('customer', $i)), range(1, $count));
    }
}
