<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('admin_announcements', function (Blueprint $table) {
            // Legacy rows remain universal; existing specific-company targeting is unchanged.
            $table->string('audience_panel', 10)->default('all');
            $table->json('target_categories')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('admin_announcements', function (Blueprint $table) {
            $table->dropColumn(['audience_panel', 'target_categories']);
        });
    }
};
