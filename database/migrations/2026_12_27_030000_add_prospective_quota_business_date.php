<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
 public function up(): void {
  if (Schema::hasTable('prospective_pos_quota_retention') && !Schema::hasColumn('prospective_pos_quota_retention','business_date')) {
   Schema::table('prospective_pos_quota_retention',fn(Blueprint $t)=>$t->date('business_date')->nullable());
  }
 }
 public function down(): void { /* No destructive rollback of frozen quota facts. */ }
};
