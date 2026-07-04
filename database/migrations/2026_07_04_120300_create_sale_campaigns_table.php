<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('sale_campaigns')) {
            Schema::create('sale_campaigns', function (Blueprint $table) {
                $table->id();
                $table->string('name')->nullable();
                $table->string('scope')->default('all'); // all | di | pos | fbrpos
                $table->decimal('discount_percent', 5, 2)->default(0);
                $table->dateTime('starts_at')->nullable();
                $table->dateTime('ends_at')->nullable();
                $table->boolean('is_active')->default(true);
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('sale_campaigns');
    }
};
