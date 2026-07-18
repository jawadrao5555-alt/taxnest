<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Owner request (Jul 2026): cashier should see HOW LONG a table has been
 * occupied/reserved. occupied_since is stamped when a table flips to
 * 'occupied' (held order / waiter order) and cleared on every free path.
 * Reserved (amber) elapsed time reuses the existing locked_at column.
 *
 * Idempotent per PROD schema-drift rules (hasTable/hasColumn guards).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('restaurant_tables')) {
            return;
        }
        if (!Schema::hasColumn('restaurant_tables', 'occupied_since')) {
            Schema::table('restaurant_tables', function (Blueprint $table) {
                $table->timestamp('occupied_since')->nullable()->after('locked_at');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('restaurant_tables') && Schema::hasColumn('restaurant_tables', 'occupied_since')) {
            Schema::table('restaurant_tables', function (Blueprint $table) {
                $table->dropColumn('occupied_since');
            });
        }
    }
};
