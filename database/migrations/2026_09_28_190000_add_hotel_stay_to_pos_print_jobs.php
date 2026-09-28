<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('pos_print_jobs') && !Schema::hasColumn('pos_print_jobs', 'hotel_stay_id')) {
            Schema::table('pos_print_jobs', function (Blueprint $table) {
                $table->unsignedBigInteger('hotel_stay_id')->nullable()->index();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('pos_print_jobs', 'hotel_stay_id')) {
            Schema::table('pos_print_jobs', function (Blueprint $table) {
                $table->dropColumn('hotel_stay_id');
            });
        }
    }
};
