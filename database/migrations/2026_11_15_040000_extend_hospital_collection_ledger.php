<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
 public function up(): void {
  Schema::create('hospital_services', function(Blueprint $t) {
   $t->id(); $t->unsignedBigInteger('company_id'); $t->string('code',20); $t->string('label',80); $t->string('service_head',20); $t->unsignedBigInteger('rate_paisa'); $t->boolean('active')->default(true); $t->timestamps(); $t->unique(['company_id','code']);
  });
  Schema::table('hospital_invoices', function(Blueprint $t) { $t->string('patient_name',100)->nullable(); $t->json('service_lines')->nullable(); });
  Schema::table('hospital_payments', fn(Blueprint $t) => $t->string('payment_reference',100)->nullable());
 }
 public function down(): void {
  Schema::table('hospital_payments', fn(Blueprint $t) => $t->dropColumn('payment_reference'));
  Schema::table('hospital_invoices', fn(Blueprint $t) => $t->dropColumn(['patient_name','service_lines']));
  Schema::dropIfExists('hospital_services');
 }
};
