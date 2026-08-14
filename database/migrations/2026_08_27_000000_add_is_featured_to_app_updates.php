<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Featured "bara elaan" mode for What's New (Task 722): a flagged update
// renders as a celebratory hero popup (gradient header, badge, CTA) on the
// POS/FBR panels instead of the plain popup. Idempotent per-column guard —
// prod schema drift convention (some live rows are marked "Ran" without
// the column actually existing).
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('app_updates') || Schema::hasColumn('app_updates', 'is_featured')) {
            return;
        }
        Schema::table('app_updates', function (Blueprint $table) {
            $table->boolean('is_featured')->default(false)->after('is_published');
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('app_updates') && Schema::hasColumn('app_updates', 'is_featured')) {
            Schema::table('app_updates', function (Blueprint $table) {
                $table->dropColumn('is_featured');
            });
        }
    }
};
