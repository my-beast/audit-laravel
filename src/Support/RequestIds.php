<?php

declare(strict_types=1);

namespace MyBeast\Audit\Laravel\Support;

use Illuminate\Contracts\Container\Container;
use Illuminate\Log\Context\Repository as ContextRepository;
use Illuminate\Support\Facades\Context;
use Throwable;

/**
 * The id of the request an event is recorded in, as the host's log lines carry it - so the event's page at the service
 * opens exactly that request's lines in Logs and its errors in Errors. Without needing either SDK:
 *
 * - mybeast/logs-laravel's, when it runs: it keeps the request's id in Laravel's hidden Context (and hands it on to the
 *   jobs the request queues, so a job's events carry the id of the request that queued it);
 * - else the request's own header (`X-Request-Id`, from a proxy or a load balancer), when one came;
 * - else none: an id the host's logs never saw would link to nothing.
 *
 * An id that does not fit the contract (1-64 letters, digits, . _ : or -) is left off; the event still goes.
 */
final readonly class RequestIds
{
    /** mybeast/logs-laravel's hidden Context key (MyBeast\Logs\Laravel\Scope\Scope::CONTEXT_KEY). */
    public const LOGS_CONTEXT_KEY = 'mybeast_logs_request_id';

    public const ID = '/^[A-Za-z0-9._:-]{1,64}$/';

    public function __construct(
        private Container $app,
        private Settings $settings,
    ) {}

    public function current(): ?string
    {
        return self::accept($this->fromContext()) ?? self::accept($this->fromHeader());
    }

    public static function accept(mixed $id): ?string
    {
        return is_string($id) && preg_match(self::ID, $id) === 1 ? $id : null;
    }

    private function fromContext(): mixed
    {
        try {
            return class_exists(Context::class) && $this->app->bound(ContextRepository::class)
                ? Context::getHidden(self::LOGS_CONTEXT_KEY)
                : null;
        } catch (Throwable) {
            return null;
        }
    }

    private function fromHeader(): ?string
    {
        $header = $this->settings->requestIdHeader();

        // An artisan run or a queue worker has a request bound with no headers: nothing comes of it.
        if ($header === null || ! $this->app->bound('request')) {
            return null;
        }

        return $this->app->make('request')->headers->get($header);
    }
}
