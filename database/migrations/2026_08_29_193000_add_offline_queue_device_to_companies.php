<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which counter device last reported its offline queue.
 *
 * Without this, the report is last-writer-wins across a shop's counters: till 2
 * sits idle with an empty queue, reports zero, and wipes the fact that till 1 is
 * holding eleven unsent bills. That is precisely the silence this telemetry was
 * added to remove, so a zero is now only allowed to clear the record when it
 * comes from the same device that raised it (or when the record is stale).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            if (!Schema::hasColumn('companies', 'offline_queue_device')) {
                $table->string('offline_queue_device', 64)->nullable()->after('offline_queue_reported_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            if (Schema::hasColumn('companies', 'offline_queue_device')) {
                $table->dropColumn('offline_queue_device');
            }
        });
    }
};
