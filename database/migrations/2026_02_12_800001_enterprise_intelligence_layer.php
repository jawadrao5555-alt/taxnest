<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('users', 'dark_mode')) {
            Schema::table('users', function (Blueprint $table) {
                $table->boolean('dark_mode')->nullable()->default(null)->after('is_active');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('users', 'dark_mode')) {
            Schema::table('users', function (Blueprint $table) {
                $table->dropColumn('dark_mode');
            });
        }
    }
};
