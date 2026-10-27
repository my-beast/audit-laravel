<?php

declare(strict_types=1);

namespace MyBeast\Audit\Laravel\Outbox;

use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use MyBeast\Audit\Laravel\Support\Settings;

/**
 * The outbox table: events waiting for the service, oldest (lowest id) first. Written on the connection of the
 * actions it records, so a row commits - or rolls back - with its action.
 */
final readonly class Outbox
{
    private const MOMENT = 'Y-m-d H:i:s.v';

    public function __construct(
        private ConnectionResolverInterface $db,
        private Settings $settings,
    ) {}

    /** @param  array<string, mixed>  $event */
    public function put(array $event): void
    {
        $this->table()->insert([
            'event_id' => $event['event_id'],
            'payload' => json_encode($event, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR),
            'created_at' => Carbon::now()->utc()->format(self::MOMENT),
            'attempts' => 0,
        ]);
    }

    /**
     * The next events to send: not refused, and older than the settle time, in order.
     *
     * @return list<OutboxRow>
     */
    public function waiting(int $limit, Carbon $settledBefore): array
    {
        return $this->table()
            ->whereNull('refused_at')
            ->where('created_at', '<=', $settledBefore->copy()->utc()->format(self::MOMENT))
            ->orderBy('id')
            ->limit($limit)
            ->get(['id', 'event_id', 'payload'])
            ->map(fn (object $row): OutboxRow => new OutboxRow((int) $row->id, (string) $row->event_id, (string) $row->payload))
            ->all();
    }

    /** The service has them: they go. @param  list<int>  $ids */
    public function forget(array $ids): void
    {
        if ($ids !== []) {
            $this->table()->whereIn('id', $ids)->delete();
        }
    }

    /** The service will never take it as it is: kept aside, with the reason, for a person to look at. */
    public function refuse(int $id, string $reason): void
    {
        $this->table()->where('id', $id)->update([
            'refused_at' => Carbon::now()->utc()->format(self::MOMENT),
            'refusal' => mb_substr($reason, 0, 500),
        ]);
    }

    /** A try that did not get through: they wait for the next one. @param  list<int>  $ids */
    public function tried(array $ids, string $error): void
    {
        if ($ids === []) {
            return;
        }

        $this->table()->whereIn('id', $ids)->update([
            'attempts' => $this->db->connection($this->settings->connection())->raw('attempts + 1'),
            'last_attempt_at' => Carbon::now()->utc()->format(self::MOMENT),
            'last_error' => mb_substr($error, 0, 500),
        ]);
    }

    /** Refused events back in line, in their old place (after the cause is fixed). */
    public function retryRefused(): int
    {
        return $this->table()->whereNotNull('refused_at')->update(['refused_at' => null, 'refusal' => null]);
    }

    /** @return array{waiting: int, refused: int, oldest: string|null, last_error: string|null} */
    public function status(): array
    {
        $oldest = $this->table()->whereNull('refused_at')->orderBy('id')->first(['created_at', 'last_error']);

        return [
            'waiting' => $this->table()->whereNull('refused_at')->count(),
            'refused' => $this->table()->whereNotNull('refused_at')->count(),
            'oldest' => $oldest?->created_at,
            'last_error' => $oldest?->last_error,
        ];
    }

    /** @return list<array{action: string, refusal: string}> */
    public function refused(int $limit = 10): array
    {
        return $this->table()->whereNotNull('refused_at')->orderBy('id')->limit($limit)->get(['payload', 'refusal'])
            ->map(fn (object $row): array => [
                'action' => (string) (json_decode((string) $row->payload, true)['action'] ?? '?'),
                'refusal' => (string) $row->refusal,
            ])->all();
    }

    private function table(): Builder
    {
        return $this->db->connection($this->settings->connection())->table($this->settings->table());
    }
}
