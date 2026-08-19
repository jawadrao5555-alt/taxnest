<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// What's New update type (Task 1286): 'feature' (Naya Feature) or
// 'improvement' (Behtari / Masla Hal). Nullable — legacy rows without a
// type default to 'improvement' via the model accessor. Idempotent
// per-column guard — prod schema drift convention (some live rows are
// marked "Ran" without the column actually existing).
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('app_updates') || Schema::hasColumn('app_updates', 'type')) {
            return;
        }
        Schema::table('app_updates', function (Blueprint $table) {
            $table->string('type', 20)->nullable()->after('audience');
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('app_updates') && Schema::hasColumn('app_updates', 'type')) {
            Schema::table('app_updates', function (Blueprint $table) {
                $table->dropColumn('type');
            });
        }
    }
};
