<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('pra_diagnostic_streams')) {
            Schema::create('pra_diagnostic_streams', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('company_id');
                $t->char('device_hash', 64);
                $t->uuid('stream_id');
                $t->unsignedInteger('pending')->nullable();
                $t->unsignedBigInteger('dropped')->default(0);
                $t->unsignedInteger('resets')->default(0);
                $t->string('storage_status', 32);
                // Use DATETIME rather than TIMESTAMP so MariaDB installations
                // with explicit_defaults_for_timestamp disabled do not invent a
                // zero default for the second required timestamp column.
                $t->dateTime('first_received_at');
                $t->dateTime('last_received_at')->index();
                $t->unique(['company_id', 'device_hash', 'stream_id'], 'pra_diag_stream_identity');
            });
        }
        if (!Schema::hasTable('pra_diagnostic_samples')) {
            Schema::create('pra_diagnostic_samples', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('company_id');
                $t->unsignedBigInteger('diagnostic_stream_id');
                $t->unsignedBigInteger('sequence');
                $t->dateTime('source_event_at', 3);
                $t->dateTime('received_at');
                $t->char('content_hash', 64);
                $t->text('facts');
                $t->unique(['diagnostic_stream_id', 'sequence'], 'pra_diag_sample_sequence');
                $t->index(['company_id', 'received_at', 'id'], 'pra_diag_sample_period');
                $t->index(['received_at', 'id'], 'pra_diag_sample_retention');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('pra_diagnostic_samples');
        Schema::dropIfExists('pra_diagnostic_streams');
    }
};
