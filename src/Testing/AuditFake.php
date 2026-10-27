<?php

declare(strict_types=1);

namespace MyBeast\Audit\Laravel\Testing;

use Closure;
use MyBeast\Audit\Laravel\Recorder;
use PHPUnit\Framework\Assert;

/**
 * Audit::fake(): events kept in memory instead of the outbox - nothing written, nothing sent - with assertions. An
 * event is still checked as the service would (an invalid one throws), and is the array the service would get.
 */
final class AuditFake implements Recorder
{
    /** @var list<array<string, mixed>> */
    public array $events = [];

    public function put(array $event): void
    {
        $this->events[] = $event;
    }

    /**
     * @param  (Closure(array<string, mixed>): bool)|null  $matching
     * @return list<array<string, mixed>>
     */
    public function recorded(?string $action = null, ?Closure $matching = null): array
    {
        return array_values(array_filter($this->events, fn (array $event): bool => ($action === null || $event['action'] === $action)
            && ($matching === null || $matching($event))));
    }

    /** @param  (Closure(array<string, mixed>): bool)|null  $matching */
    public function assertRecorded(string $action, ?Closure $matching = null): void
    {
        Assert::assertNotEmpty($this->recorded($action, $matching), "No audit event {$action}".($matching === null ? '' : ' matched').'.');
    }

    public function assertRecordedTimes(string $action, int $times): void
    {
        Assert::assertCount($times, $this->recorded($action), "Audit event {$action} was not recorded {$times} time(s).");
    }

    /** @param  (Closure(array<string, mixed>): bool)|null  $matching */
    public function assertNotRecorded(string $action, ?Closure $matching = null): void
    {
        Assert::assertEmpty($this->recorded($action, $matching), "Audit event {$action} was recorded.");
    }

    public function assertNothingRecorded(): void
    {
        Assert::assertEmpty($this->events, count($this->events).' audit event(s) were recorded.');
    }
}
