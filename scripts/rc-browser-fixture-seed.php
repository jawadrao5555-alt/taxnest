<?php
declare(strict_types=1);
// RECOVERY BLOCKER, deliberately executable rather than a missing reference.
// The original synthetic Hotel/Service/Health/FBR fixture seeder was lost with
// the temporary worktree (11:04–11:06 UTC) and cannot be safely reconstructed
// from summaries without inventing role grants and product data.
fwrite(STDERR, "RC BROWSER FIXTURE BLOCKED: the lost synthetic fixture seeder must be recovered before fresh setup. Do not substitute application/demo/customer seeders. Existing isolated data may be used only through scripts/rc-browser-fixture-resume.php.\n");
exit(2);