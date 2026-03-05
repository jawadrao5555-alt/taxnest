<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pos_transactions', function (Blueprint $table) {
            $table->string('status')->default('completed')->after('payment_method');
            $table->unsignedBigInteger('locked_by_terminal_id')->nullable()->after('status');
            $table->timestamp('lock_time')->nullable()->after('locked_by_terminal_id');

            $table->foreign('locked_by_terminal_id')->references('id')->on('pos_terminals')->nullOnDelete();
            $table->index(['company_id', 'status']);
            $table->index(['locked_by_terminal_id', 'lock_time']);
        });
    }

    public function down(): void
    {
        Schema::table('pos_transactions', function (Blueprint $table) {
            $table->dropForeign(['locked_by_terminal_id']);
            $table->dropIndex(['company_id', 'status']);
            $table->dropIndex(['locked_by_terminal_id', 'lock_time']);
            $table->dropColumn(['status', 'locked_by_terminal_id', 'lock_time']);
        });
    }
};
