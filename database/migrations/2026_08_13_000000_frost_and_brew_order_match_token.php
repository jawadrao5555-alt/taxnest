<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Frost and Brew (company id 26) — restore 'token' order-match style.
     * The Aug-23 rollout flipped every company to 'code'; this migration
     * reverts only Frost & Brew per the owner's explicit request.
     */
    public function up(): void
    {
        if (! Schema::hasTable('companies') || ! Schema::hasColumn('companies', 'order_match_style')) {
            return;
        }

        DB::table('companies')
            ->where('id', 26)
            ->update(['order_match_style' => 'token']);
    }

    public function down(): void
    {
        if (! Schema::hasTable('companies') || ! Schema::hasColumn('companies', 'order_match_style')) {
            return;
        }

        DB::table('companies')
            ->where('id', 26)
            ->update(['order_match_style' => 'code']);
    }
};
