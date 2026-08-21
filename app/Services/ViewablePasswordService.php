<?php

namespace App\Services;

use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Schema;

/**
 * Owner/admin-viewable password copies (owner rule, Jul 2026).
 *
 * Password hashes are irreversible, but the owner insists on SEEING the
 * passwords of the accounts he hands out (POS team on /pos/team, Local Bills
 * viewers on /pos/local-bills). `users.pos_team_password_enc` therefore keeps
 * a Crypt copy alongside the hash.
 *
 * SINGLE TRUTH for that copy. Every write path that sets one of these
 * accounts' passwords must go through apply() — a path that forgets it leaves
 * the owner staring at a password that no longer works. Local Bills viewers
 * are written from TWO panels (the shop owner's portal section AND the SaaS
 * admin company page), which is exactly how a copy goes stale.
 *
 * Every read goes through reveal() (APP_KEY rotation / corrupt payload must
 * degrade to "not stored", never a 500).
 */
class ViewablePasswordService
{
    /** users.pos_team_password_enc — TEXT column (encrypted values overflow varchar). */
    public const COLUMN = 'pos_team_password_enc';

    /**
     * Roles that keep a viewable copy. The owner's own pos_admin account never
     * does — nobody manages it from a panel.
     */
    public const VIEWABLE_ROLES = [
        'pos_cashier', 'pos_manager', 'pos_kitchen', 'pos_waiter', 'pos_delivery',
        // Local Bills Portal read-only login (Task 665): the shop owner creates
        // and re-reads it from /pos/local-bills.
        'local_viewer',
    ];

    public static function supports(?string $posRole): bool
    {
        return in_array($posRole, self::VIEWABLE_ROLES, true);
    }

    /**
     * Add the encrypted copy to an attribute array (create or update payload).
     * PROD schema-drift guard: cPanel pulls code before `migrate --force`, so a
     * missing column must silently skip instead of blowing up the write.
     */
    public static function apply(array $data, ?string $plainPassword): array
    {
        if ($plainPassword === null || $plainPassword === '') {
            return $data;
        }
        if (!self::columnExists()) {
            return $data;
        }
        $data[self::COLUMN] = Crypt::encryptString($plainPassword);

        return $data;
    }

    /** Decrypt a stored copy for display; null when absent/unreadable. */
    public static function reveal(?string $encrypted): ?string
    {
        if (empty($encrypted)) {
            return null;
        }
        try {
            return Crypt::decryptString($encrypted);
        } catch (\Throwable $e) {
            // APP_KEY rotated or corrupt payload — treat as "not stored".
            return null;
        }
    }

    private static function columnExists(): bool
    {
        try {
            return Schema::hasColumn('users', self::COLUMN);
        } catch (\Throwable $e) {
            return false;
        }
    }
}
