<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('hospital_invoice_corrections', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('invoice_id');
            $table->unsignedBigInteger('actor_id');
            $table->string('action', 8);
            $table->string('reason', 300);
            $table->json('payment_keys');
            $table->json('before_state');
            $table->json('after_state')->nullable();
            $table->timestamp('created_at');
            $table->timestamp('invoice_created_at');
            $table->index(['company_id', 'action', 'invoice_created_at'], 'hospital_corrections_quota_idx');
        });
    }

    public function down(): void
    {
        // Keep the audit and voided request keys across application rollbacks.
    }
};
