<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
 public function up(): void {
  if (!Schema::hasTable('fbr_pos_identities')) Schema::create('fbr_pos_identities', function(Blueprint $t) {
   $t->id(); $t->unsignedBigInteger('company_id'); $t->unsignedBigInteger('branch_id')->default(0);
   $t->unsignedBigInteger('terminal_id')->default(0); $t->string('pos_id',20); $t->string('device_uid',64); $t->timestamps();
   $t->unique(['company_id','branch_id','terminal_id'],'fbr_identity_scope_unique');
  });
  if (!Schema::hasTable('fbr_pos_identity_rebindings')) Schema::create('fbr_pos_identity_rebindings', function(Blueprint $t) {
   $t->id(); $t->unsignedBigInteger('company_id'); $t->unsignedBigInteger('transaction_id'); $t->unsignedBigInteger('actor_id');
   $t->unsignedBigInteger('reconciliation_id'); $t->string('old_device_uid',64)->nullable(); $t->string('new_device_uid',64);
   $t->string('pos_id_fingerprint',64); $t->string('evidence_reference',160); $t->dateTime('created_at');
  });
  foreach (['fbr_pos_id_snapshot'=>20,'fbr_device_uid_snapshot'=>64] as $column=>$length)
   if (!Schema::hasColumn('fbr_pos_transactions',$column)) Schema::table('fbr_pos_transactions',fn(Blueprint $t)=>$t->string($column,$length)->nullable());
  if (!Schema::hasColumn('fbr_pos_transactions','fbr_identity_captured_at')) Schema::table('fbr_pos_transactions',fn(Blueprint $t)=>$t->dateTime('fbr_identity_captured_at')->nullable());
 }
 public function down(): void {} // Historical fiscal identities must survive rollback.
};
