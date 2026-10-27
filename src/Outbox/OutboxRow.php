<?php

declare(strict_types=1);

namespace MyBeast\Audit\Laravel\Outbox;

/** One waiting event: its outbox id (the order), its event id, and the event as it is sent. */
final readonly class OutboxRow
{
    public function __construct(
        public int $id,
        public string $eventId,
        public string $payload,
    ) {}
}
