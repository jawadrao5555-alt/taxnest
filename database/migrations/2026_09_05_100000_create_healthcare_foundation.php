<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Healthcare ERP foundation schema (Task 1547).
 *
 * Adds the healthcare product identity to the SHARED platform tables
 * (companies / users / pricing_plans) and creates the two healthcare-owned
 * tables the panel needs before any clinical module exists: departments and
 * the staff-to-department map.
 *
 * Every add is individually hasTable/hasColumn guarded so this migration is
 * idempotent — it can be re-run on a box whose migration row was marked "Ran"
 * without the columns ever landing (the owner's PROD schema-drift history).
 */
return new class extends Migration
{
    public function up(): void
    {
        // ── Company: which kind of healthcare organisation, and which modules
        //    the owner switched on. `health_modules` is the company's OWN set;
        //    the plan's set (pricing_plans.health_modules) caps it at runtime.
        if (Schema::hasTable('companies')) {
            Schema::table('companies', function (Blueprint $table) {
                if (!Schema::hasColumn('companies', 'health_org_type')) {
                    // clinic | hospital | lab | pharmacy
                    $table->string('health_org_type', 20)->nullable()->after('product_type');
                }
                if (!Schema::hasColumn('companies', 'health_modules')) {
                    // JSON array of enabled module keys. NULL = never configured
                    // → HealthModuleService falls back to the org-type defaults.
                    $table->text('health_modules')->nullable()->after('health_org_type');
                }
                if (!Schema::hasColumn('companies', 'health_setup_completed')) {
                    $table->boolean('health_setup_completed')->default(false)->after('health_modules');
                }
            });
        }

        // ── Users: healthcare panel role + department posting + owner-delegated
        //    capability grants. Deliberately separate from pos_role: a person
        //    can never be half a cashier and half a doctor.
        if (Schema::hasTable('users')) {
            Schema::table('users', function (Blueprint $table) {
                if (!Schema::hasColumn('users', 'health_role')) {
                    $table->string('health_role', 32)->nullable()->after('pos_role');
                }
                if (!Schema::hasColumn('users', 'health_department_id')) {
                    // Primary posting. Extra departments live in the pivot.
                    $table->unsignedBigInteger('health_department_id')->nullable()->after('health_role');
                }
                if (!Schema::hasColumn('users', 'health_permissions')) {
                    // NULL = role defaults untouched. A JSON array = the owner's
                    // explicit set for THIS member (expands and restricts).
                    $table->text('health_permissions')->nullable()->after('health_department_id');
                }
            });
        }

        // ── Pricing plans: which modules a package sells (clinic vs hospital)
        //    and how many departments it allows (-1 = unlimited).
        if (Schema::hasTable('pricing_plans')) {
            Schema::table('pricing_plans', function (Blueprint $table) {
                if (!Schema::hasColumn('pricing_plans', 'health_modules')) {
                    $table->text('health_modules')->nullable();
                }
                if (!Schema::hasColumn('pricing_plans', 'health_department_limit')) {
                    $table->integer('health_department_limit')->default(-1);
                }
            });
        }

        // ── Departments: the second boundary (after branch) every healthcare
        //    record is filed under. branch_id nullable = organisation-wide.
        if (!Schema::hasTable('health_departments')) {
            Schema::create('health_departments', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('company_id');
                $table->unsignedBigInteger('branch_id')->nullable();
                $table->string('name');
                $table->string('code', 32)->nullable();
                // opd | ipd | lab | pharmacy | radiology | admin | other
                $table->string('type', 20)->default('opd');
                $table->string('description', 500)->nullable();
                $table->boolean('is_active')->default(true);
                $table->timestamps();

                $table->index(['company_id', 'is_active']);
                $table->index(['company_id', 'branch_id']);
                $table->unique(['company_id', 'code']);
            });
        }

        // ── Staff ↔ department map. A doctor may cover several departments;
        //    the scope service unions this with users.health_department_id.
        if (!Schema::hasTable('health_department_user')) {
            Schema::create('health_department_user', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('health_department_id');
                $table->unsignedBigInteger('user_id');
                $table->timestamps();

                $table->unique(['health_department_id', 'user_id'], 'health_dept_user_unique');
                $table->index('user_id');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('health_department_user');
        Schema::dropIfExists('health_departments');

        if (Schema::hasTable('pricing_plans')) {
            Schema::table('pricing_plans', function (Blueprint $table) {
                foreach (['health_modules', 'health_department_limit'] as $column) {
                    if (Schema::hasColumn('pricing_plans', $column)) {
                        $table->dropColumn($column);
                    }
                }
            });
        }

        if (Schema::hasTable('users')) {
            Schema::table('users', function (Blueprint $table) {
                foreach (['health_permissions', 'health_department_id', 'health_role'] as $column) {
                    if (Schema::hasColumn('users', $column)) {
                        $table->dropColumn($column);
                    }
                }
            });
        }

        if (Schema::hasTable('companies')) {
            Schema::table('companies', function (Blueprint $table) {
                foreach (['health_setup_completed', 'health_modules', 'health_org_type'] as $column) {
                    if (Schema::hasColumn('companies', $column)) {
                        $table->dropColumn($column);
                    }
                }
            });
        }
    }
};
