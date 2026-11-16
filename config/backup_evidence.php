<?php
return [
    // Explicit operator enrollment only. No enabled default or global tenant attribution.
    // source UUID => ['enabled'=>true, 'token_sha256'=>64-char hash,
    //                 'company_ids'=>[explicit tenant IDs]]
    // Provision through trusted deployment configuration; never commit real tokens/hashes.
    'sources' => [],
    'evidence_retention_days' => 90,
];
