<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('app_updates', 'image_path')) {
            Schema::table('app_updates', function (Blueprint $table) {
                $table->string('image_path')->nullable()->after('points');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('app_updates', 'image_path')) {
            Schema::table('app_updates', function (Blueprint $table) {
                $table->dropColumn('image_path');
            });
        }
    }
};
