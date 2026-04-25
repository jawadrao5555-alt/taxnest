<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            if (!Schema::hasColumn('subscriptions', 'override_type')) {
                $table->string('override_type', 20)->default('none')->after('active');
            }
            if (!Schema::hasColumn('subscriptions', 'override_until')) {
                $table->dateTime('override_until')->nullable()->after('override_type');
            }
            if (!Schema::hasColumn('subscriptions', 'free_invoice_limit')) {
                $table->unsignedInteger('free_invoice_limit')->nullable()->after('override_until');
            }
            if (!Schema::hasColumn('subscriptions', 'override_reason')) {
                $table->string('override_reason', 255)->nullable()->after('free_invoice_limit');
            }
            if (!Schema::hasColumn('subscriptions', 'override_by')) {
                $table->unsignedBigInteger('override_by')->nullable()->after('override_reason');
            }
        });
    }

    public function down(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            foreach (['override_by', 'override_reason', 'free_invoice_limit', 'override_until', 'override_type'] as $col) {
                if (Schema::hasColumn('subscriptions', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
