<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Madadgar hybrid local answer engine: per-message answer source so the admin
// panel can show Local / Cache / OpenAI / fallback totals (cost visibility).
// - assistant rows: which engine produced the reply ('local'|'cache'|'openai'|'fallback')
// - user rows: 'fallback' marks a turn that got no real answer (excluded from
//   the daily message cap so an unanswered question never burns quota)
// Idempotent with hasColumn guards (live cPanel schema-drift convention).
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('madadgar_messages') && !Schema::hasColumn('madadgar_messages', 'source')) {
            Schema::table('madadgar_messages', function (Blueprint $table) {
                $table->string('source', 16)->nullable()->after('escalation_id');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('madadgar_messages') && Schema::hasColumn('madadgar_messages', 'source')) {
            Schema::table('madadgar_messages', function (Blueprint $table) {
                $table->dropColumn('source');
            });
        }
    }
};
