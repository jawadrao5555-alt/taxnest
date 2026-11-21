<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('restaurant_orders', function (Blueprint $table): void {
            $table->string('held_billing_stream', 5)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('restaurant_orders', function (Blueprint $table): void {
            $table->dropColumn('held_billing_stream');
        });
    }
};
