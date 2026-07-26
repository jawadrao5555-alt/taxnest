<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Staff Hazri (owner batch, 26 Jul 2026): one row per POS-guard login.
// logout_at stays NULL when the user never presses Logout (browser closed /
// power cut) — the report then honestly shows last_activity_at instead.
// Idempotent for prod (owner's cPanel runs migrate --force on every deploy).
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('pos_user_sessions')) {
            return;
        }
        Schema::create('pos_user_sessions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('user_id');
            $table->dateTime('login_at');
            $table->dateTime('logout_at')->nullable();
            $table->dateTime('last_activity_at')->nullable();
            $table->string('ip', 45)->nullable();
            $table->timestamps();
            $table->index(['company_id', 'user_id', 'login_at'], 'pus_company_user_login_idx');
            $table->index(['company_id', 'login_at'], 'pus_company_login_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pos_user_sessions');
    }
};
