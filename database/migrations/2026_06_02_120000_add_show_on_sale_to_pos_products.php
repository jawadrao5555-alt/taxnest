<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pos_products', function (Blueprint $table) {
            if (!Schema::hasColumn('pos_products', 'show_on_sale')) {
                $table->boolean('show_on_sale')->default(true)->after('is_active');
            }
        });
    }

    public function down(): void
    {
        Schema::table('pos_products', function (Blueprint $table) {
            if (Schema::hasColumn('pos_products', 'show_on_sale')) {
                $table->dropColumn('show_on_sale');
            }
        });
    }
};
