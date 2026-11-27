<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('invoice_items', 'fbr_reference_checked')) {
            Schema::table('invoice_items', function (Blueprint $table) {
                $table->boolean('fbr_reference_checked')->default(false);
            });
        }
    }

    public function down(): void
    {
        Schema::table('invoice_items', function (Blueprint $table) {
            $table->dropColumn('fbr_reference_checked');
        });
    }
};
