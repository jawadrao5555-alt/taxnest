<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->boolean('is_internal_account')->default(false);
            $table->boolean('onboarding_completed')->default(false);
        });
    }
    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn(['is_internal_account', 'onboarding_completed']);
        });
    }
};
