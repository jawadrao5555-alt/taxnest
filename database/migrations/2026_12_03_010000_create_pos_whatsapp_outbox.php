<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasColumn('companies', 'pos_whatsapp_auto_send')) {
            Schema::table('companies', fn (Blueprint $t) => $t->boolean('pos_whatsapp_auto_send')->default(false));
        }
        if (!Schema::hasTable('pos_whatsapp_outbox')) {
            Schema::create('pos_whatsapp_outbox', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('company_id');
                $t->unsignedBigInteger('transaction_id');
                $t->string('recipient', 32);
                $t->string('status', 24)->default('pending');
                $t->unsignedSmallInteger('attempts')->default(0);
                $t->timestamp('next_attempt_at')->nullable();
                $t->timestamp('claimed_at')->nullable();
                $t->string('provider_message_id', 191)->nullable();
                $t->string('error', 500)->nullable();
                $t->timestamps();
                $t->unique(['company_id', 'transaction_id'], 'pos_wa_one_auto_bill');
                $t->index(['status', 'next_attempt_at']);
                $t->index(['company_id', 'provider_message_id'], 'pos_wa_provider_status');
            });
        }
        if (!Schema::hasTable('pos_whatsapp_status_events')) {
            Schema::create('pos_whatsapp_status_events', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('company_id');
                $t->string('provider_message_id', 191);
                $t->string('status', 24);
                $t->timestamp('created_at');
                $t->unique(['company_id', 'provider_message_id', 'status'], 'pos_wa_status_once');
                $t->index('created_at');
            });
        }
    }
    public function down(): void { /* Keep opt-in and delivery audit on rollback. */ }
};
