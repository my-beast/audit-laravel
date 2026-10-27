<?php

declare(strict_types=1);

namespace MyBeast\Audit\Laravel\Outbox;

use MyBeast\Audit\Laravel\Recorder;
use MyBeast\Audit\Laravel\Support\Settings;

/** Audit::record() for real: the event into the outbox, in the caller's transaction (when it is on the same connection). */
final readonly class OutboxRecorder implements Recorder
{
    public function __construct(
        private Outbox $outbox,
        private Settings $settings,
    ) {}

    public function put(array $event): void
    {
        if ($this->settings->enabled()) {
            $this->outbox->put($event);
        }
    }
}
