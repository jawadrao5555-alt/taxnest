<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Khata upgrade Aug 2026 — per-customer credit controls.
 *
 * WHY: the shop owner asked for a per-customer credit limit (udhaar hadd) so a
 * cashier can't quietly let a customer's balance run away, and a reminder
 * timestamp so nobody gets pestered with the same WhatsApp yaad-dehani twice a
 * day. Both live on pos_customers next to the existing khata_balance cache.
 *
 * Idempotent (hasColumn-guarded) so the owner's cPanel PROD can re-run
 * `migrate --force` safely — same pattern as the retail-core migration.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('pos_customers')) {
            Schema::table('pos_customers', function (Blueprint $table) {
                // Nullable = "no limit" (empty in the form). A concrete value is
                // the max outstanding balance a credit sale may push the customer to.
                if (!Schema::hasColumn('pos_customers', 'khata_limit')) {
                    $table->decimal('khata_limit', 12, 2)->nullable()->after('khata_balance');
                }
                // Last WhatsApp reminder moment — so the UI can show "aakhri yaad
                // dehani: N din pehle" and the owner doesn't double-remind.
                if (!Schema::hasColumn('pos_customers', 'khata_last_reminder_at')) {
                    $table->timestamp('khata_last_reminder_at')->nullable()->after('khata_limit');
                }
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('pos_customers')) {
            Schema::table('pos_customers', function (Blueprint $table) {
                if (Schema::hasColumn('pos_customers', 'khata_last_reminder_at')) {
                    $table->dropColumn('khata_last_reminder_at');
                }
                if (Schema::hasColumn('pos_customers', 'khata_limit')) {
                    $table->dropColumn('khata_limit');
                }
            });
        }
    }
};
