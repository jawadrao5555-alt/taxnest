<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        foreach (['health_entitlements', 'health_service_modes'] as $column) {
            if (!Schema::hasColumn('companies', $column)) {
                Schema::table('companies', fn (Blueprint $table) => $table->text($column)->nullable());
            }
        }
    }

    public function down(): void
    {
        foreach (['health_entitlements', 'health_service_modes'] as $column) {
            if (Schema::hasColumn('companies', $column)) {
                Schema::table('companies', fn (Blueprint $table) => $table->dropColumn($column));
            }
        }
    }
};
