<?php

namespace App\Console\Commands;

use App\Services\CompanyProductId;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class SyncCompanyProductIds extends Command
{
    protected $signature = 'companies:sync-product-ids';

    protected $description = 'Assign missing product Company IDs and reconcile product moves after a code rollback';

    public function handle(): int
    {
        $database = DB::connection();
        if (!CompanyProductId::available($database)) {
            $this->error('Run the Company ID migration before synchronizing IDs.');

            return self::FAILURE;
        }

        CompanyProductId::backfill($database);
        $this->info('Product Company IDs synchronized.');

        return self::SUCCESS;
    }
}
