<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * Single truth for "this process is talking to the LOCAL dev staging DB and
 * nothing else". Destructive dev-only tooling (video demo seeders, the fake
 * Desktop Agent loop) must call assertLocalStaging() before writing anything.
 *
 * Deliberately exact: a production schema whose name merely *contains*
 * "staging" must not pass, and neither must a remote host.
 */
final class DevStagingGuard
{
    public const DB_NAME = 'taxnest_staging';
    public const HOSTS = ['127.0.0.1', 'localhost'];

    /** @return string[] reasons the current connection is NOT the local staging DB (empty = OK) */
    public static function problems(): array
    {
        $conn = DB::connection();
        $cfg = $conn->getConfig();
        $problems = [];
        if (($cfg['driver'] ?? '') !== 'mysql') {
            $problems[] = "driver '" . ($cfg['driver'] ?? '?') . "' is not mysql";
        }
        if ($conn->getDatabaseName() !== self::DB_NAME) {
            $problems[] = "database '" . $conn->getDatabaseName() . "' is not " . self::DB_NAME;
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
            throw new \RuntimeException("{$tool} refused: not the local staging DB (" . implode('; ', $problems) . ').');
        }
    }
}
