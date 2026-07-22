<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Madadgar AI support bot (owner request 22 Jul 2026): floating support bubble on
// PRA POS pages — AI chat (Roman Urdu, knowledge-base answers) + WhatsApp option.
// Escalations become feature_suggestions rows with source='madadgar'.
// Idempotent (per prod-schema-drift self-heal convention) — safe to re-run.
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('madadgar_messages')) {
            Schema::create('madadgar_messages', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('company_id');
                $table->unsignedBigInteger('user_id');
                // Client-generated UUID (localStorage); "Nayi chat" = new UUID.
                $table->char('session_id', 36);
                // 'user' or 'assistant' (no system rows stored).
                $table->string('role', 12);
                $table->text('content');
                // Set on the assistant row that produced a confirmed escalation.
                $table->unsignedBigInteger('escalation_id')->nullable();
                $table->timestamps();
                $table->index(['user_id', 'session_id'], 'madadgar_user_session_idx');
                $table->index(['company_id', 'created_at'], 'madadgar_company_created_idx');
            });
        }

        if (Schema::hasTable('feature_suggestions') && !Schema::hasColumn('feature_suggestions', 'source')) {
            Schema::table('feature_suggestions', function (Blueprint $table) {
                // 'user' = submitted via /pos/suggestions form; 'madadgar' = AI bot escalation.
                $table->string('source', 20)->default('user')->after('status');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('madadgar_messages');
        if (Schema::hasTable('feature_suggestions') && Schema::hasColumn('feature_suggestions', 'source')) {
            Schema::table('feature_suggestions', function (Blueprint $table) {
                $table->dropColumn('source');
            });
        }
    }
};
