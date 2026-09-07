<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * Single truth for "this process is talking to a LOCAL disposable MariaDB
 * database and nothing else". Destructive dev-only tooling (video demo
 * seeders, the fake Desktop Agent loop, Cloud Agent local QA seed) must call
 * assertLocalStaging() before writing anything.
 *
 * Allowed DB names:
 *   - taxnest_staging — Replit / older local MySQL Staging (port 9000)
 *   - taxnest_dev     — Cloud Agent / docs MariaDB path (port 3306)
 *
 * Deliberately exact: a production schema whose name merely *contains*
 * "staging" or "dev" must not pass, and neither must a remote host.
 */
final class DevStagingGuard
{
    /** @deprecated Prefer DB_NAMES — kept for callers that expect a single legacy name */
    public const DB_NAME = 'taxnest_staging';

    /** Local disposable database names only (exact match). */
    public const DB_NAMES = ['taxnest_staging', 'taxnest_dev'];

    public const HOSTS = ['127.0.0.1', 'localhost'];

    /** @return string[] reasons the current connection is NOT a local disposable DB (empty = OK) */
    public static function problems(): array
    {
        $conn = DB::connection();
        $cfg = $conn->getConfig();
        $problems = [];
        if (($cfg['driver'] ?? '') !== 'mysql') {
            $problems[] = "driver '" . ($cfg['driver'] ?? '?') . "' is not mysql";
        }
        $dbName = (string) $conn->getDatabaseName();
        if (!in_array($dbName, self::DB_NAMES, true)) {
            $problems[] = "database '{$dbName}' is not one of " . implode('|', self::DB_NAMES);
        }
        $host = (string) ($cfg['host'] ?? '');
        if (!in_array($host, self::HOSTS, true)) {
            $problems[] = "host '{$host}' is not local";
        }
        if (!empty($cfg['url'])) {
            $problems[] = 'connection configured via DATABASE_URL (strip PG/URL env vars)';
        }
        return $problems;
    }

    /**
     * @param string $tool name used in the refusal message
     * @throws \RuntimeException when the opt-in flag or the DB identity is wrong
     */
    public static function assertLocalStaging(string $tool): void
    {
        if ((string) env('VIDEO_PIPELINE_ALLOW', '') !== '1') {
            throw new \RuntimeException("{$tool} refused: set VIDEO_PIPELINE_ALLOW=1 (recording script only).");
        }
        $problems = self::problems();
        if ($problems) {
            throw new \RuntimeException("{$tool} refused: not a local disposable DB (" . implode('; ', $problems) . ').');
        }
    }
}
