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
        'SERVER_HEALTH',
        'DAILY_OPS',
    ],

    /*
    |--------------------------------------------------------------------------
    | Operation scope (company required vs global vs dual)
    |--------------------------------------------------------------------------
    | company = company_id or resolvable company_name required
    | global  = fleet/platform; company_id ignored
    | dual    = company-scoped when a company is provided, else fleet
    */
    'operation_scopes' => [
        'COMPANY_HEALTH' => 'company',
        'BILLING_SUMMARY' => 'dual',
        'BILLING_BY_COMPANY' => 'global',
        'PRA_HEALTH' => 'dual',
        'AGENT_HEALTH' => 'dual',
        'PRINTER_HEALTH' => 'company',
        'ERROR_SUMMARY' => 'dual',
        'COMPANY_DIAGNOSTIC' => 'company',
        'PROBLEMATIC_COMPANIES' => 'global',
        'SERVER_HEALTH' => 'global',
        'DAILY_OPS' => 'global',
    ],

    /*
    |--------------------------------------------------------------------------
    | Platform health (read-only; no mutation)
    |--------------------------------------------------------------------------
    */
    'platform' => [
        'http_probes' => filter_var(env('LIVE_OPS_HTTP_PROBES', true), FILTER_VALIDATE_BOOLEAN),
        'http_probes_in_tests' => false,
        'http_timeout_seconds' => 5,
        'public_url' => env('LIVE_URL', env('APP_URL')),
        'websocket_health_url' => env('LIVE_OPS_WS_HEALTH_URL', 'http://127.0.0.1:6101/health'),
        'log_scan_bytes' => 262144,
        'systemd_in_tests' => false,
        'systemd_units' => [
            'php_fpm' => env('LIVE_FPM_SERVICE', 'php-fpm'),
            'apache' => env('LIVE_APACHE_SERVICE', 'httpd'),
            'mariadb' => env('LIVE_MARIADB_SERVICE', 'mariadb'),
            'queue' => env('LIVE_QUEUE_SERVICE', 'taxnest-queue'),
        ],
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

    /*
    |--------------------------------------------------------------------------
    | Company name resolver
    |--------------------------------------------------------------------------
    */
    'resolver' => [
        'min_partial_chars' => 3,
    ],

    /*
    |--------------------------------------------------------------------------
    | Autonomous owner-command engine
    |--------------------------------------------------------------------------
    */
    'autonomous' => [
        'max_fix_iterations' => 3,
        'low_operational_from_owner_command' => true,
        'stuck_print_minutes' => 5,
        'github_issue_prefix' => '[TAXNEST-OPS]',
        'bridge_label' => 'live-ops-command',
        'statuses' => [
            'INVESTIGATING',
            'DIAGNOSED',
            'FIXING',
            'TESTING',
            'READY_TO_DEPLOY',
            'DEPLOYING',
            'VERIFYING',
            'RESOLVED',
            'BLOCKED',
            'FAILED',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Change-set risk (ordinary safe auto-deploy vs fail-closed)
    |--------------------------------------------------------------------------
    | Deny wins. Unlisted application paths are treated as high-risk.
    | Docs-only changes are ignored by the classifier.
    */
    'risk' => [
        'auto_deploy_path_allow' => [
            'resources/views/',
            'resources/css/',
            'public/js/',
            'public/css/',
            'pra-agent/src/',
            'pra-agent/test/',
            'agent-realtime-gateway/src/',
            'app/Services/LiveOps/',
            'app/Http/Controllers/Api/LiveOpsRunnerController.php',
            'app/Console/Commands/LiveOps',
            'config/live_ops.php',
            'tests/Feature/LiveOps/',
            'tests/Feature/PosPrint',
            'scripts/cloud-live-ops',
            'scripts/ci-live-ops',
            'scripts/lib/live_ops_',
            'scripts/tests/live-ops-',
            'docs/ops/live-ops',
        ],
        'high_risk_path_deny' => [
            'database/migrations/',
            'app/Services/PosTaxMath.php',
            'app/Services/Tax',
            'app/Http/Middleware/AgentAuth.php',
            'app/Http/Middleware/Authenticate.php',
            'app/Http/Middleware/Company',
            '.env',
            '.github/workflows/deploy-production.yml',
            '.github/workflows/owner-merge-and-deploy.yml',
            'scripts/deploy-live.sh',
            'scripts/owner-merge-and-deploy.sh',
            'scripts/lib/owner-merge-and-deploy.py',
        ],
    ],
];
