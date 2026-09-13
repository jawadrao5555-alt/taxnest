<?php
return [
    'repository' => 'jawadrao5555-alt/taxnest',
    'oidc_issuer' => 'https://token.actions.githubusercontent.com',
    'oidc_audience' => 'owner-approval-relay',
    'approval_ttl_minutes' => 30,
    // Keep failed GitHub dispatches self-healing. The poller runs every minute;
    // a short lease prevents an approved release needing a manual wake-up.
    'dispatch_lease_minutes' => 2,
    'allowed_workflow_refs' => [
        'approval-dispatch.yml' => 'jawadrao5555-alt/taxnest/.github/workflows/approval-dispatch.yml@refs/heads/main',
        'owner-merge-and-deploy.yml' => 'jawadrao5555-alt/taxnest/.github/workflows/owner-merge-and-deploy.yml@refs/heads/main',
        'deploy-production.yml' => 'jawadrao5555-alt/taxnest/.github/workflows/deploy-production.yml@refs/heads/main',
    ],
];
