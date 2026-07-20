<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Dine-In Auto KOT (owner, Jul 2026): with this ON, the cashier's table
 * select becomes the LAST step of a dine-in order — the order auto-holds,
 * the KOT auto-fires to the kitchen, and the bill lands in Recall for
 * payment. Idempotent per-column guard (prod self-heal pattern).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('companies', 'dine_in_auto_kot')) {
            Schema::table('companies', function (Blueprint $table) {
                $table->boolean('dine_in_auto_kot')->default(false);
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('companies', 'dine_in_auto_kot')) {
            Schema::table('companies', function (Blueprint $table) {
                $table->dropColumn('dine_in_auto_kot');
            });
        }
    }
};
