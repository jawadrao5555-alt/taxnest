<?php

namespace App\Console\Commands;

use App\Services\KotPrintService;
use Illuminate\Console\Command;

/**
 * Independent overdue local-KOT handoff watchdog.
 *
 * Agent claim polls still run expireLocalHandoffs() as a fast path. This
 * command exists so recovery does not depend on an agent being online and
 * polling. It never reprints a content-fetched unknown outcome.
 */
class ExpireLocalKotHandoffs extends Command
{
    protected $signature = 'print:expire-local-handoffs';

    protected $description = 'Recover overdue local KOT handoffs into one cloud job without waiting for an agent poll';

    public function handle(): int
    {
        $out = KotPrintService::expireLocalHandoffsAll();
        $this->info('expired='.$out['expired'].' queued='.$out['queued'].' companies='.$out['companies']);

        return self::SUCCESS;
    }
}
