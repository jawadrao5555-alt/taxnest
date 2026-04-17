<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->string('agent_api_key', 80)->nullable()->unique()->after('pra_proxy_url');
            $table->timestamp('agent_last_seen')->nullable()->after('agent_api_key');
            $table->string('agent_version', 20)->nullable()->after('agent_last_seen');
            $table->boolean('agent_enabled')->default(false)->after('agent_version');
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn(['agent_api_key', 'agent_last_seen', 'agent_version', 'agent_enabled']);
        });
    }
};
