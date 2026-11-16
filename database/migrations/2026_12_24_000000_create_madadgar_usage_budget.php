<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
 public function up():void {
  if(!Schema::hasTable('madadgar_budget_policies'))Schema::create('madadgar_budget_policies',function(Blueprint $t){
   $t->id();$t->foreignId('company_id')->unique()->constrained()->cascadeOnDelete();
   $t->string('model',60);$t->string('currency',3)->default('USD');
   $t->decimal('input_per_million',18,6)->nullable();$t->decimal('output_per_million',18,6)->nullable();$t->decimal('monthly_budget',18,6)->nullable();
   $t->unsignedBigInteger('configured_by');$t->timestamps();
  });
  if(!Schema::hasTable('madadgar_usage_events'))Schema::create('madadgar_usage_events',function(Blueprint $t){
   $t->id();$t->foreignId('company_id')->constrained()->cascadeOnDelete();$t->string('period',7);$t->string('source',30);$t->string('model',60)->nullable();$t->string('state',30);$t->string('error_code',40)->nullable();
   $t->unsignedInteger('latency_ms')->nullable();$t->unsignedInteger('input_bound')->nullable();$t->unsignedInteger('output_bound')->nullable();$t->unsignedBigInteger('input_tokens')->nullable();$t->unsignedBigInteger('output_tokens')->nullable();
   $t->decimal('input_rate',18,6)->nullable();$t->decimal('output_rate',18,6)->nullable();$t->decimal('estimated_cost',18,6)->nullable();$t->decimal('reserved_cost',18,6)->nullable();$t->string('currency',3)->nullable();$t->timestamps();
   $t->index(['company_id','period']);
  });
 }
 // Forward-only: recorded costs, uncertain reservations and configured caps are evidence.
 // Operational rollback may change code, never erase the budget guard/history.
 public function down():void{}
};
