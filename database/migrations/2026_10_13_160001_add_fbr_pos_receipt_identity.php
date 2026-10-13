<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
 public function up(): void {
  foreach(['fbr_sales_tax_registration_number'=>30,'fbr_tax_office'=>150] as $column=>$length)
   if(!Schema::hasColumn('companies',$column)) Schema::table('companies',fn(Blueprint $t)=>$t->string($column,$length)->nullable());
  if(!Schema::hasColumn('fbr_pos_transactions','fbr_receipt_profile_snapshot')) Schema::table('fbr_pos_transactions',fn(Blueprint $t)=>$t->json('fbr_receipt_profile_snapshot')->nullable());
 }
 public function down(): void {} // Preserve the seller identity printed on issued invoices.
};
