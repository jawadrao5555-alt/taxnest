<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
 public function up(): void {
  Schema::table('invoices',function(Blueprint $t){$t->boolean('inventory_enabled_snapshot')->nullable();$t->string('inventory_environment_snapshot',20)->nullable();$t->string('inventory_posting_state',40)->nullable();$t->string('inventory_posting_error',80)->nullable();});
  Schema::table('invoice_items',fn(Blueprint $t)=>$t->json('inventory_snapshot')->nullable());
  Schema::create('di_invoice_inventory_postings',function(Blueprint $t){
   $t->id();$t->foreignId('company_id')->constrained('companies')->onDelete('cascade');$t->unsignedBigInteger('invoice_id');$t->unsignedBigInteger('invoice_item_id')->unique();
   $t->unsignedBigInteger('product_id');$t->unsignedBigInteger('branch_id')->nullable();$t->string('direction',12);
   $t->decimal('base_quantity',18,4);$t->decimal('unit_cost_snapshot',15,2)->nullable();$t->unsignedBigInteger('source_posting_id')->nullable()->index();
   $t->unsignedBigInteger('movement_id')->unique();$t->timestamp('created_at');$t->index(['company_id','invoice_id']);
  });
  Schema::create('di_inventory_return_reservations',function(Blueprint $t){
   $t->id();$t->foreignId('company_id')->constrained('companies')->onDelete('cascade');$t->unsignedBigInteger('invoice_id');$t->unsignedBigInteger('invoice_item_id')->unique();$t->unsignedBigInteger('source_posting_id')->index();$t->decimal('base_quantity',18,4);$t->string('state',20);$t->timestamps();
  });
  Schema::table('inventory_stocks',fn(Blueprint $t)=>$t->decimal('quantity',18,4)->default(0)->change());
  Schema::table('inventory_movements',function(Blueprint $t){$t->decimal('quantity',18,4)->change();$t->decimal('balance_after',18,4)->default(0)->change();});
 }
 public function down(): void {
  // Do not narrow shared quantities: rollback must never round historical stock.
  Schema::dropIfExists('di_inventory_return_reservations');
  Schema::dropIfExists('di_invoice_inventory_postings');
  Schema::table('invoice_items',fn(Blueprint $t)=>$t->dropColumn('inventory_snapshot'));
  Schema::table('invoices',fn(Blueprint $t)=>$t->dropColumn(['inventory_enabled_snapshot','inventory_environment_snapshot','inventory_posting_state','inventory_posting_error']));
 }
};
