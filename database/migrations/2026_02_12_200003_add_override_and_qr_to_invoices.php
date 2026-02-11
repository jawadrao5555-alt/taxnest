<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->text('override_reason')->nullable();
            $table->unsignedBigInteger('override_by')->nullable();
            $table->string('submission_mode')->nullable();
            $table->string('fbr_invoice_id')->nullable();
            $table->text('qr_data')->nullable();

            $table->foreign('override_by')->references('id')->on('users')->onDelete('set null');
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropForeign(['override_by']);
            $table->dropColumn(['override_reason', 'override_by', 'submission_mode', 'fbr_invoice_id', 'qr_data']);
        });
    }
};
