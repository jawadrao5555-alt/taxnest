<?php

return [
    // Legacy local disposable-data rehearsal.
    'enabled' => (bool) env('PRA_DI_SANDBOX_ENABLED', false),
    // Owner setup is available on hosted servers. Every company starts OFF;
    // only a separately saved sandbox token + explicit company opt-in can send.
    // This never enables the FBR production endpoint or automatic checkout.
    'hosted_enabled' => (bool) env('PRA_DI_SANDBOX_HOSTED_ENABLED', true),
];
