<?php

declare(strict_types=1);

namespace MyBeast\Audit\Laravel\Events;

/**
 * What it was done to, by its kind and your id: `Target::of('customer', $customer->id)`. A person as the target
 * (`Target::person($id)`) puts the event in that person's access report.
 */
final readonly class Target
{
    public string $id;

    private function __construct(
        public string $type,
        string|int $id,
    ) {
        $this->id = (string) $id;
    }

    public static function of(string $type, string|int $id): self
    {
        return new self($type, $id);
    }

    public static function person(string|int $id): self
    {
        return new self('person', $id);
    }
}
