<?php

return [

    'enable_smart_di_import_safeguards' => env('FEATURE_SMART_DI_IMPORT_SAFEGUARDS', false),

    // Suggest mappings only; never authorize draft creation or FBR submission.
    'enable_smart_di_import_mapping' => env('FEATURE_SMART_DI_IMPORT_MAPPING', false),

    'enable_fbr_retry_system' => env('FEATURE_FBR_RETRY_SYSTEM', false),

    'enable_hs_mapping_manager' => env('FEATURE_HS_MAPPING_MANAGER', false),

    'enable_reduced_rate_mapping' => env('FEATURE_REDUCED_RATE_MAPPING', false),

];
