<?php

namespace App\Console\Commands;

use App\Services\InvoicePdfCacheService;
use App\Services\InvoiceZipBuilderService;
use Illuminate\Console\Command;

/**
 * A 50,000-invoice ZIP is measured in gigabytes and the live server's disk
 * quota is not. Nothing here is precious — the export can always be rebuilt —
 * so old archives are deleted aggressively.
 */
class PruneInvoiceZipExports extends Command
{
    protected $signature = 'invoice-zips:prune';

    protected $description = 'Delete expired bulk invoice ZIP exports and their files';

    public function handle(): int
    {
        $removed = InvoiceZipBuilderService::purgeExpired();
        $this->info("Pruned {$removed} expired invoice ZIP export(s).");

        // Rendered invoice PDFs are kept between downloads, but a company that
        // stopped downloading half a year ago should not hold disk forever.
        // Nothing is lost: a pruned invoice renders again on demand.
        $pdfs = InvoicePdfCacheService::prune();
        if ($pdfs > 0) {
            $this->info("Dropped {$pdfs} cached invoice PDF(s) nobody has asked for in months.");
        }

        return self::SUCCESS;
    }
}
