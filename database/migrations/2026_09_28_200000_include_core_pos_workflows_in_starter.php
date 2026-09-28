<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Core billing and restaurant kitchen flow belong to every PRA POS package. */
return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('pricing_plans')) return;
        $columns = array_flip(Schema::getColumnListing('pricing_plans'));
        $query = DB::table('pricing_plans')->whereIn('name', ['Starter', 'Business', 'Unlimited']);
        if (isset($columns['product_type'])) $query->where('product_type', 'pos');
        $updates = [];
        foreach (['offline_enabled', 'restaurant_enabled', 'kot_enabled'] as $column) {
            if (isset($columns[$column])) $updates[$column] = true;
        }
        if ($updates) $query->update($updates);
    }

    public function down(): void
    {
        // Never revoke active shop access or overwrite owner-edited plan gates.
    }
};
