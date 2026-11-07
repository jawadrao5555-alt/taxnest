<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('users', 'session_generation')) {
            Schema::table('users', fn (Blueprint $table) => $table->char('session_generation', 36)->nullable());
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('users', 'session_generation')) {
            Schema::table('users', fn (Blueprint $table) => $table->dropColumn('session_generation'));
        }
    }
};
