<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

/**
 * SAFETY / SELF-HEAL MIGRATION (idempotent).
 *
 * The Admin "Subscription Plans" (/admin/plans) and "Subscriptions"
 * (/admin/subscriptions) pages crash with a 500 on production whenever a
 * pricing_plans / subscriptions column that the controllers + Blade views
 * reference is missing — typically because an earlier add-column migration was
 * recorded as "Ran" without actually applying the column (squashed history,
 * partial failure, or a guard that skipped sibling columns).
 *
 * This migration re-checks every column those two pages depend on and adds any
 * that are missing. It is fully idempotent (each column is wrapped in a
 * Schema::hasColumn guard), so `php artisan migrate --force` can safely heal a
 * stale production schema without touching a healthy one.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('pricing_plans')) {
            Schema::table('pricing_plans', function (Blueprint $table) {
                if (!Schema::hasColumn('pricing_plans', 'user_limit')) {
                    $table->integer('user_limit')->nullable();
                }
                if (!Schema::hasColumn('pricing_plans', 'branch_limit')) {
                    $table->integer('branch_limit')->nullable();
                }
                if (!Schema::hasColumn('pricing_plans', 'is_trial')) {
                    $table->boolean('is_trial')->default(false);
                }
                if (!Schema::hasColumn('pricing_plans', 'features')) {
                    $table->text('features')->nullable();
                }
                if (!Schema::hasColumn('pricing_plans', 'max_terminals')) {
                    $table->integer('max_terminals')->nullable();
                }
                if (!Schema::hasColumn('pricing_plans', 'max_users')) {
                    $table->integer('max_users')->nullable();
                }
                if (!Schema::hasColumn('pricing_plans', 'max_products')) {
                    $table->integer('max_products')->nullable();
                }
                if (!Schema::hasColumn('pricing_plans', 'inventory_enabled')) {
                    $table->boolean('inventory_enabled')->default(true);
                }
                if (!Schema::hasColumn('pricing_plans', 'reports_enabled')) {
                    $table->boolean('reports_enabled')->default(true);
                }
                if (!Schema::hasColumn('pricing_plans', 'price_monthly')) {
                    $table->decimal('price_monthly', 12, 2)->nullable();
                }
                if (!Schema::hasColumn('pricing_plans', 'product_type')) {
                    $table->string('product_type', 20)->default('di');
                }
            });

            // Backfill any rows missing a product_type so the tab queries return them.
            DB::table('pricing_plans')->whereNull('product_type')->orWhere('product_type', '')
                ->update(['product_type' => 'di']);
        }

        if (Schema::hasTable('subscriptions')) {
            Schema::table('subscriptions', function (Blueprint $table) {
                if (!Schema::hasColumn('subscriptions', 'billing_cycle')) {
                    $table->string('billing_cycle', 20)->default('monthly');
                }
                if (!Schema::hasColumn('subscriptions', 'discount_percent')) {
                    $table->decimal('discount_percent', 5, 2)->default(0);
                }
                if (!Schema::hasColumn('subscriptions', 'final_price')) {
                    $table->decimal('final_price', 12, 2)->nullable();
                }
                if (!Schema::hasColumn('subscriptions', 'trial_ends_at')) {
                    $table->timestamp('trial_ends_at')->nullable();
                }
                if (!Schema::hasColumn('subscriptions', 'override_type')) {
                    $table->string('override_type', 20)->default('none');
                }
                if (!Schema::hasColumn('subscriptions', 'override_until')) {
                    $table->dateTime('override_until')->nullable();
                }
                if (!Schema::hasColumn('subscriptions', 'free_invoice_limit')) {
                    $table->unsignedInteger('free_invoice_limit')->nullable();
                }
                if (!Schema::hasColumn('subscriptions', 'override_reason')) {
                    $table->string('override_reason', 255)->nullable();
                }
                if (!Schema::hasColumn('subscriptions', 'override_by')) {
                    $table->unsignedBigInteger('override_by')->nullable();
                }
            });
        }
    }

    public function down(): void
    {
        // No-op: this is a self-heal migration; we never drop columns that other
        // migrations own. Rolling back is intentionally a no-op.
    }
};
