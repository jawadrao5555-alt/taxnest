<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Desktop-agent API key hardening (security remediation, Sep 2026).
 *
 * AgentAuth used to authenticate agents by SQL equality on the PLAINTEXT
 * companies.agent_api_key column. This adds companies.agent_api_key_hash
 * (sha256 hex, 64 chars, indexed) and backfills it from every existing key so
 * the middleware can look keys up by hash and compare with hash_equals().
 *
 * The plaintext column is deliberately KEPT for now: agents already in the
 * field hold the raw key, and the POS / FBR-POS / SaaS-admin panels still
 * display it to the owner for copy-paste. Removing it is a separate,
 * follow-up migration once the panel switches to show-once-on-generate.
 *
 * Idempotent + column-guarded (prod schema drift convention).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('companies')) {
            return;
        }

        if (!Schema::hasColumn('companies', 'agent_api_key_hash')) {
            Schema::table('companies', function (Blueprint $table) {
                $col = $table->string('agent_api_key_hash', 64)->nullable();
                if (Schema::hasColumn('companies', 'agent_api_key')) {
                    $col->after('agent_api_key');
                }
                $table->index('agent_api_key_hash', 'companies_agent_api_key_hash_index');
            });
        }

        if (!Schema::hasColumn('companies', 'agent_api_key')) {
            return;
        }

        // Backfill in chunks — plain query builder so no model events / casts
        // run and companies.updated_at is not churned.
        DB::table('companies')
            ->select(['id', 'agent_api_key'])
            ->whereNotNull('agent_api_key')
            ->where('agent_api_key', '!=', '')
            ->whereNull('agent_api_key_hash')
            ->orderBy('id')
            ->chunkById(200, function ($rows) {
                foreach ($rows as $row) {
                    DB::table('companies')
                        ->where('id', $row->id)
                        ->update(['agent_api_key_hash' => hash('sha256', (string) $row->agent_api_key)]);
                }
            });
    }

    public function down(): void
    {
        if (Schema::hasTable('companies') && Schema::hasColumn('companies', 'agent_api_key_hash')) {
            Schema::table('companies', function (Blueprint $table) {
                $table->dropIndex('companies_agent_api_key_hash_index');
                $table->dropColumn('agent_api_key_hash');
            });
        }
    }
};
