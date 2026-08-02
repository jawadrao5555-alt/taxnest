<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * DI invoice send-to-buyer (Email / WhatsApp): per-invoice delivery log
 * (channel / recipient / user / status) surfaced as "Delivery History" on the
 * invoice page. Idempotent (hasTable guard) — safe on drifted prod schemas.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('invoice_deliveries')) {
            return;
        }

        Schema::create('invoice_deliveries', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('invoice_id')->index();
            $table->unsignedBigInteger('company_id')->index();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('channel', 20);              // email | whatsapp
            $table->string('recipient', 255);           // email address or normalized intl phone digits
            $table->string('status', 20)->default('sent'); // sent | failed
            $table->text('error')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoice_deliveries');
    }
};
