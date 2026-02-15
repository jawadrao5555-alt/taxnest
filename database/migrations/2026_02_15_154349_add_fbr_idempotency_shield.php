<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->string('fbr_submission_hash')->nullable()->unique()->after('submitted_at');
        });

        Schema::table('fbr_logs', function (Blueprint $table) {
            $table->string('request_payload_hash')->nullable()->index()->after('request_payload');
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropColumn('fbr_submission_hash');
        });

        Schema::table('fbr_logs', function (Blueprint $table) {
            $table->dropColumn('request_payload_hash');
        });
    }
};
