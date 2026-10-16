<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('fbr_pos_owner_reconciliations')) return;
        Schema::create('fbr_pos_owner_reconciliations', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('company_id'); $t->unsignedBigInteger('transaction_id');
            $t->unsignedBigInteger('actor_id'); $t->uuid('request_key');
            $t->string('decision', 24); $t->string('evidence_source', 24);
            $t->string('evidence_reference', 160); $t->string('environment', 16);
            $t->string('fiscal_number_hash', 64)->nullable();
            $t->string('before_fingerprint', 64); $t->dateTime('checked_at'); $t->dateTime('created_at');
            $t->unique(['company_id', 'transaction_id', 'request_key'], 'fbr_pos_owner_reconcile_request_unique');
        });
    }
    public function down(): void {} // Fiscal operator audit is retained on rollback.
};
