<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {
        if (Schema::hasTable('madadgar_escalation_requests')) return;
        Schema::create('madadgar_escalation_requests', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('company_id');
            $t->unsignedBigInteger('user_id');
            $t->uuid('session_id');
            $t->string('product', 16);
            $t->uuid('request_id');
            $t->char('fingerprint', 64);
            $t->json('response');
            $t->dateTime('created_at');
            $t->unique(['company_id', 'user_id', 'session_id', 'product', 'request_id'], 'madadgar_confirmation_identity');
        });
    }
    // Forward-only identity ledger: rollback must not make a committed request reusable.
    public function down(): void {}
};
