<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up():void {
        if (Schema::hasTable('madadgar_read_audits')) return;
        Schema::create('madadgar_read_audits',function(Blueprint $t){
            $t->id();$t->unsignedBigInteger('company_id');$t->unsignedBigInteger('user_id');
            $t->unsignedBigInteger('branch_id')->nullable();$t->string('intent',40);$t->string('outcome',32);
            $t->date('report_date');$t->string('timezone',64);$t->json('sources');$t->string('result_sha256',64);
            $t->timestamp('created_at');$t->index(['company_id','user_id','created_at']);
        });
    }
    public function down():void {Schema::dropIfExists('madadgar_read_audits');}
};
