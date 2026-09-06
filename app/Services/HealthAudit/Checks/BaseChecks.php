<?php

namespace App\Services\HealthAudit\Checks;

use App\Services\HealthAudit\HealthAuditContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Shared plumbing for the audit checks (Task 1554).
 *
 * Nothing here decides anything. It exists so that thirty-odd checks cannot
 * each invent their own way to spell "does this table exist", "who is user
 * 41", and "which screen does this row live on" — which is how an audit engine
 * ends up with one rule that quietly skips the branch boundary.
 */
abstract class BaseChecks
{
    /** Per-run memo of user id => display name. */
    protected static array $userNames = [];

    /** Per-run memo of doctor id => name. */
    protected static array $doctorNames = [];

    public static function flushCaches(): void
    {
        self::$userNames = [];
        self::$doctorNames = [];
    }

    /**
     * A numeric threshold, written straight into the SQL.
     *
     * It has to be a literal, not a bound parameter. PDO sends a bound PHP
     * float as a STRING, and SQLite sorts every text value above every number —
     * so `ABS(diff) > ?` with 1.0 bound reads as `2000 > '1'`, which is FALSE.
     * MySQL coerces and the rule fires; SQLite does not and the rule silently
     * never fires, which is the worst possible failure for a control check.
     *
     * Safe because every value passed here is a ruleset constant. Never call it
     * with anything a user typed.
     */
    protected static function num($value): string
    {
        return rtrim(rtrim(sprintf('%.6F', (float) $value), '0'), '.') ?: '0';
    }

    protected static function tableMissing(string ...$tables): bool
    {
        foreach ($tables as $table) {
            if (!Schema::hasTable($table)) {
                return true;
            }
        }

        return false;
    }

    /** Names for a set of user ids, resolved once per run. */
    protected static function userNames(array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        $missing = array_diff($ids, array_keys(self::$userNames));

        if (!empty($missing) && Schema::hasTable('users')) {
            foreach (DB::table('users')->whereIn('id', $missing)->get(['id', 'name', 'email']) as $row) {
                self::$userNames[(int) $row->id] = (string) ($row->name ?: $row->email);
            }
        }

        foreach ($ids as $id) {
            self::$userNames[$id] = self::$userNames[$id] ?? null;
        }

        return self::$userNames;
    }

    protected static function userName($id): ?string
    {
        if (!$id) {
            return null;
        }

        return self::userNames([$id])[(int) $id] ?? null;
    }

    protected static function doctorName($id): ?string
    {
        if (!$id) {
            return null;
        }

        $id = (int) $id;
        if (array_key_exists($id, self::$doctorNames)) {
            return self::$doctorNames[$id];
        }

        $name = Schema::hasTable('health_doctors')
            ? DB::table('health_doctors')->where('id', $id)->value('name')
            : null;

        return self::$doctorNames[$id] = $name ? (string) $name : null;
    }

    /**
     * A drill-down target.
     *
     * The capability travels WITH the link rather than being decided when the
     * link is built, because the person who ran the audit and the person
     * reading the pack are not always the same account. The workspace resolves
     * it against whoever is looking; a reader without the capability sees the
     * finding and its evidence, but no way through to the clinical screen.
     */
    protected static function link(string $route, array $params, string $capability): array
    {
        return ['route' => $route, 'params' => $params, 'cap' => $capability];
    }

    /**
     * Fence a row by the POSTING of the staff member it is about.
     *
     * Attendance corrections and the like carry neither a branch nor a ward of
     * their own; the person they concern is posted to one, and the reader may
     * see the row only when that posting sits inside their own fence. A staff
     * member with no posting is organisation-wide under a boundary (the
     * platform's NULL rule) and excluded by an explicit filter.
     */
    protected static function applyStaffPosting(HealthAuditContext $ctx, $query, string $userColumn)
    {
        if (!$ctx->branchId && !$ctx->departmentId
            && !is_array($ctx->branchBoundary) && !is_array($ctx->departmentBoundary)) {
            return $query;
        }

        return $query->whereExists(function ($sub) use ($ctx, $userColumn) {
            $sub->selectRaw('1')
                ->from('users as hav_staff')
                ->whereColumn('hav_staff.id', $userColumn);
            $ctx->applyBranch($sub, 'hav_staff.default_branch_id');
            $ctx->applyDepartment($sub, 'hav_staff.health_department_id');
        });
    }

    /** Money as a plain 2dp string, so a finding's params never drift by a float. */
    protected static function money($value): string
    {
        return number_format((float) $value, 2, '.', '');
    }

    protected static function dateOnly($value): ?string
    {
        if (!$value) {
            return null;
        }

        try {
            return \Illuminate\Support\Carbon::parse($value)->toDateString();
        } catch (\Throwable $e) {
            return null;
        }
    }
}
