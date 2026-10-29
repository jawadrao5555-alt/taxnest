<?php

namespace App\Console\Commands;

use App\Services\OptionalLocalBillArchive;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

class LocalBillArchiveRestoreQuarantine extends Command
{
    protected $signature = 'local-support-copies:restore-quarantine {--confirm= : Must equal Quarantine optional copies}';
    protected $description = 'After restore, destroy optional support-copy keys/copies and disable consent before exposing restored storage';

    public function handle(OptionalLocalBillArchive $archive): int
    {
        if ($this->option('confirm') !== 'Quarantine optional copies') {
            $this->error('Explicit confirmation required: --confirm="Quarantine optional copies"');
            return self::FAILURE;
        }
        if (!Schema::hasTable('support_bill_archives')) {
            $this->info('Optional archive tables absent; no optional copies available.');
            return self::SUCCESS;
        }
        $archive->quarantineAfterRestore();
        $this->info('Optional support copies quarantined; consent OFF. Live invoices/accounting and company settings unchanged.');
        return self::SUCCESS;
    }
}
