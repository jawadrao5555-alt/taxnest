<?php

return [
    'scope' => 'taxnest.reports.read',
    'timezone' => 'Asia/Karachi',
    'max_days' => 93,
    'access_minutes' => 60,
    'grant_days' => 30,
    // Exact callback URI. Add other trusted clients here after owner review; never wildcard origins.
    'redirect_uris' => array_values(array_filter(array_map('trim', explode(',', env('REPORTING_OAUTH_REDIRECT_URIS', 'https://chatgpt.com/connector_platform_oauth_redirect'))))),
    'allowed_origins' => ['https://chatgpt.com'],
];
