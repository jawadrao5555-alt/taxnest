<?php

namespace App\Console\Commands;

use App\Services\KotPrintService;
use Illuminate\Console\Command;

/**
 * Independent overdue local-KOT handoff watchdog.
 *
 * Agent claim polls still run expireLocalHandoffs() as a fast path. This
 * command exists so recovery does not depend on an agent being online and
 * polling. Dead/unresponsive shop PCs become Action Required immediately
 * (no silent 5-minute wait, no content-fetched reprint). Still-online hung
 * drains past the official handoff timeout become one cloud KOT.
 */
class ExpireLocalKotHandoffs extends Command
{
    protected $signature = 'print:expire-local-handoffs';

    protected $description = 'Recover dead-agent KOT handoffs as Action Required, and overdue still-online handoffs into one cloud job, without waiting for an agent poll';

    public function handle(): int
    {
        $out = KotPrintService::expireLocalHandoffsAll();
        $this->info('expired='.$out['expired']
            .' queued='.$out['queued']
            .' action_required='.($out['action_required'] ?? 0)
            .' cloud_requeued='.($out['cloud_requeued'] ?? 0)
            .' companies='.$out['companies']);

        return self::SUCCESS;
    }
}
