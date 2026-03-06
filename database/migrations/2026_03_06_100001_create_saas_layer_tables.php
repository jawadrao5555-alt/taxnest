<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('admin_users')) {
            Schema::create('admin_users', function (Blueprint $table) {
                $table->id();
                $table->string('name');
                $table->string('email')->unique();
                $table->string('password');
                $table->string('role', 30)->default('admin');
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('franchises')) {
            Schema::create('franchises', function (Blueprint $table) {
                $table->id();
                $table->string('name');
                $table->string('email')->unique();
                $table->string('phone', 30)->nullable();
                $table->decimal('commission_rate', 5, 2)->default(0);
                $table->string('status', 20)->default('active');
                $table->string('password')->nullable();
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('admin_audit_logs')) {
            Schema::create('admin_audit_logs', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('admin_id')->nullable();
                $table->string('action');
                $table->string('target_type')->nullable();
                $table->unsignedBigInteger('target_id')->nullable();
                $table->json('metadata')->nullable();
                $table->timestamps();
                $table->index(['admin_id']);
                $table->index(['target_type', 'target_id']);
            });
        }

        if (!Schema::hasTable('company_usage_stats')) {
            Schema::create('company_usage_stats', function (Blueprint $table) {
                $table->id();
                $table->foreignId('company_id')->unique()->constrained('companies')->onDelete('cascade');
                $table->integer('total_pos_transactions')->default(0);
                $table->decimal('total_sales_amount', 15, 2)->default(0);
                $table->integer('active_terminals')->default(0);
                $table->integer('active_users')->default(0);
                $table->integer('inventory_items')->default(0);
                $table->timestamp('last_activity_at')->nullable();
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('system_controls')) {
            Schema::create('system_controls', function (Blueprint $table) {
                $table->id();
                $table->string('key')->unique();
                $table->string('value')->default('enabled');
                $table->string('description')->nullable();
                $table->unsignedBigInteger('updated_by')->nullable();
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('subscription_invoices')) {
            Schema::create('subscription_invoices', function (Blueprint $table) {
                $table->id();
                $table->foreignId('subscription_id')->constrained('subscriptions')->onDelete('cascade');
                $table->foreignId('company_id')->constrained('companies')->onDelete('cascade');
                $table->decimal('amount', 12, 2);
                $table->string('status', 20)->default('pending');
                $table->date('due_date');
                $table->timestamp('paid_at')->nullable();
                $table->timestamps();
                $table->index(['company_id', 'status']);
            });
        }

        if (!Schema::hasTable('subscription_payments')) {
            Schema::create('subscription_payments', function (Blueprint $table) {
                $table->id();
                $table->foreignId('subscription_invoice_id')->constrained('subscription_invoices')->onDelete('cascade');
                $table->decimal('amount', 12, 2);
                $table->string('payment_method', 50)->nullable();
                $table->string('transaction_ref')->nullable();
                $table->timestamp('paid_at')->nullable();
                $table->timestamps();
            });
        }

        if (!Schema::hasColumn('companies', 'status')) {
            Schema::table('companies', function (Blueprint $table) {
                $table->string('status', 20)->default('approved')->after('company_status');
            });
        }

        if (!Schema::hasColumn('companies', 'franchise_id')) {
            Schema::table('companies', function (Blueprint $table) {
                $table->unsignedBigInteger('franchise_id')->nullable()->after('status');
                $table->foreign('franchise_id')->references('id')->on('franchises')->nullOnDelete();
            });
        }

        if (!Schema::hasColumn('pricing_plans', 'max_terminals')) {
            Schema::table('pricing_plans', function (Blueprint $table) {
                $table->integer('max_terminals')->nullable()->after('branch_limit');
                $table->integer('max_users')->nullable()->after('max_terminals');
                $table->integer('max_products')->nullable()->after('max_users');
                $table->boolean('inventory_enabled')->default(true)->after('max_products');
                $table->boolean('reports_enabled')->default(true)->after('inventory_enabled');
                $table->decimal('price_monthly', 12, 2)->nullable()->after('reports_enabled');
            });
        }

        DB::table('admin_users')->insertOrIgnore([
            'name' => 'Super Admin',
            'email' => 'admin@taxnest.com',
            'password' => Hash::make('Admin@12345'),
            'role' => 'super_admin',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $controls = [
            ['key' => 'pra_submissions', 'value' => 'enabled', 'description' => 'Enable/disable PRA submissions globally'],
            ['key' => 'pos_system', 'value' => 'enabled', 'description' => 'Enable/disable POS system globally'],
            ['key' => 'maintenance_mode', 'value' => 'disabled', 'description' => 'Enable/disable maintenance mode'],
            ['key' => 'new_registrations', 'value' => 'enabled', 'description' => 'Enable/disable new company registrations'],
        ];
        foreach ($controls as $control) {
            DB::table('system_controls')->insertOrIgnore(array_merge($control, [
                'created_at' => now(),
                'updated_at' => now(),
            ]));
        }

        DB::table('pricing_plans')->where('name', 'Starter')->update([
            'max_terminals' => 1,
            'max_users' => 3,
            'max_products' => 50,
            'inventory_enabled' => false,
            'reports_enabled' => true,
            'price_monthly' => DB::raw('price'),
        ]);
        DB::table('pricing_plans')->where('name', 'Business')->update([
            'max_terminals' => 5,
            'max_users' => 10,
            'max_products' => 500,
            'inventory_enabled' => true,
            'reports_enabled' => true,
            'price_monthly' => DB::raw('price'),
        ]);
        DB::table('pricing_plans')->where('name', 'Enterprise')->update([
            'max_terminals' => null,
            'max_users' => null,
            'max_products' => null,
            'inventory_enabled' => true,
            'reports_enabled' => true,
            'price_monthly' => DB::raw('price'),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('subscription_payments');
        Schema::dropIfExists('subscription_invoices');
        Schema::dropIfExists('system_controls');
        Schema::dropIfExists('company_usage_stats');
        Schema::dropIfExists('admin_audit_logs');

        if (Schema::hasColumn('companies', 'franchise_id')) {
            Schema::table('companies', function (Blueprint $table) {
                $table->dropForeign(['franchise_id']);
                $table->dropColumn('franchise_id');
            });
        }
        if (Schema::hasColumn('companies', 'status')) {
            Schema::table('companies', function (Blueprint $table) {
                $table->dropColumn('status');
            });
        }

        Schema::dropIfExists('franchises');
        Schema::dropIfExists('admin_users');
    }
};
