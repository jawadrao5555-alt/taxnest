<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Bulk-returned duration tracking (16 Aug 2026): stamp WHEN a rider's bill was
// marked returned so the Deliveries board and rider report can distinguish a
// delivered delivery from a returned one with a proper timestamp.
// Idempotent hasColumn guard — cPanel PROD schema-drift self-heal convention.
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('pos_transactions')) {
            return;
        }
        Schema::table('pos_transactions', function (Blueprint $table) {
            if (!Schema::hasColumn('pos_transactions', 'returned_at')) {
                $table->timestamp('returned_at')->nullable();
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('pos_transactions')) {
            return;
        }
        Schema::table('pos_transactions', function (Blueprint $table) {
            if (Schema::hasColumn('pos_transactions', 'returned_at')) {
                $table->dropColumn('returned_at');
            }
        });
    }
};
