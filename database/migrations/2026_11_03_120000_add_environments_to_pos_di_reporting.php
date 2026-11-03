<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('pos_di_sandbox_profiles', function (Blueprint $table) {
            $table->string('environment', 20)->default('sandbox');
            $table->text('production_token')->nullable();
            $table->timestamp('production_confirmed_at')->nullable();
            $table->unsignedBigInteger('production_confirmed_by')->nullable();
        });
        Schema::table('pos_di_sandbox_submissions', function (Blueprint $table) {
            $table->string('environment', 20)->default('sandbox');
            $table->string('production_invoice_number')->nullable();
            $table->dropUnique('pos_di_sandbox_source_unique');
            $table->unique(['company_id', 'source_transaction_id', 'environment'], 'pos_di_source_environment_unique');
        });
    }

    public function down(): void
    {
        // Never relabel production evidence as sandbox during a rollback.
        if (DB::table('pos_di_sandbox_submissions')->where('environment', 'production')->exists()
            || DB::table('pos_di_sandbox_profiles')->whereNotNull('production_token')->exists()) {
            throw new RuntimeException('Production DI data exists. Preserve these tables and roll back application code only.');
        }
        Schema::table('pos_di_sandbox_submissions', function (Blueprint $table) {
            $table->dropUnique('pos_di_source_environment_unique');
            $table->dropColumn(['environment', 'production_invoice_number']);
            $table->unique(['company_id', 'source_transaction_id'], 'pos_di_sandbox_source_unique');
        });
        Schema::table('pos_di_sandbox_profiles', function (Blueprint $table) {
            $table->dropColumn(['environment', 'production_token', 'production_confirmed_at', 'production_confirmed_by']);
        });
    }
};
