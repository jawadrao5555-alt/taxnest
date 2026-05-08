<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('pos_transactions', 'notes')) {
            Schema::table('pos_transactions', function (Blueprint $table) {
                $table->text('notes')->nullable()->after('reprint_count');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('pos_transactions', 'notes')) {
            Schema::table('pos_transactions', function (Blueprint $table) {
                $table->dropColumn('notes');
            });
        }
    }
};
