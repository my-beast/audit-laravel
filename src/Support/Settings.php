<?php

declare(strict_types=1);

namespace MyBeast\Audit\Laravel\Support;

/**
 * Reads config('mybeast-audit') the same way everywhere. Anything missing or malformed reads as "not sending" - never
 * an exception at boot or in a request; events still wait in the outbox.
 *
 * Every key is read with a default: a host's published config may predate a key, and Laravel merges a package's
 * defaults only one level deep (a host's own `outbox` array replaces the SDK's whole).
 */
final readonly class Settings
{
    private const SERVER_SECRET = '/^sk_[a-z0-9]{16}\.[A-Za-z0-9_-]{20,}$/';

    private const TABLE = '/^[A-Za-z_][A-Za-z0-9_]{0,63}$/';

    /** @param  array<string, mixed>  $config  config('mybeast-audit') */
    public function __construct(private array $config) {}

    /** Not switched off: Audit::record() writes to the outbox. */
    public function enabled(): bool
    {
        return ($this->config['enabled'] ?? true) === true;
    }

    public function url(): ?string
    {
        $url = rtrim((string) ($this->config['url'] ?? ''), '/');

        return filter_var($url, FILTER_VALIDATE_URL) !== false && preg_match('#^https?://#', $url) === 1 ? $url : null;
    }

    public function secret(): ?string
    {
        $secret = trim((string) ($this->config['secret'] ?? ''));

        return preg_match(self::SERVER_SECRET, $secret) === 1 ? $secret : null;
    }

    /** On, with a URL and a well-formed secret: audit:ship can send. */
    public function sending(): bool
    {
        return $this->enabled() && $this->url() !== null && $this->secret() !== null;
    }

    /** The header a request's id comes in when no mybeast/logs-laravel gave one; null: read none. */
    public function requestIdHeader(): ?string
    {
        $header = trim((string) ($this->config['request_id_header'] ?? 'X-Request-Id'));

        return preg_match('/^[A-Za-z0-9-]{1,64}$/', $header) === 1 ? $header : null;
    }

    public function connection(): ?string
    {
        $connection = $this->outbox('connection');

        return is_string($connection) && $connection !== '' ? $connection : null;
    }

    public function table(): string
    {
        $table = (string) $this->outbox('table', 'audit_outbox');

        return preg_match(self::TABLE, $table) === 1 ? $table : 'audit_outbox';
    }

    public function migrations(): bool
    {
        return $this->outbox('migrations', true) === true;
    }

    public function schedule(): bool
    {
        return $this->ship('schedule', true) === true;
    }

    public function batch(): int
    {
        return max(1, min(500, (int) $this->ship('batch', 500)));
    }

    public function maxBatches(): int
    {
        return max(1, (int) $this->ship('max_batches', 20));
    }

    public function timeout(): int
    {
        return max(1, min(60, (int) $this->ship('timeout', 10)));
    }

    public function settleSeconds(): int
    {
        return max(0, (int) $this->ship('settle_seconds', 2));
    }

    private function outbox(string $key, mixed $default = null): mixed
    {
        $outbox = $this->config['outbox'] ?? [];

        return is_array($outbox) && array_key_exists($key, $outbox) ? $outbox[$key] : $default;
    }

    private function ship(string $key, mixed $default = null): mixed
    {
        $ship = $this->config['ship'] ?? [];

        return is_array($ship) && array_key_exists($key, $ship) ? $ship[$key] : $default;
    }
}
