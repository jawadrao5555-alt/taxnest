<?php

namespace App\Console\Commands;

use App\Services\RestoreSecurityQuarantine;
use Illuminate\Console\Command;

class RestoreSecurityQuarantineCommand extends Command
{
    protected $signature = 'security:restore-quarantine
        {--restore-id= : Unique operator restore identifier; retained only as SHA-256}
        {--confirm= : Must equal Quarantine restored access}';

    protected $description = 'After a database restore, revoke resurrected browser/reset/reporting/consultant access while preserving business rows and Agent pairing';

    public function handle(RestoreSecurityQuarantine $quarantine): int
    {
        if ($this->option('confirm') !== 'Quarantine restored access') {
            $this->error('Explicit confirmation required: --confirm="Quarantine restored access"');
            return self::FAILURE;
        }
        $restoreId = (string) $this->option('restore-id');
        if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{7,159}$/D', $restoreId)) {
            $this->error('A unique 8–160 character restore identifier is required.');
            return self::FAILURE;
        }

        $result = $quarantine->run($restoreId);
        $this->info($result['already_completed']
            ? 'This restore identifier was already quarantined; no second rotation was performed.'
            : 'Restored access quarantined. Company business rows and Desktop Agent pairing were not changed.');
        $this->warn('This command is post-restore containment evidence, not proof of backup freshness or a successful production restore.');
        return self::SUCCESS;
    }
}
