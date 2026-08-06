<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SAFETY / SELF-HEAL MIGRATION (idempotent) — companies + dashboard tables.
 *
 * The `companies` table has grown ~85 columns across dozens of migrations.
 * On production cPanel some add-column migrations were recorded as "Ran"
 * without the column actually landing (squashed history / partial failure),
 * which 500s many pages — including several SaaS-admin pages that read or
 * filter on those columns (product_type, status, franchise_id, feature
 * flags, etc.).
 *
 * This migration re-checks every column the application relies on and adds
 * any that are missing, matching the development schema's types/defaults.
 * Each column is wrapped in a Schema::hasColumn() guard, so it is fully
 * idempotent: `php artisan migrate --force` heals a stale schema without
 * touching a healthy one. Core columns (id, name, ntn, timestamps,
 * deleted_at) are intentionally excluded — they always exist.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('companies')) {
            Schema::table('companies', function (Blueprint $table) {
                $add = function (string $col, callable $def) use ($table) {
                    if (!Schema::hasColumn('companies', $col)) {
                        $def($table);
                    }
                };

                // --- Identity / contact ---
                $add('owner_name', fn ($t) => $t->string('owner_name')->nullable());
                $add('registration_no', fn ($t) => $t->string('registration_no', 100)->nullable());
                $add('cnic', fn ($t) => $t->string('cnic', 20)->nullable());
                $add('email', fn ($t) => $t->string('email')->nullable());
                $add('website', fn ($t) => $t->string('website')->nullable());
                $add('phone', fn ($t) => $t->string('phone')->nullable());
                $add('mobile', fn ($t) => $t->string('mobile', 50)->nullable());
                $add('address', fn ($t) => $t->string('address')->nullable());
                $add('city', fn ($t) => $t->string('city', 100)->nullable());
                $add('province', fn ($t) => $t->string('province')->nullable());
                $add('business_activity', fn ($t) => $t->string('business_activity')->nullable());
                $add('business_category', fn ($t) => $t->string('business_category', 60)->nullable());

                // --- Branding / receipt ---
                $add('logo_path', fn ($t) => $t->string('logo_path')->nullable());
                $add('print_paper_size', fn ($t) => $t->string('print_paper_size', 20)->default('thermal'));
                $add('receipt_footer_note', fn ($t) => $t->string('receipt_footer_note')->nullable());
                $add('receipt_printer_size', fn ($t) => $t->string('receipt_printer_size', 10)->default('80mm'));
                $add('pos_receipt_show_tax', fn ($t) => $t->boolean('pos_receipt_show_tax')->default(true));

                // --- POS UI / feature flags ---
                $add('feature_flags', fn ($t) => $t->json('feature_flags')->nullable());
                $add('use_universal_pos', fn ($t) => $t->boolean('use_universal_pos')->default(false));
                $add('pos_ui_density', fn ($t) => $t->string('pos_ui_density', 20)->default('standard'));
                $add('pos_type', fn ($t) => $t->string('pos_type', 20)->default('general'));
                $add('pos_theme', fn ($t) => $t->string('pos_theme', 30)->default('purple'));
                $add('pos_dashboard_style', fn ($t) => $t->string('pos_dashboard_style', 30)->default('default'));
                $add('pos_guided_flow_enabled', fn ($t) => $t->boolean('pos_guided_flow_enabled')->default(false));

                // --- Lifecycle / status ---
                $add('compliance_score', fn ($t) => $t->integer('compliance_score')->default(100));
                $add('deleted_reason', fn ($t) => $t->string('deleted_reason')->nullable());
                $add('is_internal_account', fn ($t) => $t->boolean('is_internal_account')->default(false));
                $add('onboarding_completed', fn ($t) => $t->boolean('onboarding_completed')->default(false));
                $add('suspended_at', fn ($t) => $t->timestamp('suspended_at')->nullable());
                $add('company_status', fn ($t) => $t->string('company_status')->default('active'));
                $add('force_watermark', fn ($t) => $t->boolean('force_watermark')->default(false));
                $add('status', fn ($t) => $t->string('status', 20)->default('approved'));
                $add('product_type', fn ($t) => $t->string('product_type', 10)->default('di'));
                $add('franchise_id', fn ($t) => $t->unsignedBigInteger('franchise_id')->nullable());
                $add('sector_type', fn ($t) => $t->string('sector_type')->default('Retail'));
                $add('standard_tax_rate', fn ($t) => $t->decimal('standard_tax_rate', 5, 2)->default(18.00));

                // --- Invoice numbering / overrides ---
                $add('invoice_number_prefix', fn ($t) => $t->string('invoice_number_prefix', 20)->nullable());
                $add('next_invoice_number', fn ($t) => $t->integer('next_invoice_number')->default(1));
                $add('next_local_invoice_number', fn ($t) => $t->integer('next_local_invoice_number')->default(1));
                $add('invoice_limit_override', fn ($t) => $t->integer('invoice_limit_override')->nullable());
                $add('user_limit_override', fn ($t) => $t->integer('user_limit_override')->nullable());
                $add('branch_limit_override', fn ($t) => $t->integer('branch_limit_override')->nullable());

                // --- FBR (DI) ---
                $add('fbr_token', fn ($t) => $t->string('fbr_token')->nullable());
                $add('token_expires_at', fn ($t) => $t->timestamp('token_expires_at')->nullable());
                $add('token_expiry_date', fn ($t) => $t->date('token_expiry_date')->nullable());
                $add('last_successful_submission', fn ($t) => $t->timestamp('last_successful_submission')->nullable());
                $add('fbr_connection_status', fn ($t) => $t->string('fbr_connection_status')->default('unknown'));
                $add('fbr_environment', fn ($t) => $t->string('fbr_environment')->default('sandbox'));
                $add('fbr_sandbox_url', fn ($t) => $t->string('fbr_sandbox_url', 500)->nullable());
                $add('fbr_production_url', fn ($t) => $t->string('fbr_production_url', 500)->nullable());
                $add('fbr_sandbox_token', fn ($t) => $t->text('fbr_sandbox_token')->nullable());
                $add('fbr_production_token', fn ($t) => $t->text('fbr_production_token')->nullable());
                $add('fbr_registration_no', fn ($t) => $t->string('fbr_registration_no')->nullable());
                $add('fbr_business_name', fn ($t) => $t->string('fbr_business_name')->nullable());

                // --- FBR POS ---
                $add('fbr_pos_enabled', fn ($t) => $t->boolean('fbr_pos_enabled')->default(false));
                $add('fbr_reporting_enabled', fn ($t) => $t->boolean('fbr_reporting_enabled')->default(false));
                $add('fbr_pos_id', fn ($t) => $t->string('fbr_pos_id')->nullable());
                $add('fbr_pos_token', fn ($t) => $t->string('fbr_pos_token')->nullable());
                $add('fbr_pos_environment', fn ($t) => $t->string('fbr_pos_environment')->default('sandbox'));

                // --- PRA POS ---
                $add('inventory_enabled', fn ($t) => $t->boolean('inventory_enabled')->default(false));
                $add('pra_reporting_enabled', fn ($t) => $t->boolean('pra_reporting_enabled')->default(false));
                $add('pra_environment', fn ($t) => $t->string('pra_environment')->default('sandbox'));
                $add('pra_pos_id', fn ($t) => $t->string('pra_pos_id')->nullable());
                $add('pra_access_code', fn ($t) => $t->string('pra_access_code')->nullable());
                $add('pra_production_token', fn ($t) => $t->string('pra_production_token')->nullable());
                $add('pra_proxy_url', fn ($t) => $t->string('pra_proxy_url')->nullable());

                // --- Restaurant / KOT ---
                $add('kds_enabled', fn ($t) => $t->boolean('kds_enabled')->default(true));
                $add('restaurant_mode', fn ($t) => $t->boolean('restaurant_mode')->default(false));
                $add('kitchen_printer_enabled', fn ($t) => $t->boolean('kitchen_printer_enabled')->default(false));
                $add('print_on_hold', fn ($t) => $t->boolean('print_on_hold')->default(false));
                $add('print_on_pay', fn ($t) => $t->boolean('print_on_pay')->default(true));
                $add('auto_print_kot', fn ($t) => $t->boolean('auto_print_kot')->default(false));
                $add('kot_reprint_enabled', fn ($t) => $t->boolean('kot_reprint_enabled')->default(true));

                // --- Desktop sync agent ---
                $add('agent_api_key', fn ($t) => $t->string('agent_api_key', 80)->nullable());
                $add('agent_last_seen', fn ($t) => $t->timestamp('agent_last_seen')->nullable());
                $add('agent_version', fn ($t) => $t->string('agent_version', 20)->nullable());
                $add('agent_enabled', fn ($t) => $t->boolean('agent_enabled')->default(false));

                // --- Security / discount limits ---
                $add('confidential_pin', fn ($t) => $t->string('confidential_pin')->nullable());
                $add('manager_override_pin', fn ($t) => $t->string('manager_override_pin')->nullable());
                $add('cashier_discount_limit', fn ($t) => $t->decimal('cashier_discount_limit', 5, 2)->default(10.00));
                $add('manager_discount_limit', fn ($t) => $t->decimal('manager_discount_limit', 5, 2)->default(50.00));

                // --- Rider tracking shop pin (ZFC, Aug 2026) ---
                $add('shop_lat', fn ($t) => $t->decimal('shop_lat', 10, 7)->nullable());
                $add('shop_lng', fn ($t) => $t->decimal('shop_lng', 10, 7)->nullable());
            });
        }

        // --- Dashboard read columns on transaction tables (defensive, rarely missing) ---
        if (Schema::hasTable('invoices')) {
            Schema::table('invoices', function (Blueprint $table) {
                if (!Schema::hasColumn('invoices', 'fbr_status')) {
                    $table->string('fbr_status', 30)->nullable();
                }
                if (!Schema::hasColumn('invoices', 'total_amount')) {
                    $table->decimal('total_amount', 15, 2)->nullable();
                }
            });
        }

        if (Schema::hasTable('pos_transactions')) {
            Schema::table('pos_transactions', function (Blueprint $table) {
                if (!Schema::hasColumn('pos_transactions', 'status')) {
                    $table->string('status', 30)->nullable();
                }
                if (!Schema::hasColumn('pos_transactions', 'total_amount')) {
                    $table->decimal('total_amount', 15, 2)->nullable();
                }
            });
        }

        if (Schema::hasTable('fbr_pos_transactions')) {
            Schema::table('fbr_pos_transactions', function (Blueprint $table) {
                if (!Schema::hasColumn('fbr_pos_transactions', 'total_amount')) {
                    $table->decimal('total_amount', 15, 2)->nullable();
                }
            });
        }
    }

    public function down(): void
    {
        // No-op: self-heal migration; never drops columns owned by other migrations.
    }
};
