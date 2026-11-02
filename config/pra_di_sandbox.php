<?php

return [
    // Preparation workbench only. Production is independently refused in code.
    'enabled' => (bool) env('PRA_DI_SANDBOX_ENABLED', false),
];
