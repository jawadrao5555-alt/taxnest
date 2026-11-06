<?php

return [
    // Per-company/environment reviewed rules remain OFF until explicitly saved.
    'automatic_enabled' => (bool) env('POS_FEDERAL_AUTOMATION_ENABLED', true),
    // Legacy local disposable-data rehearsal.
    'enabled' => (bool) env('PRA_DI_SANDBOX_ENABLED', false),
    // Owner setup is available on hosted servers. Every company starts OFF;
    // only a separately saved sandbox token + explicit company opt-in can send.
    // No company is enabled automatically; checkout does not auto-submit.
    'hosted_enabled' => (bool) env('PRA_DI_SANDBOX_HOSTED_ENABLED', true),
    'production_enabled' => (bool) env('PRA_DI_PRODUCTION_ENABLED', true),
];
