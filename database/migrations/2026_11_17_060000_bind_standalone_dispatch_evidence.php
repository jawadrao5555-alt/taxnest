<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
 public function up(): void {
  Schema::table('fbr_pos_transactions', fn(Blueprint $t)=>$t->string('fbr_requested_environment_snapshot',20)->nullable());
  Schema::table('fbr_pos_submission_evidence',function(Blueprint $t){$t->string('device_uid',100)->nullable();$t->string('submission_claim_hash',64)->nullable();$t->unsignedBigInteger('reviewed_device_proof_id')->nullable();});
 }
 public function down(): void {
  Schema::table('fbr_pos_submission_evidence',fn(Blueprint $t)=>$t->dropColumn(['device_uid','submission_claim_hash','reviewed_device_proof_id']));
  Schema::table('fbr_pos_transactions',fn(Blueprint $t)=>$t->dropColumn('fbr_requested_environment_snapshot'));
 }
};
