<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {
        Schema::create('reviewed_device_proofs', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('company_id'); $t->string('device_uid',128); $t->string('pos_id',64);
            $t->string('environment',16); $t->string('action',16); $t->unsignedBigInteger('actor_id');
            $t->string('evidence_reference',300); $t->char('evidence_sha256',64); $t->text('review_note');
            $t->unsignedBigInteger('revokes_id')->nullable(); $t->timestamp('created_at');
            $t->index(['company_id','device_uid','pos_id','environment'],'reviewed_device_proof_scope');
        });
    }
    public function down(): void { Schema::dropIfExists('reviewed_device_proofs'); }
};
