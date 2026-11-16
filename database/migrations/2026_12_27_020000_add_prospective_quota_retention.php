<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('prospective_pos_quota_retention')) {
            Schema::create('prospective_pos_quota_retention', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('company_id');
                $t->unsignedBigInteger('transaction_id');
                $t->dateTime('sold_at');
                $t->boolean('quota_counted');
                $t->boolean('aggregate_final');
                $t->string('source', 24);
                $t->unsignedBigInteger('source_id');
                $t->timestamp('captured_at');
                $t->unique(['company_id', 'transaction_id'], 'prospective_pos_quota_identity');
                $t->index(['company_id', 'source', 'source_id'], 'prospective_pos_quota_source');
            });
        }
    }

    public function down(): void
    { /* Forward-only: retained quota evidence must survive operational rollback. */
    }
};
