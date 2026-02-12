<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hs_rejection_history', function (Blueprint $table) {
            $table->string('error_code', 100)->nullable()->after('last_rejection_reason');
            $table->text('error_message')->nullable()->after('error_code');
            $table->timestamp('last_rejected_at')->nullable()->after('error_message');
            $table->string('environment', 20)->default('sandbox')->after('last_rejected_at');
        });
    }

    public function down(): void
    {
        Schema::table('hs_rejection_history', function (Blueprint $table) {
            $table->dropColumn(['error_code', 'error_message', 'last_rejected_at', 'environment']);
        });
    }
};
