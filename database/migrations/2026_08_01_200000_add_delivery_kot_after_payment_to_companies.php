<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Customer request (voice note, 1 Aug 2026): kuch delivery customers advance
// payment ke baghair order confirm nahi karte — is liye option chahiye ke
// PROVISIONAL delivery bill par KOT tab tak na nikle jab tak payment confirm
// (bill promote/finalize) na ho. Default OFF = existing behaviour unchanged.
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('companies', 'delivery_kot_after_payment')) {
            Schema::table('companies', function (Blueprint $table) {
                $table->boolean('delivery_kot_after_payment')->default(false);
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('companies', 'delivery_kot_after_payment')) {
            Schema::table('companies', function (Blueprint $table) {
                $table->dropColumn('delivery_kot_after_payment');
            });
        }
    }
};
