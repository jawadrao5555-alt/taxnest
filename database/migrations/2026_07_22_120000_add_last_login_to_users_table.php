<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Last Login tracking: stamped on every successful login (web/pos/fbrpos
 * guards) via the Login event listener in AppServiceProvider. Displayed in
 * the SaaS admin company details page ("Team & Last Logins" card).
 *
 * Idempotent per-column guards — safe to re-run on PROD (schema-drift rule).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('users', 'last_login_at')) {
            Schema::table('users', function (Blueprint $table) {
                $table->timestamp('last_login_at')->nullable()->after('remember_token');
            });
        }

        if (!Schema::hasColumn('users', 'last_login_ip')) {
            Schema::table('users', function (Blueprint $table) {
                $table->string('last_login_ip', 45)->nullable()->after('last_login_at');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('users', 'last_login_ip')) {
            Schema::table('users', function (Blueprint $table) {
                $table->dropColumn('last_login_ip');
            });
        }

        if (Schema::hasColumn('users', 'last_login_at')) {
            Schema::table('users', function (Blueprint $table) {
                $table->dropColumn('last_login_at');
            });
        }
    }
};
