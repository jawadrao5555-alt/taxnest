<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('subscriptions', 'lifecycle_snapshot')) {
            Schema::table('subscriptions', fn (Blueprint $t) => $t->json('lifecycle_snapshot')->nullable());
        }
    }

    public function down(): void
    { /* Forward-only: preserve contractual boundaries and historical access evidence. */
    }
};
