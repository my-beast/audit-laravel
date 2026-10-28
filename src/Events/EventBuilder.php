<?php

declare(strict_types=1);

namespace MyBeast\Audit\Laravel\Events;

use DateTimeInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Makes the event the service takes (schemas/v1/events.schema.json) and checks it as the service will. Ids only:
 * an e-mail address in an id or the context throws; one typed into a reason is taken out (the service would refuse
 * the event, and a reason is a person's words, not the code's).
 */
final class EventBuilder
{
    public const ID = '/^[A-Za-z0-9._:-]{1,64}$/';

    public const ACTION = '/^[a-z][a-z0-9]*(?:[._-][a-z0-9]+)*$/';

    public const EMAIL = '/[A-Za-z0-9._%+-]+@[A-Za-z0-9-]+(?:\.[A-Za-z0-9-]+)*\.[A-Za-z]{2,}/';

    private const MAX_CONTEXT_BYTES = 8192;

    private const MAX_CONTEXT_DEPTH = 4;

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     *
     * @throws InvalidAuditEvent
     */
    public static function build(
        string $action,
        Actor $actor,
        ?Target $target,
        array $context,
        string|int|null $subject,
        string|int|null $account,
        ?string $reason,
        ?DateTimeInterface $at,
        ?string $requestId = null,
    ): array {
        if (strlen($action) > 80 || preg_match(self::ACTION, $action) !== 1) {
            throw new InvalidAuditEvent("The action \"{$action}\" must be lower-case letters and digits joined by . _ or - (at most 80), e.g. customer.viewed.");
        }

        if ($target !== null && (strlen($target->type) > 40 || preg_match(self::ACTION, $target->type) !== 1)) {
            throw new InvalidAuditEvent("The target type \"{$target->type}\" must be lower-case letters and digits joined by . _ or - (at most 40).");
        }

        return array_filter([
            'event_id' => (string) Str::uuid7(),
            'occurred_at' => Carbon::instance($at ?? Carbon::now())->utc()->format('Y-m-d\TH:i:s.v\Z'),
            'action' => $action,
            'actor' => ['type' => $actor->type, 'id' => self::id($actor->id, 'actor')],
            'target' => $target === null ? null : ['type' => $target->type, 'id' => self::id($target->id, 'target')],
            'subject' => $subject === null ? null : self::id((string) $subject, 'subject'),
            'account' => $account === null ? null : self::id((string) $account, 'account'),
            // Not the host's own words: one that does not fit is left off, as the log lines leave it.
            'request_id' => $requestId !== null && preg_match(self::ID, $requestId) === 1 ? $requestId : null,
            'reason' => self::reason($reason),
            'context' => self::context($context),
        ], fn (mixed $value): bool => $value !== null);
    }

    private static function id(string $id, string $what): string
    {
        if (preg_match(self::EMAIL, $id) === 1) {
            throw new InvalidAuditEvent("The {$what} is an e-mail address: give your id for them - who they are, your own console says.");
        }

        if (preg_match(self::ID, $id) !== 1) {
            throw new InvalidAuditEvent("The {$what} id \"{$id}\" must be 1-64 letters, digits, . _ : or -.");
        }

        return $id;
    }

    private static function reason(?string $reason): ?string
    {
        $reason = trim((string) preg_replace(self::EMAIL, '[e-mail removed]', (string) $reason));

        return $reason === '' ? null : mb_substr($reason, 0, 500);
    }

    /** @param  array<string, mixed>  $context */
    private static function context(array $context): ?array
    {
        if ($context === []) {
            return null;
        }

        if (array_is_list($context)) {
            throw new InvalidAuditEvent('The context must be keyed (an object), not a list.');
        }

        if (self::depth($context) > self::MAX_CONTEXT_DEPTH) {
            throw new InvalidAuditEvent('The context nests too deep (at most '.self::MAX_CONTEXT_DEPTH.' levels).');
        }

        $json = json_encode($context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);

        if ($json === false) {
            throw new InvalidAuditEvent('The context cannot be written as JSON: '.json_last_error_msg());
        }

        if (strlen($json) > self::MAX_CONTEXT_BYTES) {
            throw new InvalidAuditEvent('The context is too big (at most 8 KB as JSON): keep ids and short facts, never documents.');
        }

        if (preg_match(self::EMAIL, $json) === 1) {
            throw new InvalidAuditEvent('The context holds an e-mail address: ids only, never names or e-mails.');
        }

        return json_decode($json, true);
    }

    /** @param  array<mixed>  $value */
    private static function depth(array $value): int
    {
        $deepest = 0;

        foreach ($value as $item) {
            if (is_array($item)) {
                $deepest = max($deepest, self::depth($item));
            }
        }

        return $deepest + 1;
    }
}
