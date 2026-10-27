<?php

declare(strict_types=1);

namespace MyBeast\Audit\Laravel\Console;

use Illuminate\Console\Command;
use MyBeast\Audit\Laravel\Outbox\Outbox;
use MyBeast\Audit\Laravel\Transport\Shipper;

final class ShipCommand extends Command
{
    protected $signature = 'audit:ship
        {--status : Only say what waits in the outbox, and what the service refused}
        {--retry-refused : Put the refused events back in line (after fixing what the service said), then send}';

    protected $description = 'Send the audit outbox to services.my-beast.com/audit, in order, and forget what the service confirmed';

    public function handle(Shipper $shipper, Outbox $outbox): int
    {
        if ($this->option('status')) {
            return $this->status($outbox);
        }

        if ($this->option('retry-refused')) {
            $this->info($outbox->retryRefused().' refused event(s) back in line.');
        }

        $report = $shipper->ship();

        if (! $report->configured) {
            $this->warn('Not sending: MYBEAST_AUDIT_URL or MYBEAST_AUDIT_SECRET is missing or malformed. Events wait in the outbox.');

            return self::SUCCESS;
        }

        if ($report->busy) {
            $this->line('Another audit:ship is running.');

            return self::SUCCESS;
        }

        $waiting = $outbox->status();
        $this->line("Sent {$report->sent()} event(s)".($report->duplicates > 0 ? " ({$report->duplicates} were there already)" : '')
            .($report->refused > 0 ? "; {$report->refused} refused (audit:ship --status)" : '')
            .". {$waiting['waiting']} waiting.");

        if ($report->stopped !== null) {
            $this->error($report->stopped.' They wait in the outbox; the next run sends them in order.');

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    private function status(Outbox $outbox): int
    {
        $status = $outbox->status();

        $this->line("{$status['waiting']} event(s) waiting".($status['oldest'] !== null ? ", the oldest since {$status['oldest']} UTC" : '').'.');

        if ($status['last_error'] !== null) {
            $this->line("Last try: {$status['last_error']}");
        }

        if ($status['refused'] > 0) {
            $this->warn("{$status['refused']} refused by the service:");

            foreach ($outbox->refused() as $refused) {
                $this->line("  {$refused['action']}: {$refused['refusal']}");
            }
        }

        return self::SUCCESS;
    }
}
