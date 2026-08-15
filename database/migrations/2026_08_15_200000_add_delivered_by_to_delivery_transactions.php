<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Task 786: stamp who closed an unassigned delivery bill (delivered_by = pos user id).
// Idempotent per-column hasColumn guards — cPanel PROD schema-drift self-heal convention.
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('pos_transactions') && !Schema::hasColumn('pos_transactions', 'delivered_by')) {
            Schema::table('pos_transactions', function (Blueprint $table) {
                $table->unsignedBigInteger('delivered_by')->nullable()->after('delivered_at');
            });
        }

        if (Schema::hasTable('fbr_pos_transactions') && !Schema::hasColumn('fbr_pos_transactions', 'delivered_by')) {
            Schema::table('fbr_pos_transactions', function (Blueprint $table) {
                $table->unsignedBigInteger('delivered_by')->nullable()->after('delivered_at');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('pos_transactions') && Schema::hasColumn('pos_transactions', 'delivered_by')) {
            Schema::table('pos_transactions', function (Blueprint $table) {
                $table->dropColumn('delivered_by');
            });
        }

        if (Schema::hasTable('fbr_pos_transactions') && Schema::hasColumn('fbr_pos_transactions', 'delivered_by')) {
            Schema::table('fbr_pos_transactions', function (Blueprint $table) {
                $table->dropColumn('delivered_by');
            });
        }
    }
};
