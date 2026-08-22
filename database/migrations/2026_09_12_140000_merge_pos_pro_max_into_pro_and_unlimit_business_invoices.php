<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Owner package decision:
 * - Pro Max is retired as a sellable PRA POS package.
 * - Pro keeps its current price but inherits Pro Max's capacity and gates.
 * - Business receives unlimited final invoices.
 *
 * Historical Pro Max rows remain so old proofs and subscriptions retain their
 * original record. Current subscriptions and pending package selections move
 * to Pro without changing their dates, cycle, or recorded paid amount.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('pricing_plans')) {
            return;
        }

        DB::transaction(function () {
            $planQuery = DB::table('pricing_plans')
                ->whereIn('name', ['Business', 'Pro', 'Pro Max']);
            if (Schema::hasColumn('pricing_plans', 'product_type')) {
                $planQuery->where('product_type', 'pos');
            }
            $plans = $planQuery->get()->keyBy('name');

            $business = $plans->get('Business');
            $pro = $plans->get('Pro');
            $proMax = $plans->get('Pro Max');
            $columns = array_flip(Schema::getColumnListing('pricing_plans'));

            if ($business && isset($columns['invoice_limit'])) {
                $businessUpdate = ['invoice_limit' => -1];
                if (isset($columns['updated_at'])) {
                    $businessUpdate['updated_at'] = now();
                }
                DB::table('pricing_plans')->where('id', $business->id)->update($businessUpdate);
            }

            if (!$pro || !$proMax) {
                return;
            }

            $proUpdate = [];
            if (isset($columns['updated_at'])) {
                $proUpdate['updated_at'] = now();
            }
            foreach (['invoice_limit', 'user_limit', 'branch_limit', 'max_terminals', 'max_users', 'max_products'] as $column) {
                if (isset($columns[$column])) {
                    $proUpdate[$column] = $proMax->{$column};
                }
            }

            // Copy every plan-gated feature from the retired tier, while
            // deliberately leaving price and price_quarterly on the existing
            // Pro row exactly as the owner requested.
            foreach (array_keys($columns) as $column) {
                if (str_ends_with($column, '_enabled')) {
                    $proUpdate[$column] = $proMax->{$column};
                }
            }

            DB::table('pricing_plans')->where('id', $pro->id)->update($proUpdate);

            if (Schema::hasTable('subscriptions')
                && Schema::hasColumn('subscriptions', 'pricing_plan_id')
                && Schema::hasColumn('subscriptions', 'active')) {
                $subscriptionUpdate = ['pricing_plan_id' => $pro->id];
                if (Schema::hasColumn('subscriptions', 'updated_at')) {
                    $subscriptionUpdate['updated_at'] = now();
                }
                DB::table('subscriptions')
                    ->where('pricing_plan_id', $proMax->id)
                    ->where('active', 1)
                    ->update($subscriptionUpdate);
            }

            if (Schema::hasTable('companies') && Schema::hasColumn('companies', 'requested_plan_id')) {
                $pendingColumns = array_values(array_filter(
                    ['status', 'company_status'],
                    fn (string $column) => Schema::hasColumn('companies', $column)
                ));
                if ($pendingColumns !== []) {
                    $companyUpdate = ['requested_plan_id' => $pro->id];
                    if (Schema::hasColumn('companies', 'updated_at')) {
                        $companyUpdate['updated_at'] = now();
                    }
                    DB::table('companies')
                        ->where('requested_plan_id', $proMax->id)
                        ->where(function ($query) use ($pendingColumns) {
                            foreach ($pendingColumns as $index => $column) {
                                $index === 0
                                    ? $query->where($column, 'pending')
                                    : $query->orWhere($column, 'pending');
                            }
                        })
                        ->update($companyUpdate);
                }
            }

            if (Schema::hasTable('payment_proofs')
                && Schema::hasColumn('payment_proofs', 'pricing_plan_id')
                && Schema::hasColumn('payment_proofs', 'status')) {
                $proofUpdate = ['pricing_plan_id' => $pro->id];
                if (Schema::hasColumn('payment_proofs', 'updated_at')) {
                    $proofUpdate['updated_at'] = now();
                }
                DB::table('payment_proofs')
                    ->where('pricing_plan_id', $proMax->id)
                    ->where('status', 'pending')
                    ->update($proofUpdate);
            }
        });
    }

    public function down(): void
    {
        // One-way commercial consolidation. Original plan assignments and
        // pending selections cannot be reconstructed safely after migration.
    }
};