<?php

declare(strict_types=1);

namespace MyBeast\Audit\Laravel\Transport;

use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use MyBeast\Audit\Laravel\Outbox\Outbox;
use MyBeast\Audit\Laravel\Outbox\OutboxRow;
use MyBeast\Audit\Laravel\Support\Settings;

/**
 * Sends the outbox to the service in order (audit:ship), one batch at a time, and forgets only what the service
 * confirmed - kept, or kept already. When the service cannot be reached, is busy or refuses the whole batch, the run
 * stops there: everything waits, and the next run starts again from the same oldest event, so nothing is lost and
 * nothing overtakes. An event refused on its own is set aside with its reason; the ones after it go on.
 */
final readonly class Shipper
{
    private const LOCK = 'mybeast-audit:ship';

    public function __construct(
        private Outbox $outbox,
        private ?ServiceClient $client,
        private Settings $settings,
        private Cache $cache,
    ) {}

    public function ship(): ShipReport
    {
        if ($this->client === null) {
            return new ShipReport(configured: false);
        }

        // One shipper at a time, across servers when the cache is shared: two would send the same events.
        $lock = $this->cache->lock(self::LOCK, 600);

        if (! $lock->get()) {
            return new ShipReport(busy: true);
        }

        try {
            return $this->run();
        } finally {
            $lock->release();
        }
    }

    private function run(): ShipReport
    {
        $kept = $duplicates = $refused = 0;
        $batch = $this->settings->batch();
        $settledBefore = Carbon::now()->subSeconds($this->settings->settleSeconds());

        for ($sent = 0; $sent < $this->settings->maxBatches(); $sent++) {
            $rows = $this->outbox->waiting($batch, $settledBefore);

            if ($rows === []) {
                break;
            }

            try {
                $response = $this->client->send(array_map(fn (OutboxRow $row): string => $row->payload, $rows));
            } catch (TransportFailed $e) {
                return $this->stop($rows, $e->getMessage(), $kept, $duplicates, $refused);
            }

            if ($response->status() === 413 && count($rows) > 1) {
                // The same events again, in half the batch.
                $batch = max(1, intdiv(count($rows), 2));
                $sent--;

                continue;
            }

            if ($response->status() === 413) {
                $this->outbox->refuse($rows[0]->id, 'Too big for the service, even alone.');
                $refused++;

                continue;
            }

            if (! $response->successful()) {
                return $this->stop($rows, $this->why($response), $kept, $duplicates, $refused);
            }

            $done = $this->settle($rows, $response);

            if ($done === null) {
                return $this->stop($rows, 'The service answered without a result for each event.', $kept, $duplicates, $refused);
            }

            [$k, $d, $r] = $done;
            $kept += $k;
            $duplicates += $d;
            $refused += $r;
        }

        return new ShipReport($kept, $duplicates, $refused);
    }

    /**
     * What the service said of each event: kept and duplicate go, refused is set aside.
     *
     * @param  list<OutboxRow>  $rows
     * @return array{0: int, 1: int, 2: int}|null
     */
    private function settle(array $rows, Response $response): ?array
    {
        $results = $response->json('results');

        if (! is_array($results) || count($results) !== count($rows)) {
            return null;
        }

        $forget = [];
        $kept = $duplicates = $refused = 0;

        foreach ($results as $result) {
            $index = is_array($result) && is_int($result['index'] ?? null) ? $result['index'] : null;
            $row = $index === null ? null : ($rows[$index] ?? null);

            if ($row === null || ($result['event_id'] ?? $row->eventId) !== $row->eventId) {
                return null;
            }

            $status = $result['status'] ?? null;

            if ($status === 'kept' || $status === 'duplicate') {
                $forget[] = $row->id;
                $status === 'kept' ? $kept++ : $duplicates++;
            } elseif ($status === 'refused') {
                $this->refused($row, (string) ($result['reason'] ?? 'refused'));
                $refused++;
            }
        }

        $this->outbox->forget($forget);

        return [$kept, $duplicates, $refused];
    }

    private function refused(OutboxRow $row, string $reason): void
    {
        $this->outbox->refuse($row->id, $reason);

        Log::error('My Beast audit: the service refused an audit event; it is kept aside in the outbox (audit:ship --status)', [
            'event_id' => $row->eventId,
            'action' => json_decode($row->payload, true)['action'] ?? null,
            'reason' => $reason,
        ]);
    }

    /** @param  list<OutboxRow>  $rows */
    private function stop(array $rows, string $why, int $kept, int $duplicates, int $refused): ShipReport
    {
        $this->outbox->tried(array_map(fn (OutboxRow $row): int => $row->id, $rows), $why);
        Log::warning('My Beast audit: events wait in the outbox', ['reason' => $why]);

        return new ShipReport($kept, $duplicates, $refused, stopped: $why);
    }

    private function why(Response $response): string
    {
        $message = $response->json('message');

        return match (true) {
            $response->status() === 401 => 'The service does not know this secret (401): check MYBEAST_AUDIT_SECRET.',
            $response->status() === 429 => 'The service is busy (429).',
            default => "The service answered {$response->status()}".(is_string($message) ? ': '.mb_substr($message, 0, 200) : '.'),
        };
    }
}
