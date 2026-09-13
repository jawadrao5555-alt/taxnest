<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('app_updates') && ! Schema::hasColumn('app_updates', 'deployment_key')) {
            Schema::table('app_updates', function (Blueprint $table): void {
                $table->string('deployment_key', 64)->nullable()->unique('app_updates_deploy_key_uidx');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('app_updates') && Schema::hasColumn('app_updates', 'deployment_key')) {
            Schema::table('app_updates', function (Blueprint $table): void {
                $table->dropUnique('app_updates_deploy_key_uidx');
                $table->dropColumn('deployment_key');
            });
        }
    }
};
