<?php

namespace App\Console\Commands;

use App\Services\Pharmacy\MedicineCatalogueSyncService;
use App\Services\Pharmacy\MedicineSourceReview;
use Illuminate\Console\Command;

class ApplyEffectiveMedicinePrices extends Command
{
    protected $signature = 'catalogue:apply-effective-prices';

    protected $description = 'Apply due, unambiguous source prices to the catalogue; shop prices still require review';

    public function handle(MedicineCatalogueSyncService $service): int
    {
        $this->info('Due source prices applied: '.MedicineSourceReview::applyDue($service));

        return self::SUCCESS;
    }
}
