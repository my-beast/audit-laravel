<?php

declare(strict_types=1);

namespace MyBeast\Audit\Laravel\Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use MyBeast\Audit\Laravel\AuditServiceProvider;
use Orchestra\Testbench\TestCase as Testbench;

/** A host with the SDK: its own database (the outbox migrated with it), the service's URL and secret. */
abstract class TestCase extends Testbench
{
    use RefreshDatabase;

    protected const URL = 'https://audit.example.test';

    protected const SECRET = 'sk_abcdefghijklmnop.0123456789abcdefghijABCDEFGHIJ0123456789abc';

    protected function getPackageProviders($app): array
    {
        return [AuditServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('cache.default', 'array');
        $app['config']->set('mybeast-audit.url', self::URL.'/');
        $app['config']->set('mybeast-audit.secret', self::SECRET);
        // Tests send at once; a real host lets a commit settle first.
        $app['config']->set('mybeast-audit.ship.settle_seconds', 0);
    }

    /** @return list<object> the outbox, in order */
    protected function outbox(): array
    {
        return DB::table('audit_outbox')->orderBy('id')->get()->all();
    }

    /** @return list<array<string, mixed>> the events a batch request carried */
    protected static function eventsOf(Request $request): array
    {
        $body = $request->hasHeader('Content-Encoding', 'gzip') ? gzdecode($request->body()) : $request->body();

        return json_decode((string) $body, true, flags: JSON_THROW_ON_ERROR)['events'];
    }

    /**
     * The service's answer to a batch, each event's status as the test says (kept by default).
     *
     * @param  array<string, string>  $statuses  event_id => kept|duplicate|refused
     * @return array<string, mixed>
     */
    protected static function answer(Request $request, array $statuses = []): array
    {
        $results = [];

        foreach (self::eventsOf($request) as $index => $event) {
            $status = $statuses[$event['event_id']] ?? 'kept';
            $results[] = ['index' => $index, 'event_id' => $event['event_id'], 'status' => $status]
                + ($status === 'refused' ? ['reason' => 'occurred_at is in the future: check the host\'s clock.'] : ['seq' => $index + 1]);
        }

        return ['kept' => 0, 'duplicates' => 0, 'refused' => 0, 'results' => $results];
    }
}
