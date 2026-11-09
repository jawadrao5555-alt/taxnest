<?php

namespace App\Console\Commands;

use App\Services\AuditPackRetention;
use Illuminate\Console\Command;

final class PruneAuditPacks extends Command
{
    protected $signature = 'audit-packs:prune {--limit=100 : Maximum export records per batch (1-500)}';

    protected $description = 'Expire old generated audit ZIPs in bounded batches, retaining their history.';

    public function handle(): int
    {
        $limit = (string) $this->option('limit');
        if (! ctype_digit($limit) || (int) $limit < 1 || (int) $limit > 500) {
            $this->error('Use --limit=1..500.');

            return self::FAILURE;
        }
        try {
            $result = AuditPackRetention::prune(null, (int) $limit);
        } catch (\Throwable $exception) {
            $this->error('Audit export retention is unavailable.');

            return self::FAILURE;
        }
        $this->info('Audit export records examined='.$result['examined'].' expired='.$result['expired'].' failed='.$result['failed']);

        return $result['failed'] ? self::FAILURE : self::SUCCESS;
    }
}
