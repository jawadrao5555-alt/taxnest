<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('fbr_pos_transaction_items', 'uom')) {
            Schema::table('fbr_pos_transaction_items', function (Blueprint $table) {
                $table->string('uom', 10)->default('U')->after('hs_code');
            });
        }
    }

    public function down(): void
    {
        Schema::table('fbr_pos_transaction_items', function (Blueprint $table) {
            $table->dropColumn('uom');
        });
    }
};
