<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasColumn('invoice_items', 'st_withheld_amount')) {
            Schema::table('invoice_items', fn (Blueprint $t) => $t->decimal('st_withheld_amount', 18, 2)->nullable());
        }
        // Historic booleans do not prove an amount. Never backfill them as Rs 1.
    }
    public function down(): void
    {
        Schema::table('invoice_items', fn (Blueprint $t) => $t->dropColumn('st_withheld_amount'));
    }
};
