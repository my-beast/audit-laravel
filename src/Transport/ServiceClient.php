<?php

declare(strict_types=1);

namespace MyBeast\Audit\Laravel\Transport;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Throwable;

/** The host server talking to the service with its secret (MYBEAST_AUDIT_SECRET). Answers come back as they are. */
final readonly class ServiceClient
{
    public function __construct(
        private string $url,
        private string $secret,
        private int $timeout,
    ) {}

    /**
     * One batch to POST /v1/events, gzip: the events exactly as the outbox keeps them, in its order.
     *
     * @param  list<string>  $payloads  each event's JSON
     *
     * @throws TransportFailed
     */
    public function send(array $payloads): Response
    {
        $body = (string) gzencode('{"events":['.implode(',', $payloads).']}');

        try {
            return Http::withToken($this->secret)
                ->acceptJson()
                ->timeout($this->timeout)
                ->connectTimeout(min(5, $this->timeout))
                ->withHeaders(['Content-Encoding' => 'gzip'])
                ->withBody($body, 'application/json')
                ->post($this->url.'/v1/events');
        } catch (Throwable $e) {
            throw new TransportFailed('The audit service could not be reached: '.$e->getMessage(), 0, $e);
        }
    }
}
