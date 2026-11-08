<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('products', 'selling_packings')) {
            Schema::table('products', fn (Blueprint $t) => $t->json('selling_packings')->nullable());
        }
        Schema::table('invoice_items', function (Blueprint $t) {
            if (! Schema::hasColumn('invoice_items', 'selling_snapshot')) {
                $t->json('selling_snapshot')->nullable();
            }
            $t->decimal('quantity', 18, 4)->change();
            $t->decimal('price', 21, 6)->change();
            $t->decimal('mrp', 21, 6)->nullable()->change();
        });
    }

    public function down(): void
    {
        // Do not narrow quantities or discard immutable sales evidence on rollback.
        // The additive nullable fields are compatible with the earlier application.
    }
};
