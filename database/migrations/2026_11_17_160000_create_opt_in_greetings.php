<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('greeting_preferences', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('company_id')->unique();
            $t->boolean('enabled')->default(false);
            $t->string('timezone')->default('Asia/Karachi');
            $t->json('templates');
            $t->unsignedBigInteger('updated_by');
            $t->timestamps();
        });
        Schema::create('greeting_recipients', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('company_id')->index();
            $t->string('name');
            $t->string('email');
            $t->date('event_date');
            $t->string('event');
            $t->boolean('email_consent')->default(false);
            $t->timestamp('consented_at')->nullable();
            $t->unsignedBigInteger('recorded_by');
            $t->timestamps();
        });
        Schema::create('greeting_deliveries', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('company_id')->index();
            $t->unsignedBigInteger('recipient_id');
            $t->string('event');
            $t->string('channel')->default('email');
            $t->date('occurrence');
            $t->string('status')->default('pending');
            $t->unsignedInteger('attempts')->default(0);
            $t->string('reason')->nullable();
            $t->timestamp('sent_at')->nullable();
            $t->timestamps();
            $t->unique(['company_id', 'recipient_id', 'event', 'channel', 'occurrence'], 'greeting_occurrence_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('greeting_deliveries');
        Schema::dropIfExists('greeting_recipients');
        Schema::dropIfExists('greeting_preferences');
    }
};
