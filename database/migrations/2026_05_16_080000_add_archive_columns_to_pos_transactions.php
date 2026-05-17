<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pos_transactions', function (Blueprint $table) {
            $table->boolean('is_archived')->default(false)->index()->after('status');
            $table->timestamp('archived_at')->nullable()->after('is_archived');
            $table->unsignedBigInteger('archived_by_report_id')->nullable()->after('archived_at');

            $table->foreign('archived_by_report_id')
                ->references('id')->on('pos_day_close_reports')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('pos_transactions', function (Blueprint $table) {
            $table->dropForeign(['archived_by_report_id']);
            $table->dropColumn(['is_archived', 'archived_at', 'archived_by_report_id']);
        });
    }
};
