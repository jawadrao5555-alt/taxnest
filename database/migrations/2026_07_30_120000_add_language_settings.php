<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Language system (owner, 30 Jul 2026): company default language + per-user override.
// 'ur' = Roman Urdu (current default tone), 'en' = pure English.
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('companies', 'default_language')) {
            Schema::table('companies', function (Blueprint $table) {
                $table->string('default_language', 5)->default('ur');
            });
        }
        if (!Schema::hasColumn('users', 'language')) {
            Schema::table('users', function (Blueprint $table) {
                $table->string('language', 5)->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('companies', 'default_language')) {
            Schema::table('companies', fn (Blueprint $t) => $t->dropColumn('default_language'));
        }
        if (Schema::hasColumn('users', 'language')) {
            Schema::table('users', fn (Blueprint $t) => $t->dropColumn('language'));
        }
    }
};
