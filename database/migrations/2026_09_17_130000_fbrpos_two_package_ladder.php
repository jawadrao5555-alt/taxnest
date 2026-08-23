<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Owner package decision (23 Aug 2026) — FBR POS drops to TWO packages.
 *
 *   • Pro is merged INTO Business: Business keeps its name and takes Pro's
 *     whole feature set and capacity, except branches, which are capped at 2.
 *     Anything above that is the Rs 10,000/branch/year add-on (now open to FBR
 *     POS as well, see BranchAddonService).
 *   • Starter and Business are the only sellable FBR POS packages. The Pro row
 *     stays for history; the sellable allowlist and is_public take it off sale.
 *   • Starter bills 2,000 FBR invoices a month (was 500). Products are
 *     unlimited on BOTH packages.
 *   • PRICE STORAGE CHANGES: FBR POS used to store a MONTHLY price and charge
 *     price × 12 × 0.94 once a year. It now follows the PRA POS convention —
 *     `price` IS the annual rate, with hand-set quarterly (+5%) and monthly
 *     (+10%) rates, and the shop may buy any of the three cycles.
 *       Starter  Rs 17,999/yr · 4,699/quarter · 1,649/month
 *       Business Rs 27,999/yr · 7,349/quarter · 2,599/month
 *
 * Idempotent: the end state is written outright.
 */
return new class extends Migration
{
    /**
     * The owner-approved end state. price = ANNUAL from this migration on.
     */
    private const TARGET = [
        'Starter' => [
            'price'           => 17999,
            'price_quarterly' => 4699,
            'price_monthly'   => 1649,
            'invoice_limit'   => 2000,
            'max_products'    => -1,
            'branch_limit'    => 1,
        ],
        'Business' => [
            'price'           => 27999,
            'price_quarterly' => 7349,
            'price_monthly'   => 2599,
            'invoice_limit'   => -1,
            'user_limit'      => -1,
            'max_terminals'   => -1,
            'max_users'       => -1,
            'max_products'    => -1,
            'branch_limit'    => 2,
        ],
    ];

    private const RETIRED = ['Pro'];

    public function up(): void
    {
        if (!Schema::hasTable('pricing_plans')) {
            return;
        }

        DB::transaction(function () {
            $columns = array_flip(Schema::getColumnListing('pricing_plans'));
            if (!isset($columns['product_type'])) {
                return;
            }

            $plans = DB::table('pricing_plans')
                ->where('product_type', 'fbrpos')
                ->whereIn('name', array_merge(array_keys(self::TARGET), self::RETIRED))
                ->get()
                ->keyBy('name');

            $business = $plans->get('Business');
            $pro = $plans->get('Pro');

            if (!$business) {
                return;
            }

            foreach (self::TARGET as $name => $values) {
                $plan = $plans->get($name);
                if (!$plan) {
                    continue;
                }

                $row = [];
                foreach ($values as $column => $value) {
                    if (isset($columns[$column])) {
                        $row[$column] = $value;
                    }
                }

                // Business inherits every gate Pro had — "Pro ke saray feature
                // Business mein" — so nothing is lost in the merge.
                if ($name === 'Business' && $pro) {
                    foreach (array_keys($columns) as $column) {
                        if (str_ends_with($column, '_enabled')) {
                            $row[$column] = (int) $pro->{$column};
                        }
                    }
                }

                if ($row === []) {
                    continue;
                }
                if (isset($columns['updated_at'])) {
                    $row['updated_at'] = now();
                }
                DB::table('pricing_plans')->where('id', $plan->id)->update($row);
            }

            $retiredIds = [];
            foreach (self::RETIRED as $name) {
                if ($plan = $plans->get($name)) {
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

            // Shops on Pro move to Business — same features, same dates, same
            // amount already paid.
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
        // One-way: the old monthly-price convention and the Pro assignments
        // cannot be reconstructed safely once the rows have moved.
    }
};
