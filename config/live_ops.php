<?php

/**
 * Live TaxNest Operations — allow-lists, risk tiers, and hard limits.
 *
 * Cloud Agents are planners only. Privileged work runs in GitHub Environment
 * "production" (or admin session) through these fixed allow-lists.
 * Arbitrary SQL / shell / artisan is never accepted as input.
 */

return [

    /*
    |--------------------------------------------------------------------------
    | Diagnostic operations (read-only)
    |--------------------------------------------------------------------------
    */
    'diagnostic_operations' => [
        'COMPANY_HEALTH',
        'BILLING_SUMMARY',
        'BILLING_BY_COMPANY',
        'PRA_HEALTH',
        'AGENT_HEALTH',
        'PRINTER_HEALTH',
        'ERROR_SUMMARY',
        'COMPANY_DIAGNOSTIC',
        'PROBLEMATIC_COMPANIES',
    ],

    /*
    |--------------------------------------------------------------------------
    | Remediation actions by risk
    |--------------------------------------------------------------------------
    | HIGH / deny-by-default actions are listed for documentation and rejection.
    | They MUST NOT be executable via Live Ops remediation paths.
    */
    'remediation_actions' => [
        'low' => [
            'ENQUEUE_TEST_PRINT',
            'FORCE_AGENT_UPDATE_ADVERTISE',
            'REFRESH_OPERATIONAL_STATE',
            'RETRY_ONE_PRA_INVOICE',
        ],
        'medium' => [
            'REBIND_ASSIGNED_PRINTER',
            'ENQUEUE_AGENT_COMMAND',
        ],
        'high_denied' => [
            'REGENERATE_AGENT_API_KEY',
            'DISABLE_COMPANY_AGENT',
            'DESTRUCTIVE_DB_REPAIR',
            'BULK_BILLING_CHANGE',
            'ARBITRARY_SQL',
            'ARBITRARY_SHELL',
            'ARBITRARY_ARTISAN',
            'SSH',
            'CREDENTIAL_CHANGE',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Desktop agent pending commands (allow-listed only)
    |--------------------------------------------------------------------------
    */
    'agent_commands' => [
        'STATUS_REFRESH',
        'RESYNC',
        'UPLOAD_REDACTED_LOGS',
        'TEST_PRINT',
        'PRINTER_REFRESH',
        'SAFE_AGENT_RESTART',
    ],

    /*
    |--------------------------------------------------------------------------
    | Limits
    |--------------------------------------------------------------------------
    */
    'limits' => [
        'max_date_range_days' => 31,
        'max_companies_in_report' => 200,
        'max_error_lines' => 40,
        'max_log_chars_per_line' => 400,
        'max_pra_failures' => 25,
        'max_print_jobs' => 25,
        'max_devices' => 20,
        'agent_command_ttl_minutes' => 30,
        'remediation_ttl_hours' => 24,
    ],

    /*
    |--------------------------------------------------------------------------
    | Runner token (trusted Actions / server only — never Cloud Agent env)
    |--------------------------------------------------------------------------
    */
    'runner_token' => env('LIVE_OPS_RUNNER_TOKEN'),

    /*
    |--------------------------------------------------------------------------
    | Product scope
    |--------------------------------------------------------------------------
    */
    'product_types' => ['pos'], // NestPOS PRA focus

    'exclude_email_suffix' => '@scaletest.pk',
];
