<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('pos_federal_rules', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('company_id'); $t->string('channel', 8);
            $t->string('environment', 12); $t->boolean('enabled')->default(false);
            $t->unsignedInteger('version')->default(1); $t->text('mapping');
            $t->unsignedBigInteger('reviewed_by'); $t->timestamp('reviewed_at'); $t->timestamps();
            $t->unique(['company_id','channel','environment'], 'pos_federal_rule_scope');
        });
        Schema::create('pos_federal_outbox', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('company_id'); $t->unsignedBigInteger('source_transaction_id');
            $t->string('channel', 8); $t->string('environment',12); $t->string('state',32)->default('queued');
            $t->text('rule_snapshot'); $t->string('connection_hash',64);
            $t->unsignedBigInteger('submission_id')->nullable(); $t->string('error_code',64)->nullable();
            $t->timestamp('started_at')->nullable(); $t->timestamps();
            // One original finalized sale selects one immutable federal route.
            $t->unique(['company_id','source_transaction_id'], 'pos_federal_sale_once');
            $t->index(['state','id']);
        });
    }
    public function down(): void
    {
        // Roll back the application/feature flag; never drop stored fiscal evidence.
        if (\Illuminate\Support\Facades\DB::table('pos_federal_rules')->exists() || \Illuminate\Support\Facades\DB::table('pos_federal_outbox')->exists()) {
            throw new \RuntimeException('Federal evidence tables must be retained. Disable automation for rollback.');
        }
        Schema::dropIfExists('pos_federal_outbox');
        Schema::dropIfExists('pos_federal_rules');
    }
};
