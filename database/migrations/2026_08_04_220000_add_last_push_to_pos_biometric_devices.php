<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Audit trail for ADMS pushes (4 Aug 2026): remember the source IP + time of
 * the last punch push per device. Root /iclock endpoints are SN-authenticated
 * only, so admins need visibility into where pushes come from.
 *
 * Idempotent: per-column hasColumn guards (prod schema drift safety).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('pos_biometric_devices')) {
            return;
        }

        Schema::table('pos_biometric_devices', function (Blueprint $table) {
            if (!Schema::hasColumn('pos_biometric_devices', 'last_push_ip')) {
                $table->string('last_push_ip', 45)->nullable()->after('is_active');
            }
            if (!Schema::hasColumn('pos_biometric_devices', 'last_push_at')) {
                $table->dateTime('last_push_at')->nullable()->after('last_push_ip');
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('pos_biometric_devices')) {
            return;
        }

        Schema::table('pos_biometric_devices', function (Blueprint $table) {
            if (Schema::hasColumn('pos_biometric_devices', 'last_push_ip')) {
                $table->dropColumn('last_push_ip');
            }
            if (Schema::hasColumn('pos_biometric_devices', 'last_push_at')) {
                $table->dropColumn('last_push_at');
            }
        });
    }
};
