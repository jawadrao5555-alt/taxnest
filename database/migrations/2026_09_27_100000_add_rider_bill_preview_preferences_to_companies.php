<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('companies') && !Schema::hasColumn('companies', 'rider_bill_preview_prefs')) {
            Schema::table('companies', function (Blueprint $table) {
                // Versioned JSON is deliberately separate from receipt preferences:
                // this is an access-control policy, not a presentation preference.
                $table->json('rider_bill_preview_prefs')->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('companies') && Schema::hasColumn('companies', 'rider_bill_preview_prefs')) {
            Schema::table('companies', fn (Blueprint $table) => $table->dropColumn('rider_bill_preview_prefs'));
        }
    }
};