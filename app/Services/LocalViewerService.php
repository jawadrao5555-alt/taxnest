<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Local Bills Portal viewer accounts (pos_role='local_viewer').
 *
 * These read-only logins are managed from TWO panels over the SAME rows: the
 * SaaS admin company page and — since Task 665 — the shop owner himself on
 * /pos/local-bills. This class is the single truth for the company-wide rules
 * both panels must obey, so a cap enforced in one place cannot be walked
 * around from the other.
 */
class LocalViewerService
{
    /**
     * How many viewer accounts ONE company may keep, counted across both
     * panels. Deliberately small: each login exposes every local bill of the
     * shop.
     */
    public const MAX_PER_COMPANY = 2;

    /** Company-scoped viewer query — the only way these rows are selected. */
    public static function query(int $companyId)
    {
        return User::where('company_id', $companyId)->where('pos_role', 'local_viewer');
    }

    public static function countFor(int $companyId): int
    {
        return self::query($companyId)->count();
    }

    public static function atCapacity(int $companyId): bool
    {
        return self::countFor($companyId) >= self::MAX_PER_COMPANY;
    }

    /**
     * Create a viewer account, or return NULL when the company is already at
     * capacity. Both panels create through here.
     *
     * Count + insert run inside one transaction behind a lock on the company
     * row, so two simultaneous creates (owner and support clicking at the same
     * moment, or a double-submit) can never land a third account: the second
     * transaction waits, then re-counts and refuses.
     */
    public static function create(int $companyId, string $name, string $email, string $plainPassword): ?User
    {
        return DB::transaction(function () use ($companyId, $name, $email, $plainPassword) {
            // Serialize concurrent creates for THIS company only.
            DB::table('companies')->where('id', $companyId)->lockForUpdate()->first();

            if (self::atCapacity($companyId)) {
                return null;
            }

            return User::create(ViewablePasswordService::apply([
                'name' => $name,
                'email' => $email,
                'password' => bcrypt($plainPassword),
                'company_id' => $companyId,
                'role' => 'employee',
                'pos_role' => 'local_viewer',
                'is_active' => true,
            ], $plainPassword));
        });
    }

    /** Shared "you are at the limit" wording for both panels. */
    public static function capacityMessage(): string
    {
        return 'Limit reached — a shop can keep at most ' . self::MAX_PER_COMPANY
            . ' Local Bills viewer accounts (owner-created and admin-created share this limit). Delete one first.';
    }
}
