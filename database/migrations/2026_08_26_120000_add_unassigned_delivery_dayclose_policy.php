<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            if (! Schema::hasColumn('companies', 'pos_dayclose_unassigned_delivery_action')) {
                $table->string('pos_dayclose_unassigned_delivery_action', 10)
                    ->default('allow')
                    ->after('pos_business_day_cutoff');
            }
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            if (Schema::hasColumn('companies', 'pos_dayclose_unassigned_delivery_action')) {
                $table->dropColumn('pos_dayclose_unassigned_delivery_action');
            }
        });
    }
};