<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pricing_plans', function (Blueprint $table) {
            if (!Schema::hasColumn('pricing_plans', 'user_limit')) {
                $table->integer('user_limit')->nullable()->after('invoice_limit');
            }
            if (!Schema::hasColumn('pricing_plans', 'branch_limit')) {
                $table->integer('branch_limit')->nullable()->after('user_limit');
            }
            if (!Schema::hasColumn('pricing_plans', 'is_trial')) {
                $table->boolean('is_trial')->default(false)->after('branch_limit');
            }
            if (!Schema::hasColumn('pricing_plans', 'features')) {
                $table->text('features')->nullable()->after('is_trial');
            }
        });

        Schema::table('subscriptions', function (Blueprint $table) {
            if (!Schema::hasColumn('subscriptions', 'billing_cycle')) {
                $table->string('billing_cycle', 20)->default('monthly')->after('pricing_plan_id');
            }
            if (!Schema::hasColumn('subscriptions', 'discount_percent')) {
                $table->decimal('discount_percent', 5, 2)->default(0)->after('billing_cycle');
            }
            if (!Schema::hasColumn('subscriptions', 'final_price')) {
                $table->decimal('final_price', 12, 2)->nullable()->after('discount_percent');
            }
        });
    }

    public function down(): void
    {
        Schema::table('pricing_plans', function (Blueprint $table) {
            $table->dropColumn(['user_limit', 'branch_limit', 'is_trial', 'features']);
        });

        Schema::table('subscriptions', function (Blueprint $table) {
            $table->dropColumn(['billing_cycle', 'discount_percent', 'final_price']);
        });
    }
};
