<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void { Schema::table('pos_agent_devices', function(Blueprint $table) { $table->json('local_fbr_ims')->nullable(); }); }
    public function down(): void { Schema::table('pos_agent_devices', function(Blueprint $table) { $table->dropColumn('local_fbr_ims'); }); }
};
