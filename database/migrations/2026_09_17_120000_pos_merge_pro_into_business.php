<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Owner package decision (23 Aug 2026) — PRA POS drops to THREE packages.
 *
 *   • Pro is merged INTO Business: the Business row keeps its name but takes
 *     Pro's whole feature set (Staff Hazri included) and Pro's capacity, and
 *     is repriced to Rs 27,999 a year.
 *   • Pro and Pro Max stop being sellable. Their rows stay in pricing_plans so
 *     old subscriptions, proofs and receipts keep pointing at what was really
 *     bought; the sellable allowlist (PosPlanComparisonService) and is_public
 *     are what take them off the shelf.
 *   • WhatsApp Bill stops being a paid add-on for the two upper packages: it is
 *     INCLUDED in Business and Unlimited (Starter still buys it).
 *   • Caller ID is INCLUDED in Unlimited only; Business keeps buying it as the
 *     paid add-on it is today.
 *   • Branches: Starter 1, Business 1, Unlimited 2. Anything above that is the
 *     existing Rs 10,000/branch/year add-on — which Unlimited can now buy too,
 *     because its limit stopped being infinite.
 *   • Team accounts: Business 7, Unlimited 12 (Starter stays 2).
 *
 * Shops sitting on Pro / Pro Max move to Business without touching their dates,
 * cycle or the amount they actually paid — they lose nothing, because Business
 * now IS Pro. Idempotent: the target state is written outright, so a second run
 * changes nothing.
 */
return new class extends Migration
{
    /**
     * The owner-approved end state. Written here in full rather than derived,
     * so the intended ladder is readable years later and a half-applied edit
     * cannot leave a package sitting between two shapes.
     */
    private const TARGET = [
        'Business' => [
            'price'           => 27999,
            'price_quarterly' => 7349,
            'price_monthly'   => 2599,
            'invoice_limit'   => -1,
            'user_limit'      => 7,
            'branch_limit'    => 1,
            'max_terminals'   => -1,
            'max_users'       => -1,
            'max_products'    => -1,
        ],
        'Unlimited' => [
            'invoice_limit' => -1,
            'user_limit'    => 12,
            'branch_limit'  => 2,
            'max_terminals' => -1,
            'max_products'  => -1,
        ],
        'Starter' => [
            'branch_limit' => 1,
        ],
    ];

    /**
     * Gate columns each package must end up with, for the gates this decision
     * actually moves. Everything else on Business is copied from Pro below —
     * "saray feature Pro walay" — so a gate nobody listed here still follows.
     */
    private const GATES = [
        'Starter'   => ['whatsapp_enabled' => 0, 'caller_id_enabled' => 0, 'rider_tracking_enabled' => 0],
        'Business'  => ['whatsapp_enabled' => 1, 'caller_id_enabled' => 0, 'rider_tracking_enabled' => 0, 'hazri_enabled' => 1],
        'Unlimited' => ['whatsapp_enabled' => 1, 'caller_id_enabled' => 1, 'rider_tracking_enabled' => 0, 'hazri_enabled' => 1],
    ];

    /** Packages that stop being sold. */
    private const RETIRED = ['Pro', 'Pro Max'];

    public function up(): void
    {
        if (!Schema::hasTable('pricing_plans')) {
            return;
        }

        DB::transaction(function () {
            $columns = array_flip(Schema::getColumnListing('pricing_plans'));

            $query = DB::table('pricing_plans')
                ->whereIn('name', array_merge(array_keys(self::TARGET), self::RETIRED));
            if (isset($columns['product_type'])) {
                $query->where('product_type', 'pos');
            }
            $plans = $query->get()->keyBy('name');

            $business = $plans->get('Business');
            $pro = $plans->get('Pro');

            if (!$business) {
                return;
            }

            // ── 1. Business inherits Pro ───────────────────────────────────
            $updates = [];
            foreach (self::TARGET as $name => $limits) {
                $plan = $plans->get($name);
                if (!$plan) {
                    continue;
                }

                $row = [];
                foreach ($limits as $column => $value) {
                    if (isset($columns[$column])) {
                        $row[$column] = $value;
                    }
                }

                // Every remaining gate on Business follows the retired Pro row,
                // so a feature nobody thought to list is not silently dropped.
                if ($name === 'Business' && $pro) {
                    foreach (array_keys($columns) as $column) {
                        if (str_ends_with($column, '_enabled')) {
                            $row[$column] = (int) $pro->{$column};
                        }
                    }
                }

                foreach (self::GATES[$name] ?? [] as $column => $value) {
                    if (isset($columns[$column])) {
                        $row[$column] = $value;
                    }
                }

                $updates[$plan->id] = $row;
            }

            foreach ($updates as $planId => $row) {
                if ($row === []) {
                    continue;
                }
                if (isset($columns['updated_at'])) {
                    $row['updated_at'] = now();
                }
                DB::table('pricing_plans')->where('id', $planId)->update($row);
            }

            // ── 2. Pro / Pro Max leave the shelf ───────────────────────────
            $retiredIds = [];
            foreach (self::RETIRED as $name) {
                $plan = $plans->get($name);
                if ($plan) {
                    $retiredIds[] = $plan->id;
                }
            }

            if ($retiredIds === []) {
                return;
            }

            if (isset($columns['is_public'])) {
                $hide = ['is_public' => 0];
                if (isset($columns['updated_at'])) {
                    $hide['updated_at'] = now();
                }
                DB::table('pricing_plans')->whereIn('id', $retiredIds)->update($hide);
            }

            // ── 3. Everyone still pointing at them moves to Business ───────
            if (Schema::hasTable('subscriptions')
                && Schema::hasColumn('subscriptions', 'pricing_plan_id')
                && Schema::hasColumn('subscriptions', 'active')) {
                $move = ['pricing_plan_id' => $business->id];
                if (Schema::hasColumn('subscriptions', 'updated_at')) {
                    $move['updated_at'] = now();
                }
                DB::table('subscriptions')
                    ->whereIn('pricing_plan_id', $retiredIds)
                    ->where('active', 1)
                    ->update($move);
            }

            if (Schema::hasTable('companies') && Schema::hasColumn('companies', 'requested_plan_id')) {
                $pendingColumns = array_values(array_filter(
                    ['status', 'company_status'],
                    fn (string $column) => Schema::hasColumn('companies', $column)
                ));
                if ($pendingColumns !== []) {
                    $move = ['requested_plan_id' => $business->id];
                    if (Schema::hasColumn('companies', 'updated_at')) {
                        $move['updated_at'] = now();
                    }
                    DB::table('companies')
                        ->whereIn('requested_plan_id', $retiredIds)
                        ->where(function ($query) use ($pendingColumns) {
                            foreach ($pendingColumns as $index => $column) {
                                $index === 0
                                    ? $query->where($column, 'pending')
                                    : $query->orWhere($column, 'pending');
                            }
                        })
                        ->update($move);
                }
            }

            if (Schema::hasTable('payment_proofs')
                && Schema::hasColumn('payment_proofs', 'pricing_plan_id')
                && Schema::hasColumn('payment_proofs', 'status')) {
                $move = ['pricing_plan_id' => $business->id];
                if (Schema::hasColumn('payment_proofs', 'updated_at')) {
                    $move['updated_at'] = now();
                }
                DB::table('payment_proofs')
                    ->whereIn('pricing_plan_id', $retiredIds)
                    ->where('status', 'pending')
                    ->update($move);
            }
        });
    }

    public function down(): void
    {
        // One-way commercial consolidation: which shops were on Pro (and what
        // Business looked like before it absorbed Pro) cannot be reconstructed
        // safely once the rows have moved.
    }
};
