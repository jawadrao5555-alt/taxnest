<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// KOT Full Mode (ZFC customer feedback, Jul 2026): when ON, every KOT prints the
// COMPLETE order (new rows flagged NEW) instead of the delta-only ticket that
// read as a "tiny/incomplete" slip to kitchens that re-fire from full tickets.
// Idempotent + hasColumn-guarded (prod self-heal parity).
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('companies', 'pos_kot_full_mode')) {
            Schema::table('companies', function (Blueprint $table) {
                $table->boolean('pos_kot_full_mode')->default(false);
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('companies', 'pos_kot_full_mode')) {
            Schema::table('companies', function (Blueprint $table) {
                $table->dropColumn('pos_kot_full_mode');
            });
        }
    }
};
