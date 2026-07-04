<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Free trial cap: 20 -> 10 invoices/transactions across every product's
     * trial plan (di, pos, fbrpos). Also fixes any trial feature-list strings
     * that still advertise the old 20-invoice cap.
     */
    public function up(): void
    {
        DB::table('pricing_plans')->where('is_trial', true)->update([
            'invoice_limit' => 10,
            'updated_at' => now(),
        ]);

        foreach (DB::table('pricing_plans')->where('is_trial', true)->get() as $t) {
            if (empty($t->features)) {
                continue;
            }
            $updated = str_replace(
                ['20 invoices', '20 transactions'],
                ['10 invoices', '10 transactions'],
                $t->features
            );
            if ($updated !== $t->features) {
                DB::table('pricing_plans')->where('id', $t->id)->update([
                    'features' => $updated,
                    'updated_at' => now(),
                ]);
            }
        }
    }

    public function down(): void
    {
        DB::table('pricing_plans')->where('is_trial', true)->update([
            'invoice_limit' => 20,
            'updated_at' => now(),
        ]);
    }
};
