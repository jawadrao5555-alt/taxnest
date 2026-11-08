<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('subscriptions', 'purchase_snapshot')) {
            Schema::table('subscriptions', fn (Blueprint $table) => $table->json('purchase_snapshot')->nullable());
        }
        // Old purchases have unknown historical terms. Never backfill them
        // from today's catalogue and present that as the original agreement.
    }

    public function down(): void
    {
        if (Schema::hasColumn('subscriptions', 'purchase_snapshot')) {
            Schema::table('subscriptions', fn (Blueprint $table) => $table->dropColumn('purchase_snapshot'));
        }
    }
};
