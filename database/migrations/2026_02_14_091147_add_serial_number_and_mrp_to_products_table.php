<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->string('serial_number', 100)->nullable()->after('sro_reference');
            $table->decimal('mrp', 14, 2)->nullable()->after('serial_number');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn(['serial_number', 'mrp']);
        });
    }
};
