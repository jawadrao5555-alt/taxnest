<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Enrich PRA POS plan feature lists (owner request Jul 2026):
     * plan cards must show full, persuasive details. Feature JSON no longer
     * duplicates the limit columns (bills/month, team accounts, branches) —
     * views render those from the columns directly. Lists are cumulative:
     * views print "Everything in <previous>, plus:" above each higher tier.
     * Idempotent: plain UPDATEs matched on product_type + name.
     */
    public function up(): void
    {
        $sets = [
            'Starter' => [
                'PRA fiscal receipts with QR code',
                'Fast barcode & SKU billing screen',
                'Thermal receipt printing (80mm & 58mm)',
                'Customer records & purchase history',
                'Sales & tax reports',
                'Daily closing (Z-report)',
            ],
            'Business' => [
                'Offline billing with auto-sync',
                'Multi-terminal support',
                'Advanced reports with CSV & PDF export',
                'Deals & combo pricing',
            ],
            'Pro' => [
                'Full Restaurant module — kitchen display, dine-in tables, waiter tablets & KOT printing',
                'Inventory & stock management',
                'Advanced analytics dashboard',
                'Priority support',
            ],
            'Unlimited' => [
                'No limits — unlimited bills, team accounts & branches',
                'Every current & future feature unlocked',
                'Priority support & onboarding help',
            ],
        ];

        foreach ($sets as $name => $features) {
            DB::table('pricing_plans')
                ->where('product_type', 'pos')
                ->where('name', $name)
                ->update([
                    'features' => json_encode($features, JSON_UNESCAPED_UNICODE),
                    'updated_at' => now(),
                ]);
        }
    }

    public function down(): void
    {
        // Marketing copy update — nothing to restore.
    }
};
