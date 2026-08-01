<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Item-wise made/unmade record on cancel (ZFC, 2 Aug 2026): cancel karte
 * waqt cashier har item par bataye "ban gaya tha ya nahi" — waste audit ke
 * liye. NULL = kabhi poochha nahi gaya (purane cancels).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('restaurant_order_items', function (Blueprint $table) {
            if (!Schema::hasColumn('restaurant_order_items', 'was_made')) {
                $table->boolean('was_made')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('restaurant_order_items', function (Blueprint $table) {
            if (Schema::hasColumn('restaurant_order_items', 'was_made')) {
                $table->dropColumn('was_made');
            }
        });
    }
};
