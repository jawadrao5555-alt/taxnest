<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up():void {if(Schema::hasTable('madadgar_messages')&&!Schema::hasColumn('madadgar_messages','answer_provenance'))Schema::table('madadgar_messages',fn(Blueprint $t)=>$t->json('answer_provenance')->nullable());}
    public function down():void {if(Schema::hasColumn('madadgar_messages','answer_provenance'))Schema::table('madadgar_messages',fn(Blueprint $t)=>$t->dropColumn('answer_provenance'));}
};
