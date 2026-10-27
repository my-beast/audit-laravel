<?php

declare(strict_types=1);

namespace MyBeast\Audit\Laravel;

/** Where Audit::record() puts an event: the outbox (Outbox\OutboxRecorder), or memory under Audit::fake(). */
interface Recorder
{
    /** @param  array<string, mixed>  $event  as the service takes it (schemas/v1/events.schema.json, one of `events`) */
    public function put(array $event): void;
}
