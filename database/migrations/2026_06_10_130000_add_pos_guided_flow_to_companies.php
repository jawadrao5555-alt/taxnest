<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            if (!Schema::hasColumn('companies', 'pos_guided_flow_enabled')) {
                // Opt-in guided keyboard billing flow for the universal POS sale screen.
                // Default FALSE keeps every existing company's keyboard behaviour unchanged.
                $table->boolean('pos_guided_flow_enabled')->default(false)->after('kot_reprint_enabled');
            }
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            if (Schema::hasColumn('companies', 'pos_guided_flow_enabled')) {
                $table->dropColumn('pos_guided_flow_enabled');
            }
        });
    }
};
