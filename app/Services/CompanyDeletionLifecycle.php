<?php

namespace App\Services;

use App\Models\AdminUser;
use App\Models\Company;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/** Durable request/hold/restore/purge evidence; never a substitute for fiscal retention. */
class CompanyDeletionLifecycle
{
    public const PENDING = 'pending_review';
    public const ON_HOLD = 'on_hold';
    public const RESTORED = 'restored';
    public const PURGED = 'purged';

    public function supported(): bool
    {
        return Schema::hasTable('company_deletion_requests')
            && Schema::hasTable('company_deletion_request_events');
    }

    /** Password verification happens in ProfileController immediately before this call. */
    public function requestByOwner(Company $company, User $user, ?string $reason = null): ?object
    {
        if (!$this->supported()) {
            return null; // Deploy-before-migrate compatibility; no destructive fallback.
        }

        return DB::transaction(function () use ($company, $user, $reason) {
            $currentCompany = Company::query()->lockForUpdate()->findOrFail($company->id);
            $currentUser = User::query()->lockForUpdate()->findOrFail($user->id);
            abort_unless((int) $currentUser->company_id === (int) $currentCompany->id
                && in_array($currentUser->role, ['company_admin', 'super_admin'], true)
                && !Auth::guard('admin')->check()
                && !(request()->hasSession() && request()->session()->has('impersonation')), 403);

            if ($existing = $this->active((int) $currentCompany->id, true)) {
                return $existing;
            }

            return $this->createRequest($currentCompany, 'web', (int) $currentUser->id, null,
                (string) $currentUser->email, $reason, self::PENDING);
        });
    }

    /** Called in the same transaction that moves the company into the bin. */
    public function beginHold(Company $company, AdminUser $actor, ?string $reason): ?object
    {
        if (!$this->supported()) return null;

        $request = $this->active((int) $company->id, true)
            ?: $this->createRequest($company, 'admin', null, (int) $actor->id,
                (string) $actor->email, $reason, self::PENDING);
        $binnedAt = now();
        $holdUntil = $binnedAt->copy()->addDays(Company::BIN_HOLD_DAYS);
        DB::table('company_deletion_requests')->where('id', $request->id)->update([
            'status' => self::ON_HOLD,
            'reason' => $reason ?: $request->reason,
            'binned_at' => $binnedAt,
            'hold_until' => $holdUntil,
            'updated_at' => now(),
        ]);
        $this->event((int) $request->id, (int) $company->id, 'bin_hold_started', 'admin',
            (int) $actor->id, ['hold_until' => $holdUntil->toISOString()]);

        return DB::table('company_deletion_requests')->where('id', $request->id)->first();
    }

    /** Restoring access revokes browser sessions only; Agent/API keys stay untouched. */
    public function restored(Company $company, AdminUser $actor): int
    {
        $rotated = 0;
        if (Schema::hasTable('users') && Schema::hasColumn('users', 'session_generation')) {
            User::query()->where('company_id', $company->id)->orderBy('id')->chunkById(100,
                function ($users) use (&$rotated) {
                    foreach ($users as $user) {
                        $user->forceFill(['session_generation' => (string) Str::uuid()])->saveQuietly();
                        $rotated++;
                    }
                });
        }
        if (!$this->supported()) return $rotated;

        $request = $this->active((int) $company->id, true)
            ?: $this->createRequest($company, 'admin', null, (int) $actor->id,
                (string) $actor->email, 'Legacy bin entry restored', self::ON_HOLD);
        DB::table('company_deletion_requests')->where('id', $request->id)->update([
            'active_key' => null,
            'status' => self::RESTORED,
            'restored_at' => now(),
            'updated_at' => now(),
        ]);
        $this->event((int) $request->id, (int) $company->id, 'company_restored', 'admin',
            (int) $actor->id, ['browser_session_generations_rotated' => $rotated]);

        return $rotated;
    }

    /** Called inside the database purge transaction so evidence cannot claim a rolled-back purge. */
    public function purged(Company $company, AdminUser $actor): ?int
    {
        if (!$this->supported()) return null;
        $request = $this->active((int) $company->id, true)
            ?: $this->createRequest($company, 'admin', null, (int) $actor->id,
                (string) $actor->email, 'Legacy bin entry permanently deleted', self::ON_HOLD);
        DB::table('company_deletion_requests')->where('id', $request->id)->update([
            'active_key' => null,
            'status' => self::PURGED,
            'purged_at' => now(),
            'updated_at' => now(),
        ]);
        $this->event((int) $request->id, (int) $company->id, 'database_purge_committed', 'admin',
            (int) $actor->id, ['retained_evidence' => ['admin_audit_logs', 'registered_credentials']]);
        return (int) $request->id;
    }

    public function recordFileCleanup(?int $requestId, int $companyId, AdminUser $actor, array $results): void
    {
        if (!$requestId || !$this->supported()) return;
        $this->event($requestId, $companyId, 'private_file_cleanup_attempted', 'admin',
            (int) $actor->id, $results);
    }

    public function active(int $companyId, bool $lock = false): ?object
    {
        if (!$this->supported()) return null;
        $query = DB::table('company_deletion_requests')
            ->where('active_key', $this->activeKey($companyId));
        if ($lock) $query->lockForUpdate();
        return $query->first();
    }

    private function createRequest(Company $company, string $guard, ?int $userId, ?int $adminId,
        ?string $email, ?string $reason, string $status): object
    {
        $manifest = $this->manifest((int) $company->id);
        $canonical = AgentCoreSnapshotService::canonicalJson($manifest);
        $id = DB::table('company_deletion_requests')->insertGetId([
            'company_id' => $company->id,
            'active_key' => $this->activeKey((int) $company->id),
            'company_name_snapshot' => $company->name,
            'product_type_snapshot' => $company->product_type,
            'requester_guard' => $guard,
            'requested_by_user_id' => $userId,
            'requested_by_admin_id' => $adminId,
            'requester_email_hash' => $email ? hash('sha256', Str::lower(trim($email))) : null,
            'requester_verified_at' => now(),
            'reason' => $reason,
            'status' => $status,
            'scope_manifest' => $canonical,
            'scope_manifest_sha256' => hash('sha256', $canonical),
            'requested_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->event($id, (int) $company->id, 'deletion_requested', $guard,
            $userId ?? $adminId, ['requester_verified' => true, 'manifest_sha256' => hash('sha256', $canonical)]);
        return DB::table('company_deletion_requests')->where('id', $id)->first();
    }

    private function manifest(int $companyId): array
    {
        $tables = ['users', 'subscriptions', 'invoices', 'payment_proofs', 'audit_packs'];
        $counts = [];
        foreach ($tables as $table) {
            if (Schema::hasTable($table) && Schema::hasColumn($table, 'company_id')) {
                $counts[$table] = DB::table($table)->where('company_id', $companyId)->count();
            }
        }
        ksort($counts);
        return [
            'version' => 1,
            'company_id' => $companyId,
            'scoped_row_counts' => $counts,
            'retained_after_purge' => ['admin_audit_logs', 'registered_credentials'],
        ];
    }

    private function event(int $requestId, int $companyId, string $event, string $guard,
        ?int $actorId, array $metadata = []): void
    {
        DB::table('company_deletion_request_events')->insert([
            'request_id' => $requestId,
            'company_id' => $companyId,
            'event' => $event,
            'actor_guard' => $guard,
            'actor_id' => $actorId,
            'metadata' => $metadata === [] ? null : AgentCoreSnapshotService::canonicalJson($metadata),
            'created_at' => now(),
        ]);
    }

    private function activeKey(int $companyId): string
    {
        return 'company:'.$companyId;
    }
}
