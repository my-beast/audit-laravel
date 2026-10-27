<?php

declare(strict_types=1);

namespace MyBeast\Audit\Laravel\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static string record(string $action, \MyBeast\Audit\Laravel\Events\Actor $actor, ?\MyBeast\Audit\Laravel\Events\Target $target = null, array $context = [], string|int|null $subject = null, string|int|null $account = null, ?string $reason = null, ?\DateTimeInterface $at = null)
 * @method static \MyBeast\Audit\Laravel\Testing\AuditFake fake()
 * @method static \MyBeast\Audit\Laravel\Transport\ShipReport ship()
 * @method static array{waiting: int, refused: int, oldest: string|null, last_error: string|null} status()
 *
 * @see \MyBeast\Audit\Laravel\Audit
 */
final class Audit extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \MyBeast\Audit\Laravel\Audit::class;
    }
}
