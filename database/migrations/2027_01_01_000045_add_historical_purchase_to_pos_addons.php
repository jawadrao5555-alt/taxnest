<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('pos_addons') && !Schema::hasColumn('pos_addons', 'historical_purchase')) {
            Schema::table('pos_addons', fn (Blueprint $table) => $table->json('historical_purchase')->nullable());
        }
    }

    public function down(): void
    {
        // Retain the evidence and its connection to each historical entitlement.
    }
};
