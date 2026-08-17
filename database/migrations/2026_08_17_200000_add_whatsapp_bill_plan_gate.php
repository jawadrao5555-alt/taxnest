<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Owner (17 Aug 2026): "whatsapp bill pro say uper saray packages mein
 * shamil kr do" — WhatsApp Bill (receipt-popup share button + auto-open +
 * reprint-list share + public receipt links, Task 1036) becomes Pro-and-above.
 *
 * New plan gate column: pricing_plans.whatsapp_enabled (PLAN_GATES /
 * planAllows pattern — fails OPEN if column missing on lagging PROD).
 *
 * Matrix (PRA POS product_type='pos'):
 *   Trial     → 0 (active-trial rule in planAllows grants it; expired locks)
 *   Starter   → 0
 *   Business  → 0
 *   Pro       → 1
 *   Pro Max   → 1
 *   Unlimited → 1
 * Non-'pos' product types keep the default TRUE — nothing changes for them.
 *
 * Idempotent: hasColumn guards + deterministic UPDATEs (prod runs
 * migrate --force via deploy script).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('pricing_plans', 'whatsapp_enabled')) {
            Schema::table('pricing_plans', function (Blueprint $table) {
                $table->boolean('whatsapp_enabled')->default(true);
            });
        }

        DB::table('pricing_plans')
            ->where('product_type', 'pos')
            ->whereIn('name', ['Pro', 'Pro Max', 'Unlimited'])
            ->update(['whatsapp_enabled' => 1]);
        DB::table('pricing_plans')
            ->where('product_type', 'pos')
            ->whereNotIn('name', ['Pro', 'Pro Max', 'Unlimited'])
            ->update(['whatsapp_enabled' => 0]);
    }

    public function down(): void
    {
        if (Schema::hasColumn('pricing_plans', 'whatsapp_enabled')) {
            Schema::table('pricing_plans', function (Blueprint $table) {
                $table->dropColumn('whatsapp_enabled');
            });
        }
    }
};
