<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // Add branch_id to PRA POS transactions (nullable — backward compatible)
        if (Schema::hasTable('pos_transactions') && !Schema::hasColumn('pos_transactions', 'branch_id')) {
            Schema::table('pos_transactions', function (Blueprint $t) {
                $t->unsignedBigInteger('branch_id')->nullable()->after('company_id');
                $t->index(['company_id', 'branch_id'], 'pos_tx_company_branch_idx');
            });
        }

        // Add branch_id to FBR POS transactions
        if (Schema::hasTable('fbr_pos_transactions') && !Schema::hasColumn('fbr_pos_transactions', 'branch_id')) {
            Schema::table('fbr_pos_transactions', function (Blueprint $t) {
                $t->unsignedBigInteger('branch_id')->nullable()->after('company_id');
                $t->index(['company_id', 'branch_id'], 'fbr_pos_tx_company_branch_idx');
            });
        }

        // Add default_branch_id to users (for auto-assignment on login)
        if (Schema::hasTable('users') && !Schema::hasColumn('users', 'default_branch_id')) {
            Schema::table('users', function (Blueprint $t) {
                $t->unsignedBigInteger('default_branch_id')->nullable()->after('company_id');
                $t->index('default_branch_id');
            });
        }

        // Add branch scope to POS products (NULL = global to company, else branch-specific)
        if (Schema::hasTable('pos_products') && !Schema::hasColumn('pos_products', 'branch_id')) {
            Schema::table('pos_products', function (Blueprint $t) {
                $t->unsignedBigInteger('branch_id')->nullable()->after('company_id');
                $t->index(['company_id', 'branch_id'], 'pos_products_company_branch_idx');
            });
        }

        // Add branch scope to POS customers (NULL = global to company)
        if (Schema::hasTable('pos_customers') && !Schema::hasColumn('pos_customers', 'branch_id')) {
            Schema::table('pos_customers', function (Blueprint $t) {
                $t->unsignedBigInteger('branch_id')->nullable()->after('company_id');
                $t->index(['company_id', 'branch_id'], 'pos_customers_company_branch_idx');
            });
        }

        // Add code + city to branches for nicer display in switcher
        if (Schema::hasTable('branches')) {
            Schema::table('branches', function (Blueprint $t) {
                if (!Schema::hasColumn('branches', 'code')) {
                    $t->string('code', 20)->nullable()->after('name');
                }
                if (!Schema::hasColumn('branches', 'city')) {
                    $t->string('city', 100)->nullable()->after('address');
                }
                if (!Schema::hasColumn('branches', 'is_active')) {
                    $t->boolean('is_active')->default(true)->after('is_head_office');
                }
            });
        }

        // Pivot: users <-> branches (M2M for managers/owners who access multiple branches)
        if (!Schema::hasTable('branch_user')) {
            Schema::create('branch_user', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('user_id');
                $t->unsignedBigInteger('branch_id');
                $t->string('access_level', 20)->default('full'); // full | read_only
                $t->timestamps();
                $t->unique(['user_id', 'branch_id']);
                $t->index('branch_id');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('pos_transactions') && Schema::hasColumn('pos_transactions', 'branch_id')) {
            Schema::table('pos_transactions', function (Blueprint $t) {
                $t->dropIndex('pos_tx_company_branch_idx');
                $t->dropColumn('branch_id');
            });
        }
        if (Schema::hasTable('fbr_pos_transactions') && Schema::hasColumn('fbr_pos_transactions', 'branch_id')) {
            Schema::table('fbr_pos_transactions', function (Blueprint $t) {
                $t->dropIndex('fbr_pos_tx_company_branch_idx');
                $t->dropColumn('branch_id');
            });
        }
        if (Schema::hasTable('users') && Schema::hasColumn('users', 'default_branch_id')) {
            Schema::table('users', function (Blueprint $t) {
                $t->dropColumn('default_branch_id');
            });
        }
        if (Schema::hasTable('pos_products') && Schema::hasColumn('pos_products', 'branch_id')) {
            Schema::table('pos_products', function (Blueprint $t) {
                $t->dropIndex('pos_products_company_branch_idx');
                $t->dropColumn('branch_id');
            });
        }
        if (Schema::hasTable('pos_customers') && Schema::hasColumn('pos_customers', 'branch_id')) {
            Schema::table('pos_customers', function (Blueprint $t) {
                $t->dropIndex('pos_customers_company_branch_idx');
                $t->dropColumn('branch_id');
            });
        }
        Schema::dropIfExists('branch_user');
    }
};
