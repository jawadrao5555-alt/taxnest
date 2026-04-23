<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('push_subscriptions')) {
            return;
        }
        Schema::create('push_subscriptions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable()->index();
            $table->unsignedBigInteger('company_id')->nullable()->index();
            $table->string('scope', 32)->default('di')->index(); // di | pos | fbrpos
            // Push endpoints are URLs — typically <500 chars; spec allows longer
            // but VARCHAR(500) keeps the column index-friendly on MySQL.
            $table->string('endpoint', 500);
            $table->string('p256dh', 255)->nullable();
            $table->string('auth_key', 255)->nullable();
            $table->string('user_agent', 500)->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'endpoint'], 'push_sub_user_endpoint_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('push_subscriptions');
    }
};
