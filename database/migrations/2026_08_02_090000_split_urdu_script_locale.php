<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Three-language system (owner, 2 Aug 2026): 'ur' becomes real Urdu script and
// Roman Urdu moves to the new 'rur' locale. Users/companies who had chosen 'ur'
// were seeing ROMAN Urdu, so remap their stored preference to 'rur' — nobody's
// UI may flip to Urdu script because of this deploy. Column stays varchar(5).
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('users', 'language')) {
            DB::table('users')->where('language', 'ur')->update(['language' => 'rur']);
        }
        if (Schema::hasColumn('companies', 'default_language')) {
            DB::table('companies')->where('default_language', 'ur')->update(['default_language' => 'rur']);
            // New companies must also default to Roman Urdu, not script.
            Schema::table('companies', function ($table) {
                $table->string('default_language', 5)->default('rur')->change();
            });
        }
    }

    public function down(): void
    {
        // One-way semantic split — reversing would conflate Roman & script users.
    }
};
