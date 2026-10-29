<?php

namespace App\Console\Commands;

use App\Services\OptionalLocalBillArchive;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

class LocalBillArchiveCleanup extends Command
{
    protected $signature = 'local-support-copies:cleanup {--limit=100 : Bounded expiry batch, 1–1000}';
    protected $description = 'Remove expired optional support copies without touching live bills or settings';

    public function handle(OptionalLocalBillArchive $archive): int
    {
        if (!Schema::hasTable('support_bill_archives')) return self::SUCCESS;
        if (!ctype_digit((string) $this->option('limit')) || (int) $this->option('limit') < 1 || (int) $this->option('limit') > 1000) {
            $this->error('Choose a batch limit between 1 and 1000.');
            return self::FAILURE;
        }
        $this->info('Expired optional copies removed: '.$archive->cleanup((int) $this->option('limit')));
        return self::SUCCESS;
    }
}
