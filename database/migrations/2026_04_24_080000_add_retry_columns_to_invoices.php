<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            if (!Schema::hasColumn('invoices', 'retry_count')) {
                $table->unsignedInteger('retry_count')->default(0)->after('fbr_status');
            }
            if (!Schema::hasColumn('invoices', 'last_retry_at')) {
                $table->timestamp('last_retry_at')->nullable()->after('retry_count');
            }
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            if (Schema::hasColumn('invoices', 'last_retry_at')) {
                $table->dropColumn('last_retry_at');
            }
            if (Schema::hasColumn('invoices', 'retry_count')) {
                $table->dropColumn('retry_count');
            }
        });
    }
};
