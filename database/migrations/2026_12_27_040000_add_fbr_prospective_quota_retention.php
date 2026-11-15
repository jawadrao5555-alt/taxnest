<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('prospective_fbr_quota_retention')) {
            return;
        }
        Schema::create('prospective_fbr_quota_retention', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('company_id');
            $t->unsignedBigInteger('transaction_id');
            $t->dateTime('sold_at');
            $t->boolean('quota_counted');
            $t->string('source', 24);
            $t->timestamp('captured_at');
            $t->unique(['company_id', 'transaction_id'], 'prospective_fbr_quota_identity');
            $t->index(['company_id', 'sold_at'], 'prospective_fbr_quota_window');
        });
    }

    public function down(): void
    { /* Forward-only retained bill-count evidence. */
    }
};
