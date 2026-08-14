<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Task 705 — LOCAL cashier ↔ PRA cashier counterpart link (owner-set on the
 * Team page). Drives the khufia station identity switch (Ctrl+Alt+Shift+L):
 * a LOCAL-scoped cashier's session flips to this linked PRA cashier ID and
 * back. No FK constraint (users self-reference + prod-drift self-heal
 * convention); validation is company-scoped in the controller.
 * Idempotent per-column guards (PROD schema drift self-heal).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('users')) {
            return;
        }
        if (!Schema::hasColumn('users', 'pos_counterpart_user_id')) {
            Schema::table('users', function (Blueprint $table) {
                $table->unsignedBigInteger('pos_counterpart_user_id')->nullable()->index();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('users') && Schema::hasColumn('users', 'pos_counterpart_user_id')) {
            Schema::table('users', function (Blueprint $table) {
                $table->dropColumn('pos_counterpart_user_id');
            });
        }
    }
};
