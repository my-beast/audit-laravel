<?php

declare(strict_types=1);

namespace MyBeast\Audit\Laravel\Events;

/**
 * Who did it, by your id for them - never a name or an e-mail: one of your people (a customer, a tenant), one of
 * your operators (staff, support), or the system (a job, a command: `Actor::system('scheduler')`).
 */
final readonly class Actor
{
    public string $id;

    private function __construct(
        public string $type,
        string|int $id,
    ) {
        $this->id = (string) $id;
    }

    public static function person(string|int $id): self
    {
        return new self('person', $id);
    }

    public static function operator(string|int $id): self
    {
        return new self('operator', $id);
    }

    public static function system(string|int $id): self
    {
        return new self('system', $id);
    }
}
