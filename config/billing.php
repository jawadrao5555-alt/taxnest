<?php
return [
    'shared_configured_cycles' => env('BILLING_SHARED_CONFIGURED_CYCLES', false),
    // Explicit rollout only. Existing paid terms/frozen quotes are never rewritten.
    'disable_fresh_monthly' => env('BILLING_DISABLE_FRESH_MONTHLY', false),
];
