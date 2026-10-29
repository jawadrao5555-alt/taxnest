<?php

return [
    // Kept outside the public disk and SQL backups. Never restore old keys over
    // a current keyring: see docs/ops/optional-local-bill-archive-restore.md.
    'key_root' => storage_path('app/private/local-bill-archive-keys'),
    'max_payload_bytes' => 1048576,
];
