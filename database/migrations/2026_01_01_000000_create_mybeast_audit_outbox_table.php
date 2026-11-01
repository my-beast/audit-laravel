<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * My Beast audit's outbox (mybeast/audit-laravel): each event Audit::record() wrote, in the transaction of the action
 * it records, until audit:ship has it confirmed by the service - then the row goes. `payload` is the event exactly as
 * it is sent (schemas/v1/events.schema.json); `id` is the order it is sent in. A row the service refused stays, with
 * the reason, for a person to look at (`audit:ship --status`, `--retry-refused`).
 */
return new class extends Migration
{
    public function getConnection(): ?string
    {
        return config('mybeast-audit.outbox.connection');
    }

    public function up(): void
    {
        Schema::connection($this->getConnection())->create($this->table(), function (Blueprint $table) {
            $table->id();
            $table->uuid('event_id')->unique();
            $table->text('payload');
            $table->dateTime('created_at', 3);
            $table->unsignedInteger('attempts')->default(0);
            $table->dateTime('last_attempt_at', 3)->nullable();
            $table->string('last_error', 500)->nullable();
            $table->dateTime('refused_at', 3)->nullable();
            $table->string('refusal', 500)->nullable();

            $table->index(['refused_at', 'id']);
        });
    }

    public function down(): void
    {
        Schema::connection($this->getConnection())->dropIfExists($this->table());
    }

    private function table(): string
    {
        return (string) config('mybeast-audit.outbox.table', 'audit_outbox');
    }
};
