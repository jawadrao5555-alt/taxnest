<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Duplicate-print guard for the silent-print queue.
 *
 * A job stuck in 'printing' used to be blindly requeued after 2 minutes. But
 * once the agent has FETCHED the job's content (GET print-jobs/{id}/content),
 * the paper may already have come out and only the result POST was lost —
 * requeueing prints the bill twice. `content_fetched_at` records that moment
 * so housekeeping can tell "claimed, never rendered" (safe to retry) from
 * "content delivered, outcome unknown" (park as failed, reprint deliberately).
 *
 * Idempotent + column-guarded (prod schema drift convention). Code paths are
 * guarded on Schema::hasColumn so a deploy before this migration keeps the
 * old behaviour instead of erroring.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('pos_print_jobs') && !Schema::hasColumn('pos_print_jobs', 'content_fetched_at')) {
            Schema::table('pos_print_jobs', function (Blueprint $table) {
                $table->timestamp('content_fetched_at')->nullable()->after('claim_token');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('pos_print_jobs', 'content_fetched_at')) {
            Schema::table('pos_print_jobs', function (Blueprint $table) {
                $table->dropColumn('content_fetched_at');
            });
        }
    }
};
