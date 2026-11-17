<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasColumn('hospital_payments','fbr_pos_transaction_id')) {
            Schema::table('hospital_payments', function (Blueprint $t) {
                $t->unsignedBigInteger('fbr_pos_transaction_id')->nullable()->index();
                $t->string('fbr_status',32)->nullable();
                $t->string('fbr_invoice_number',100)->nullable();
                $t->string('fbr_response_code',100)->nullable();
                $t->text('fbr_error_message')->nullable();
                $t->text('fbr_qr_payload')->nullable();
            });
        }
    }
    public function down(): void
    {
        // Forward-only: fiscal receipt evidence must survive an application rollback.
    }
};
