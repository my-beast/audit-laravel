<?php

declare(strict_types=1);

namespace MyBeast\Audit\Laravel;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\ServiceProvider;
use MyBeast\Audit\Laravel\Console\ShipCommand;
use MyBeast\Audit\Laravel\Outbox\Outbox;
use MyBeast\Audit\Laravel\Outbox\OutboxRecorder;
use MyBeast\Audit\Laravel\Support\Settings;
use MyBeast\Audit\Laravel\Transport\ServiceClient;
use MyBeast\Audit\Laravel\Transport\Shipper;

/**
 * Plugs MyBeast audit in: the config, the outbox's migration, Audit::record() into the outbox, and audit:ship every
 * minute on one server (the outbox is in the shared database, so one shipper is enough - and two would race).
 */
final class AuditServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/mybeast-audit.php', 'mybeast-audit');

        // Made per use from the config as it is then: a test or a later change takes effect at once.
        $this->app->bind(Settings::class, fn ($app): Settings => new Settings((array) $app['config']->get('mybeast-audit', [])));
        $this->app->singleton(Audit::class);
        $this->app->bind(Outbox::class, fn ($app): Outbox => new Outbox($app['db'], $app->make(Settings::class)));
        $this->app->bindIf(Recorder::class, OutboxRecorder::class);
        $this->app->bind(Shipper::class, function ($app): Shipper {
            $settings = $app->make(Settings::class);

            return new Shipper(
                $app->make(Outbox::class),
                $settings->sending() ? new ServiceClient((string) $settings->url(), (string) $settings->secret(), $settings->timeout()) : null,
                $settings,
                $app['cache']->store(),
            );
        });
    }

    public function boot(): void
    {
        $settings = $this->app->make(Settings::class);

        if ($settings->migrations()) {
            $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        }

        if (! $this->app->runningInConsole()) {
            return;
        }

        $this->publishes([__DIR__.'/../config/mybeast-audit.php' => config_path('mybeast-audit.php')], 'mybeast-audit-config');
        $this->publishesMigrations([__DIR__.'/../database/migrations' => database_path('migrations')], 'mybeast-audit-migrations');
        $this->commands([ShipCommand::class]);

        if ($settings->schedule()) {
            $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
                $schedule->command('audit:ship')->everyMinute()->withoutOverlapping()->onOneServer();
            });
        }
    }
}
