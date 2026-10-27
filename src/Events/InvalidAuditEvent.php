<?php

declare(strict_types=1);

namespace MyBeast\Audit\Laravel\Events;

use InvalidArgumentException;

/**
 * Audit::record() was called with an event the service would refuse (schemas/v1/events.schema.json): thrown at once,
 * in the caller's transaction, so the mistake is seen where it is made - not found in the outbox weeks later.
 */
final class InvalidAuditEvent extends InvalidArgumentException {}
