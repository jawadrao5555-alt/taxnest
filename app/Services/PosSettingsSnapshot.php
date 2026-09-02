<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Regression guard for feature upgrades (owner rule, Sep 2026).
 *
 * The rule this exists to enforce: shipping a feature must change the behaviour
 * of THAT feature and nothing else. A shop's saved settings, per-branch values,
 * staff permissions, feature toggles and saved preferences must survive every
 * deploy untouched.
 *
 * Reading the diff of a migration is not proof. This takes a deterministic
 * snapshot of the live settings surface before a deploy, takes another one
 * after, and names every row/column that moved. Anything it reports is either
 * a deliberate change (allow-list it) or the regression we are trying to catch.
 *
 * DENY-LIST, NOT ALLOW-LIST
 * ------------------------
 * Columns are included by default and excluded only when they match a volatile
 * pattern. That is the whole point: a setting added next month is covered
 * automatically, without anyone remembering to register it here. An allow-list
 * would silently stop protecting the newest settings — exactly the ones most
 * likely to be mishandled by the migration that introduced them.
 */
class PosSettingsSnapshot
{
    /**
     * Tables whose rows carry per-company configuration.
     *
     * Each entry: table => the column that identifies the owning company (used
     * for --company filtering and for readable diff output). A table that does
     * not exist on this database is skipped, never fatal — dev, staging and
     * live are routinely a migration apart.
     */
    public const TABLES = [
        'companies' => 'id',
        'branches' => 'company_id',
        'branch_user' => null,   // pivot: user↔branch access level, no company column
        'users' => 'company_id',
    ];

    /**
     * Tables whose snapshot is column-SCOPED instead of deny-list filtered.
     *
     * `users` is the one place where "every non-volatile column" is the wrong
     * answer: the table is the biggest on the shard and most of it is identity,
     * not configuration. Snapshotting all of it would push names and email
     * addresses into a baseline file that lives on disk between deploy stages,
     * and would blow PHP's memory limit on a large host — which then trips the
     * non-fatal baseline bypass and silently disarms the guard.
     *
     * Only columns that actually decide what a staff member may DO are listed.
     * Missing ones are skipped, so this is safe across schema versions.
     */
    public const SCOPED_COLUMNS = [
        'users' => [
            'company_id', 'branch_id', 'role', 'pos_role', 'permissions',
            'pos_custom_access', 'is_active', 'is_pos_cashier', 'pos_can_reprint',
            'pos_till_id', 'status',
        ],
    ];

    /**
     * Volatile columns: they move during normal trading, so including them
     * would bury a real regression under thousands of expected diffs.
     *
     * Patterns are matched case-insensitively against the column name.
     */
    public const VOLATILE_PATTERNS = [
        '/^id$/',
        '/^(created_at|updated_at|deleted_at)$/',
        '/_at$/',            // last_seen_at, trial_ends_at, suspended_at, ...
        '/_on$/',
        '/token/',           // api/fbr/pra/session tokens rotate on their own
        '/password/',
        '/secret/',
        '/_key$/',
        '/remember_me/',
        '/^last_/',
        '/last_seen/',       // agent_last_seen, pos_last_seen, ...
        '/heartbeat/',
        '/_count$/',
        '/_counter$/',
        '/_seq$/',
        '/_used$/',
        '/_usage$/',
        '/_balance$/',
        '/^current_/',
        '/_credits?$/',
    ];

    /**
     * Columns that are settings even though they match a volatile pattern.
     * Checked BEFORE the deny-list, so this is the escape hatch for a genuine
     * setting whose name happens to look like telemetry.
     */
    public const FORCE_INCLUDE = [
        'companies' => ['pos_dayclose_cutoff', 'pos_business_day_start'],
        'branches' => [],
    ];

    /**
     * Build the snapshot.
     *
     * @param  int|null  $companyId  Limit to one company (null = every company).
     * @return array{generated_at:string,connection:string,tables:array<string,array<string,array<string,mixed>>>}
     */
    public function capture(?int $companyId = null): array
    {
        $tables = [];

        foreach (self::TABLES as $table => $companyColumn) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            $columns = $this->settingColumns($table);
            if ($columns === []) {
                continue;
            }

            // The row key must be the primary key even when the company column
            // is something else (users/branches): several rows per company.
            $keyColumn = Schema::hasColumn($table, 'id') ? 'id' : (string) $companyColumn;
            $select = array_merge([$keyColumn], $columns);
            if ($companyColumn !== null) {
                $select[] = $companyColumn;
            }
            $select = array_values(array_unique($select));

            $query = DB::table($table)->select($select)->orderBy($keyColumn);
            if ($companyId !== null && $companyColumn !== null && Schema::hasColumn($table, $companyColumn)) {
                $query->where($companyColumn, $companyId);
            }

            $rows = [];
            // Chunked: a live shard has thousands of companies and tens of
            // thousands of users; a single get() would hold them all in memory.
            $query->chunk(500, function ($chunk) use (&$rows, $keyColumn, $companyColumn, $columns) {
                foreach ($chunk as $row) {
                    $arr = (array) $row;
                    $values = [];
                    foreach ($columns as $col) {
                        $values[$col] = $this->normalize($arr[$col] ?? null);
                    }
                    // The company id rides along so the diff can say WHICH shop
                    // moved, not just "users row 8412".
                    $values['_company_id'] = ($companyColumn !== null && isset($arr[$companyColumn]))
                        ? (string) $arr[$companyColumn]
                        : null;
                    $rows[(string) $arr[$keyColumn]] = $values;
                }
            });

            $tables[$table] = $rows;
        }

        return [
            'generated_at' => now()->toIso8601String(),
            'connection' => (string) config('database.default'),
            'company_id' => $companyId,
            'tables' => $tables,
        ];
    }

    /**
     * Settings-bearing columns of a table, deny-list filtered.
     *
     * @return list<string>
     */
    public function settingColumns(string $table): array
    {
        $existing = Schema::getColumnListing($table);

        // Column-scoped tables take only what is listed, and only what exists.
        if (isset(self::SCOPED_COLUMNS[$table])) {
            $out = array_values(array_intersect(self::SCOPED_COLUMNS[$table], $existing));
            sort($out);

            return $out;
        }

        $forced = array_map('strtolower', self::FORCE_INCLUDE[$table] ?? []);
        $out = [];

        foreach ($existing as $column) {
            $lower = strtolower($column);
            if (in_array($lower, $forced, true)) {
                $out[] = $column;
                continue;
            }
            if ($this->isVolatile($lower)) {
                continue;
            }
            $out[] = $column;
        }

        sort($out);

        return $out;
    }

    public function isVolatile(string $column): bool
    {
        foreach (self::VOLATILE_PATTERNS as $pattern) {
            if (preg_match($pattern, $column) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * Compare two snapshots.
     *
     * The only finding that fails a deploy is `changed`: a row that existed
     * before and whose stored value moved. New rows (a shop signed up), removed
     * rows (a shop was deleted) and new columns (the feature being shipped) are
     * reported for context but are never regressions by themselves.
     *
     * @param  list<string>  $allow  Column names whose change is expected this deploy.
     * @return array{changed:list<array<string,mixed>>,added_rows:array<string,int>,removed_rows:array<string,int>,new_columns:array<string,list<string>>,dropped_columns:array<string,list<string>>,allowed:list<array<string,mixed>>}
     */
    public function diff(array $before, array $after, array $allow = []): array
    {
        $allow = array_map('strtolower', $allow);
        $result = [
            'changed' => [],
            'allowed' => [],
            'added_rows' => [],
            'removed_rows' => [],
            'new_columns' => [],
            'dropped_columns' => [],
            'dropped_tables' => [],
        ];

        $beforeTables = $before['tables'] ?? [];
        $afterTables = $after['tables'] ?? [];

        foreach ($beforeTables as $table => $beforeRows) {
            // A settings table that vanished is the loudest possible regression:
            // every value it held is gone. Reported on its own so it can never
            // be mistaken for "N rows removed" (a shop being deleted).
            if ($beforeRows !== [] && ! array_key_exists($table, $afterTables)) {
                $result['dropped_tables'][] = $table;
                continue;
            }

            $afterRows = $afterTables[$table] ?? [];

            $beforeCols = $this->columnsOf($beforeRows);
            $afterCols = $this->columnsOf($afterRows);
            $newCols = array_values(array_diff($afterCols, $beforeCols));
            $droppedCols = array_values(array_diff($beforeCols, $afterCols));
            if ($newCols !== []) {
                $result['new_columns'][$table] = $newCols;
            }
            if ($droppedCols !== []) {
                $result['dropped_columns'][$table] = $droppedCols;
            }

            $removed = 0;
            foreach ($beforeRows as $key => $beforeRow) {
                if (! array_key_exists($key, $afterRows)) {
                    $removed++;
                    continue;
                }
                $afterRow = $afterRows[$key];
                foreach ($beforeRow as $col => $beforeValue) {
                    if ($col === '_company_id' || ! array_key_exists($col, $afterRow)) {
                        continue; // a dropped column is reported once, above
                    }
                    if ($afterRow[$col] === $beforeValue) {
                        continue;
                    }
                    $finding = [
                        'table' => $table,
                        'row' => (string) $key,
                        'company_id' => $beforeRow['_company_id'] ?? null,
                        'column' => $col,
                        'before' => $beforeValue,
                        'after' => $afterRow[$col],
                    ];
                    if (in_array(strtolower($col), $allow, true)) {
                        $result['allowed'][] = $finding;
                    } else {
                        $result['changed'][] = $finding;
                    }
                }
            }
            if ($removed > 0) {
                $result['removed_rows'][$table] = $removed;
            }

            $added = count(array_diff_key($afterRows, $beforeRows));
            if ($added > 0) {
                $result['added_rows'][$table] = $added;
            }
        }

        // A table that only exists in the AFTER snapshot is a brand-new table.
        foreach (array_diff_key($afterTables, $beforeTables) as $table => $afterRows) {
            if ($afterRows !== []) {
                $result['added_rows'][$table] = count($afterRows);
            }
        }

        return $result;
    }

    /**
     * Values are compared as strings so a driver difference can never be
     * mistaken for a settings change. Live PDO hands back non-cast integer
     * columns as strings while dev hands back ints — comparing raw would report
     * every single boolean toggle on the shard as "changed".
     */
    private function normalize(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }
        if (is_float($value)) {
            // 12.50 and 12.5 are the same setting.
            return rtrim(rtrim(sprintf('%.6F', $value), '0'), '.');
        }
        $str = (string) $value;

        // JSON settings columns are compared by MEANING, not by byte order:
        // re-saving a preferences blob legitimately reorders its keys, and that
        // must not read as a wiped setting.
        $trimmed = ltrim($str);
        if ($trimmed !== '' && ($trimmed[0] === '{' || $trimmed[0] === '[')) {
            $decoded = json_decode($str, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                $this->ksortRecursive($decoded);
                $canonical = json_encode($decoded, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                if ($canonical !== false) {
                    return $canonical;
                }
            }
        }

        return $str;
    }

    private function ksortRecursive(array &$arr): void
    {
        ksort($arr);
        foreach ($arr as &$v) {
            if (is_array($v)) {
                $this->ksortRecursive($v);
            }
        }
    }

    /** @return list<string> */
    private function columnsOf(array $rows): array
    {
        $first = reset($rows);
        if (! is_array($first)) {
            return [];
        }
        $cols = array_keys($first);

        return array_values(array_filter($cols, fn ($c) => $c !== '_company_id'));
    }
}
