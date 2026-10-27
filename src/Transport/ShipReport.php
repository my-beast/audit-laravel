<?php

declare(strict_types=1);

namespace MyBeast\Audit\Laravel\Transport;

/** What one audit:ship did. */
final readonly class ShipReport
{
    public function __construct(
        public int $kept = 0,
        public int $duplicates = 0,
        public int $refused = 0,
        // Why it stopped with events still waiting: the service down, busy, or not knowing the secret.
        public ?string $stopped = null,
        public bool $configured = true,
        public bool $busy = false,
    ) {}

    public function sent(): int
    {
        return $this->kept + $this->duplicates;
    }
}
