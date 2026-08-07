<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Billing Scope visibility rule (owner request 07 Aug 2026): scope set karne
 * ka ikhtiyar by default sirf company OWNER (base role company_admin) ke paas
 * rahe; yeh switch ON karke owner apne managers/admins ko bhi ijazat de sakta
 * hai. Idempotent + hasColumn-guarded (cPanel PROD schema-drift convention).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('companies')) {
            return;
        }
        if (!Schema::hasColumn('companies', 'billing_scope_admin_enabled')) {
            Schema::table('companies', function (Blueprint $table) {
                $table->boolean('billing_scope_admin_enabled')->default(false)->after('pra_reporting_enabled');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('companies') && Schema::hasColumn('companies', 'billing_scope_admin_enabled')) {
            Schema::table('companies', function (Blueprint $table) {
                $table->dropColumn('billing_scope_admin_enabled');
            });
        }
    }
};
