<?php

namespace App\Support;

/**
 * Detects "MySQL is unreachable / overloaded" errors — the shared-host outage
 * class (evening-rush MySQL blips) — as opposed to ordinary query errors
 * (bad SQL, missing column, constraint violation), which must keep their
 * normal behavior untouched.
 */
class DatabaseDown
{
    /**
     * MySQL server-level error codes that mean the SERVER is unavailable,
     * not that the query was wrong.
     *
     * 1040 Too many connections        1203 max_user_connections (per user)
     * 1226 max_..._connections quota   2002 can't connect (socket/refused)
     * 2003 can't connect (TCP)         2006 server has gone away
     * 2013 lost connection mid-query
     */
    private const CODES = [1040, 1203, 1226, 2002, 2003, 2006, 2013];

    private const MESSAGE_MARKERS = [
        'connection refused',
        'connection timed out',
        'server has gone away',
        'too many connections',
        'max_user_connections',
        'lost connection to mysql',
        'no such file or directory',      // unix socket missing (mysqld down)
        'name or service not known',      // DNS for DB host failed
        'php_network_getaddresses',
    ];

    /**
     * True when the exception (or anything in its previous-chain) is a
     * database CONNECTION failure.
     */
    public static function isConnectionFailure(\Throwable $e): bool
    {
        for ($t = $e; $t !== null; $t = $t->getPrevious()) {
            if ($t instanceof \PDOException) {
                $driverCode = $t->errorInfo[1] ?? null;
                if (in_array((int) $driverCode, self::CODES, true)) {
                    return true;
                }
                // Connect-time PDOExceptions often carry the code directly
                // (e.g. 2002) instead of in errorInfo.
                if (in_array((int) $t->getCode(), self::CODES, true)) {
                    return true;
                }
            }

            $msg = strtolower($t->getMessage());
            foreach (self::MESSAGE_MARKERS as $marker) {
                if (str_contains($msg, $marker)) {
                    return true;
                }
            }
        }

        return false;
    }
}
