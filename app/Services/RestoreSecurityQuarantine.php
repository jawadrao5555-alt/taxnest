<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Fail-closed access quarantine for an operator-restored database.
 *
 * This does not restore a backup or prove backup freshness. It invalidates
 * access which an older database could resurrect, while deliberately leaving
 * company business rows and Desktop Agent credentials untouched.
 */
final class RestoreSecurityQuarantine
{
    public function run(string $restoreReference): array
    {
        if (!Schema::hasTable('restore_security_quarantines')) {
            throw new RuntimeException('Restore security quarantine migration is not installed.');
        }

        $referenceHash = hash('sha256', $restoreReference);

        return DB::transaction(function () use ($referenceHash) {
            DB::table('restore_security_quarantines')->insertOrIgnore([
                'restore_reference_hash' => $referenceHash,
                'status' => 'running',
                'started_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $evidence = DB::table('restore_security_quarantines')
                ->where('restore_reference_hash', $referenceHash)->lockForUpdate()->first();
            if (!$evidence) {
                throw new RuntimeException('Restore security quarantine evidence could not be locked.');
            }
            if ($evidence->status === 'completed') {
                return [
                    'evidence_id' => (int) $evidence->id,
                    'already_completed' => true,
                    'counts' => json_decode((string) $evidence->revocation_counts, true) ?: [],
                ];
            }

            $agentBefore = $this->agentPairingDigest();
            $counts = [];

            $counts['database_sessions_deleted'] = $this->deleteAll('sessions');
            $counts['browser_generations_rotated'] = $this->rotateBrowserGenerations();
            $counts['password_reset_tokens_deleted'] = $this->deleteAll('password_reset_tokens');
            $counts['password_reset_grants_revoked'] = $this->revokePasswordResetGrants();
            $counts['reporting_codes_revoked'] = $this->markNow('reporting_oauth_codes', 'used_at');
            $counts['reporting_grants_revoked'] = $this->markNow('reporting_oauth_grants', 'revoked_at');
            $counts['reporting_access_tokens_deleted'] = $this->deleteAll('reporting_oauth_access_tokens');
            $counts['reporting_refresh_tokens_deleted'] = $this->deleteAll('reporting_oauth_refresh_tokens');
            $counts['consultant_links_revoked'] = $this->revokeConsultantLinks();
            $counts['consultant_invites_revoked'] = $this->markNow('consultant_invites', 'revoked_at');

            $counts['optional_support_copies_deleted'] = Schema::hasTable('support_bill_archives')
                ? DB::table('support_bill_archives')->count() : 0;
            $counts['optional_support_consents_disabled'] = Schema::hasTable('support_bill_archive_prefs')
                ? DB::table('support_bill_archive_prefs')->where('enabled', true)->count() : 0;
            if (Schema::hasTable('support_bill_archives')) {
                app(OptionalLocalBillArchive::class)->quarantineAfterRestore();
            }

            $agentAfter = $this->agentPairingDigest();
            if ($agentBefore !== $agentAfter) {
                throw new RuntimeException('Restore quarantine changed Desktop Agent pairing state.');
            }

            DB::table('restore_security_quarantines')->where('id', $evidence->id)->update([
                'status' => 'completed',
                'revocation_counts' => json_encode($counts, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
                'agent_pairing_rows' => $agentAfter['rows'],
                'agent_pairing_sha256' => $agentAfter['sha256'],
                'completed_at' => now(),
                'updated_at' => now(),
            ]);

            return ['evidence_id' => (int) $evidence->id, 'already_completed' => false, 'counts' => $counts];
        }, 3);
    }

    private function rotateBrowserGenerations(): int
    {
        if (!Schema::hasTable('users') || !Schema::hasColumn('users', 'session_generation')) {
            return 0;
        }
        $count = 0;
        DB::table('users')->select('id')->orderBy('id')->chunkById(100, function ($users) use (&$count) {
            foreach ($users as $user) {
                DB::table('users')->where('id', $user->id)->update([
                    'session_generation' => (string) Str::uuid(),
                ]);
                $count++;
            }
        });
        return $count;
    }

    private function revokePasswordResetGrants(): int
    {
        if (!Schema::hasTable('password_reset_otps') || !Schema::hasColumn('password_reset_otps', 'used')) {
            return 0;
        }
        $update = ['used' => true];
        if (Schema::hasColumn('password_reset_otps', 'reset_grant_hash')) $update['reset_grant_hash'] = null;
        if (Schema::hasColumn('password_reset_otps', 'reset_completed_at')) $update['reset_completed_at'] = now();
        if (Schema::hasColumn('password_reset_otps', 'updated_at')) $update['updated_at'] = now();
        return DB::table('password_reset_otps')->where('used', false)->update($update);
    }

    private function revokeConsultantLinks(): int
    {
        if (!Schema::hasTable('consultant_client_links')) return 0;
        $update = ['status' => 'revoked'];
        if (Schema::hasColumn('consultant_client_links', 'revoked_by')) $update['revoked_by'] = 'restore_quarantine';
        if (Schema::hasColumn('consultant_client_links', 'revoked_at')) $update['revoked_at'] = now();
        if (Schema::hasColumn('consultant_client_links', 'updated_at')) $update['updated_at'] = now();
        return DB::table('consultant_client_links')->whereIn('status', ['pending', 'active'])->update($update);
    }

    private function markNow(string $table, string $column): int
    {
        if (!Schema::hasTable($table) || !Schema::hasColumn($table, $column)) return 0;
        return DB::table($table)->whereNull($column)->update([$column => now()]);
    }

    private function deleteAll(string $table): int
    {
        return Schema::hasTable($table) ? DB::table($table)->delete() : 0;
    }

    private function agentPairingDigest(): array
    {
        if (!Schema::hasTable('companies')) return ['rows' => 0, 'sha256' => hash('sha256', '[]')];
        $columns = ['id'];
        foreach (['agent_api_key', 'agent_api_key_hash', 'agent_enabled'] as $column) {
            if (Schema::hasColumn('companies', $column)) $columns[] = $column;
        }
        $rows = DB::table('companies')->select($columns)->orderBy('id')->get()
            ->map(fn ($row) => (array) $row)->all();
        $canonical = AgentCoreSnapshotService::canonicalJson($rows);
        return ['rows' => count($rows), 'sha256' => hash('sha256', $canonical)];
    }
}
