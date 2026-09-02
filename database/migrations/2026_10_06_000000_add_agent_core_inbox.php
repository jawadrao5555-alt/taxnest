<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $createdCompanyFlag = false;
        if (Schema::hasTable('companies') && !Schema::hasColumn('companies', 'agent_core_enabled')) {
            Schema::table('companies', function (Blueprint $table) {
                $table->boolean('agent_core_enabled')->default(false);
            });
            $createdCompanyFlag = true;
        }

        $createdInbox = false;
        if (!Schema::hasTable('agent_core_events')) {
            Schema::create('agent_core_events', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('company_id');
                $table->string('device_uid', 64);
                $table->string('event_id', 64);
                $table->string('idempotency_key', 128);
                $table->string('event_type', 64);
                $table->timestamp('occurred_at')->nullable();
                $table->json('payload');
                $table->string('content_hash', 64);
                // Explicitly scoped escape hatch for pre-Core rows whose old
                // timestamp column discarded sub-second precision.
                $table->boolean('legacy_backfilled')->default(false);
                $table->timestamps();

                $table->unique(['company_id', 'device_uid', 'event_id'], 'agent_core_events_idempotency');
                $table->unique(['company_id', 'device_uid', 'idempotency_key'], 'agent_core_events_idempotency_key');
                $table->index(['company_id', 'device_uid'], 'agent_core_events_device_scope');
            });
            $createdInbox = true;
        }

        // A rollback must never delete a table that existed before this
        // migration. Persist ownership rather than guessing from its columns.
        if ($createdInbox || $createdCompanyFlag) {
            if (!Schema::hasTable('agent_core_inbox_migration_ownership')) {
                Schema::create('agent_core_inbox_migration_ownership', function (Blueprint $table) {
                    $table->string('table_name', 64)->primary();
                });
            }
            if ($createdInbox) {
                DB::table('agent_core_inbox_migration_ownership')->updateOrInsert(
                    ['table_name' => 'agent_core_events']
                );
            }
            if ($createdCompanyFlag) {
                DB::table('agent_core_inbox_migration_ownership')->updateOrInsert(
                    ['table_name' => 'companies.agent_core_enabled']
                );
            }
        }
    }

    public function down(): void
    {
        $ownsInbox = Schema::hasTable('agent_core_inbox_migration_ownership')
            && DB::table('agent_core_inbox_migration_ownership')->where('table_name', 'agent_core_events')->exists();
        if ($ownsInbox && Schema::hasTable('agent_core_events')) {
            Schema::drop('agent_core_events');
        }
        if ($ownsInbox && Schema::hasTable('agent_core_inbox_migration_ownership')) {
            DB::table('agent_core_inbox_migration_ownership')->where('table_name', 'agent_core_events')->delete();
        }

        $ownsCompanyFlag = Schema::hasTable('agent_core_inbox_migration_ownership')
            && DB::table('agent_core_inbox_migration_ownership')->where('table_name', 'companies.agent_core_enabled')->exists();
        if ($ownsCompanyFlag && Schema::hasTable('companies') && Schema::hasColumn('companies', 'agent_core_enabled')) {
            Schema::table('companies', function (Blueprint $table) {
                $table->dropColumn('agent_core_enabled');
            });
        }
        if ($ownsCompanyFlag && Schema::hasTable('agent_core_inbox_migration_ownership')) {
            DB::table('agent_core_inbox_migration_ownership')->where('table_name', 'companies.agent_core_enabled')->delete();
        }
    }
};