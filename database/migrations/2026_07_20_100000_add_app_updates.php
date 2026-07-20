<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// "What's New" update notifications (owner request 20 Jul 2026): admin publishes
// app updates; POS users get a one-time popup + bell-icon history.
// Idempotent (per prod-schema-drift self-heal convention) — safe to re-run.
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('app_updates')) {
            Schema::create('app_updates', function (Blueprint $table) {
                $table->id();
                $table->string('title', 150);
                // JSON array of bullet points ("is update mein kya aya").
                $table->text('points')->nullable();
                // Which panel sees it: pos (NestPOS PRA) for now; di / fbrpos later.
                $table->string('audience', 20)->default('pos');
                $table->boolean('is_published')->default(true);
                $table->unsignedBigInteger('created_by')->nullable();
                $table->timestamps();
                $table->index(['audience', 'is_published'], 'app_updates_aud_pub_idx');
            });
        }

        if (!Schema::hasTable('app_update_seens')) {
            Schema::create('app_update_seens', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('app_update_id');
                $table->unsignedBigInteger('user_id');
                $table->timestamps();
                $table->unique(['app_update_id', 'user_id'], 'app_update_seen_unique');
                $table->index('user_id', 'app_update_seens_user_idx');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('app_update_seens');
        Schema::dropIfExists('app_updates');
    }
};
