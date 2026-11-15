<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {
        Schema::create('tenant_backup_evidence', function(Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('company_id'); $t->uuid('source_id'); $t->uuid('event_id');
            $t->string('reported_status',20); $t->string('provenance',32)->default('authenticated_producer_report');
            $t->timestamp('event_at'); $t->timestamp('received_at'); $t->timestamp('reported_retained_until')->nullable();
            $t->timestamp('evidence_expires_at'); $t->char('content_hash',64);
            $t->unique(['source_id','event_id']); $t->index(['company_id','event_at','id']);
        });
    }
    public function down(): void { Schema::dropIfExists('tenant_backup_evidence'); }
};
