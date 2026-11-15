<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
 public function up(): void {
  Schema::create('school_fee_accounts', function(Blueprint $t) {
   $t->id(); $t->unsignedBigInteger('company_id'); $t->unsignedBigInteger('branch_id')->nullable();
   $t->string('student_reference',100); $t->string('student_name',100)->nullable(); $t->string('fee_period',7);
   $t->json('fee_lines'); $t->unsignedBigInteger('payable_paisa'); $t->unsignedBigInteger('paid_paisa')->default(0); $t->timestamps();
   $t->index(['company_id','branch_id','id']);
  });
  Schema::table('school_fee_receipts', function(Blueprint $t) {
   $t->unsignedBigInteger('account_id')->nullable()->index(); $t->unsignedBigInteger('requested_account_id')->nullable(); $t->string('student_name',100)->nullable();
   $t->string('payment_method',10)->default('cash'); $t->json('fee_lines')->nullable();
   $t->unsignedBigInteger('payable_paisa')->nullable(); $t->unsignedBigInteger('paid_paisa')->nullable(); $t->unsignedBigInteger('balance_paisa')->nullable();
  });
  Schema::table('school_fee_day_closes', function(Blueprint $t) { $t->string('variance_reason',500)->nullable(); $t->string('handover_to',100)->nullable(); $t->string('handover_note',500)->nullable(); });
 }
 public function down(): void {
  Schema::table('school_fee_day_closes', fn(Blueprint $t) => $t->dropColumn(['variance_reason','handover_to','handover_note']));
  Schema::table('school_fee_receipts', fn(Blueprint $t) => $t->dropIndex(['account_id']));
  Schema::table('school_fee_receipts', fn(Blueprint $t) => $t->dropColumn(['account_id','requested_account_id','student_name','payment_method','fee_lines','payable_paisa','paid_paisa','balance_paisa']));
  Schema::dropIfExists('school_fee_accounts');
 }
};
