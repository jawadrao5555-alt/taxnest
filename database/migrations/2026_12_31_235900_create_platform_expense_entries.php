<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {
        if (Schema::hasTable('platform_expense_entries')) return;
        Schema::create('platform_expense_entries',function(Blueprint $t){
            $t->id();$t->unsignedBigInteger('admin_id');$t->string('actor_name');
            $t->uuid('request_id');$t->char('fingerprint',64);$t->string('kind',12);
            $t->unsignedBigInteger('reverses_id')->nullable()->unique();
            $t->date('entry_date');$t->string('category',80);$t->string('reference',160);$t->text('notes')->nullable();
            $t->unsignedBigInteger('amount_paisa');$t->char('currency',3)->default('PKR');$t->timestamp('created_at');
            $t->unique(['admin_id','request_id']);$t->index(['entry_date','category']);
        });
    }
    public function down(): void { /* Financial history intentionally retained on code rollback. */ }
};
