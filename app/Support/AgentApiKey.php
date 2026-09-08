<?php

namespace App\Support;

use Illuminate\Support\Facades\Schema;

/**
 * Desktop-agent API key helpers (security remediation, Sep 2026).
 *
 * The key itself ('tnk_' . 48 random chars) is still stored in plaintext on
 * companies.agent_api_key because agents in the field and the owner-facing
 * panels depend on it. Authentication, however, goes through the sha256
 * mirror column companies.agent_api_key_hash (see AgentAuth). This class
 * owns the hashing convention and the "does the hash column exist yet?" memo
 * so a deploy that runs before the migration keeps working on plaintext.
 */
final class AgentApiKey
{
    private const RECHECK_SECONDS = 30.0;

    private static ?bool $hashColumn = null;

    private static float $hashColumnAt = 0.0;

    public static function hash(string $key): string
    {
        return hash('sha256', $key);
    }

    /**
     * A YES is permanent for the process; a NO is re-checked shortly after so
     * a long-lived worker that booted before the migration picks it up.
     */
    public static function hashColumnAvailable(): bool
    {
        if (self::$hashColumn === true) {
            return true;
        }
        if (self::$hashColumn === false && (microtime(true) - self::$hashColumnAt) < self::RECHECK_SECONDS) {
            return false;
        }

        try {
            self::$hashColumn = Schema::hasColumn('companies', 'agent_api_key_hash');
        } catch (\Throwable $e) {
            self::$hashColumn = false;
        }
        self::$hashColumnAt = microtime(true);

        return self::$hashColumn;
    }

    /** Tests rebuild the schema between cases; the memo must not outlive it. */
    public static function flushSchemaCache(): void
    {
        self::$hashColumn = null;
        self::$hashColumnAt = 0.0;
    }
}
