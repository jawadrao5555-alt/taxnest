<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * POS pricing ladder restructure (owner-approved, 02 Aug 2026).
 *
 * 5 packages, 14,999 → 69,999 ANNUAL, strict feature superset going up,
 * plus a NEW explicit quarterly price per plan (price_quarterly) — POS
 * subscriptions can now be Annual or Quarterly (no monthly, owner's call).
 *
 * Existing subscribers keep their current subscription untouched until
 * end_date; renewals are quoted at the new rates (owner: "agli renewal
 * par naye rate"). No subscription rows are modified here — plans only.
 *
 * Idempotent: pure UPDATEs keyed by id + product_type + expected current
 * name; safe to re-run (prod deploys run `migrate --force`, never seeds).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('pricing_plans', 'price_quarterly')) {
            Schema::table('pricing_plans', function (Blueprint $table) {
                $table->decimal('price_quarterly', 12, 2)->nullable()->after('price_monthly');
            });
        }

        $plans = [
            [
                'id' => 9, 'name' => 'Starter',
                'update' => [
                    'price' => 14999, 'price_quarterly' => 4299, 'compare_at_price' => null,
                    'invoice_limit' => 800, 'user_limit' => 2, 'max_users' => 2,
                    'max_terminals' => 1, 'max_products' => 300, 'branch_limit' => 1,
                    'inventory_enabled' => 1, 'reports_enabled' => 1,
                    'features' => json_encode([
                        'PRA fiscal receipts with QR code',
                        'Fast barcode & SKU billing screen',
                        'Thermal receipt printing (80mm & 58mm)',
                        'Customer records, khata & purchase history',
                        'Inventory & stock management',
                        'Basic sales & tax reports + daily closing (Z-report)',
                        'Android mobile app for your whole team',
                        'Urdu / English + dashboard themes',
                    ]),
                ],
            ],
            [
                'id' => 10, 'name' => 'Business',
                'update' => [
                    'price' => 24999, 'price_quarterly' => 7199, 'compare_at_price' => null,
                    'invoice_limit' => 2500, 'user_limit' => 5, 'max_users' => 5,
                    'max_terminals' => 3, 'max_products' => 1000, 'branch_limit' => 1,
                    'inventory_enabled' => 1, 'reports_enabled' => 1,
                    'features' => json_encode([
                        'Offline billing with auto-sync + Desktop app & silent printing',
                        'Multi-terminal support (3 counters)',
                        'Deals & combo pricing',
                        'Advanced reports with CSV & PDF export',
                        'Product Excel import / export',
                    ]),
                ],
            ],
            [
                'id' => 11, 'name' => 'Pro',
                'update' => [
                    'price' => 34999, 'price_quarterly' => 9999, 'compare_at_price' => null,
                    'invoice_limit' => 5000, 'user_limit' => 10,
                    'features' => json_encode([
                        'Full Restaurant module — dine-in tables, KOT, kitchen display & waiter tablets',
                        'Delivery Riders — rider khata, settlements & rider portal',
                        'Public QR Menu page — customers scan & browse your menu online',
                        'Advanced analytics dashboard',
                        'Cancelled-orders & kitchen-waste report',
                        '2 branches, unlimited counters & products',
                    ]),
                ],
            ],
            [
                'id' => 22, 'name' => 'Pro Max',
                'update' => [
                    'price' => 49999, 'price_quarterly' => 14399, 'compare_at_price' => null,
                    'invoice_limit' => 10000, 'user_limit' => 20, 'branch_limit' => 3,
                    'rider_tracking_enabled' => 1,
                    'features' => json_encode([
                        'Staff Hazri (attendance) with day-close report',
                        'Rider LIVE tracking on map',
                        '10,000 bills / month',
                        '3 branches & 20 team accounts',
                    ]),
                ],
            ],
            [
                'id' => 21, 'name' => 'Unlimited',
                'update' => [
                    'price' => 69999, 'price_quarterly' => 19999, 'compare_at_price' => null,
                    'invoice_limit' => -1, 'user_limit' => -1, 'branch_limit' => -1,
                    'custom_access_enabled' => 1, 'rider_tracking_enabled' => 1,
                    'features' => json_encode([
                        'Team Custom Access — per-user permissions',
                        'Unlimited bills, branches, team accounts & products',
                        'Priority support',
                    ]),
                ],
            ],
        ];

        // Schema-tolerant: only write columns that actually exist. The sqlite
        // migration chain used by RefreshDatabase tests (and, per past drift
        // incidents, even prod) can miss later ensure-columns additions like
        // rider_tracking_enabled — a missing column must skip, never crash.
        $existingCols = array_flip(Schema::getColumnListing('pricing_plans'));

        foreach ($plans as $p) {
            // Fresh installs (tests, new envs) can run this BEFORE later migrations
            // that add columns like rider_tracking_enabled — only update columns
            // that exist now (the later migration sets its own column's values).
            $update = array_intersect_key($p['update'], $existingCols);
            $skipped = array_diff_key($p['update'], $update);

            if ($skipped !== []) {
                logger()->warning("POS pricing ladder migration: plan id {$p['id']} — missing columns skipped: " . implode(', ', array_keys($skipped)));
            }

            if ($update === []) {
                continue;
            }

            $updated = DB::table('pricing_plans')
                ->where('id', $p['id'])
                ->where('product_type', 'pos')
                ->where('name', $p['name'])
                ->update($update);

            if (!$updated) {
                // Row drifted (renamed/deleted) — do NOT guess. Log loudly and skip.
                logger()->warning("POS pricing ladder migration: plan id {$p['id']} ('{$p['name']}') not found or name drifted — skipped.");
            }
        }
    }

    public function down(): void
    {
        // Business decision — no automatic price rollback.
    }
};
