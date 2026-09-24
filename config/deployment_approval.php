<?php
return [
    'repository' => 'jawadrao5555-alt/taxnest',
    'oidc_issuer' => 'https://token.actions.githubusercontent.com',
    'oidc_audience' => 'owner-approval-relay',
    'approval_ttl_minutes' => 30,
    // Keep failed GitHub dispatches self-healing. The poller runs every minute
    // when GitHub actually schedules it; a short lease prevents an approved
    // release needing a second TaxNest Admin approval.
    'dispatch_lease_minutes' => 2,
    // A claim must leave enough approval lifetime to merge and register the deploy run.
    // GitHub cron can pick a request up seconds before expiry otherwise.
    'handoff_min_remaining_minutes' => 5,
    // GitHub cron is not a guaranteed timer. Warn the owner when the last
    // authenticated poller heartbeat is older than this.
    'schedule_delay_warn_minutes' => 20,
    // Deliberately dormant. A future GitHub App driver must mint short-lived
    // installation tokens outside stored application data and preserve every
    // exact-SHA/OIDC/provenance gate. Merely changing this value cannot enable
    // dispatch: no credential-bearing driver is shipped.
    'immediate_dispatch' => [
        'driver' => 'disabled',
    ],
    'allowed_workflow_refs' => [
        'approval-dispatch.yml' => 'jawadrao5555-alt/taxnest/.github/workflows/approval-dispatch.yml@refs/heads/main',
        'owner-merge-and-deploy.yml' => 'jawadrao5555-alt/taxnest/.github/workflows/owner-merge-and-deploy.yml@refs/heads/main',
        'deploy-production.yml' => 'jawadrao5555-alt/taxnest/.github/workflows/deploy-production.yml@refs/heads/main',
    ],
];
