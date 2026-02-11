<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->string('fbr_environment')->default('sandbox');
            $table->text('fbr_sandbox_token')->nullable();
            $table->text('fbr_production_token')->nullable();
            $table->string('fbr_registration_no')->nullable();
            $table->string('fbr_business_name')->nullable();
            $table->timestamp('suspended_at')->nullable();
        });

        Schema::table('users', function (Blueprint $table) {
            $table->boolean('is_active')->default(true);
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn(['fbr_environment', 'fbr_sandbox_token', 'fbr_production_token', 'fbr_registration_no', 'fbr_business_name', 'suspended_at']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('is_active');
        });
    }
};
