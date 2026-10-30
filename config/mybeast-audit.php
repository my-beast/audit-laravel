<?php

declare(strict_types=1);

/*
 * MyBeast audit: this app's audit events, kept in one tamper-evident log at services.my-beast.com/audit.
 * Audit::record(…) writes an event to the outbox table in your own database transaction - no action without its
 * record - and `php artisan audit:ship` (every minute, by the scheduler) sends the outbox in order and forgets what
 * the service confirmed. The URL and the secret come from the project's page there (its setup guide: the Overview
 * until Audit first keeps an event, then "Setup guide").
 *
 * Without the URL or the secret, events still wait in the outbox: nothing is lost while the .env is incomplete.
 */
return [
    // false: Audit::record() checks the event and writes nothing (tests that do not fake it, a checkout without audit).
    'enabled' => (bool) env('MYBEAST_AUDIT_ENABLED', true),

    // The service, e.g. https://services.my-beast.com/audit (no trailing slash needed).
    'url' => env('MYBEAST_AUDIT_URL'),

    // sk_….…: this server's secret for the service. Keep it on the server only.
    'secret' => env('MYBEAST_AUDIT_SECRET'),

    // Each event carries the id of the request it was recorded in, so its page at the service opens that request's
    // log lines and errors: mybeast/logs-laravel's id when it runs, else this request header's ('' reads none).
    'request_id_header' => env('MYBEAST_AUDIT_REQUEST_ID_HEADER', 'X-Request-Id'),

    'outbox' => [
        // The connection of the actions you audit (null: the default one) - the outbox row joins their transaction
        // only on the same connection.
        'connection' => env('MYBEAST_AUDIT_CONNECTION'),

        // The outbox table. Rename it to your own rule (core_audit_outbox, say) before the first migrate.
        'table' => env('MYBEAST_AUDIT_OUTBOX_TABLE', 'audit_outbox'),

        // true: the SDK's migration runs with yours. false: publish it (vendor:publish --tag=mybeast-audit-migrations).
        'migrations' => true,
    ],

    'ship' => [
        // true: audit:ship runs every minute on one server (the scheduler must run: schedule:work or cron).
        'schedule' => true,

        // Events in one request (the service takes at most 500), and requests in one run.
        'batch' => 500,
        'max_batches' => 20,

        // Seconds to wait for the service.
        'timeout' => 10,

        // An event is sent once it is this many seconds old, so a transaction that committed later with a lower id is
        // not overtaken by one that committed first.
        'settle_seconds' => 2,
    ],
];
