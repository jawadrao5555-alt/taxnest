<?php
namespace App\Console\Commands;

use App\Services\PosWhatsAppOutbox;
use Illuminate\Console\Command;

class DrainPosWhatsAppOutbox extends Command
{
    protected $signature = 'pos:whatsapp-drain {--limit=5}';
    protected $description = 'Recover due opted-in POS WhatsApp bill deliveries';
    public function handle(PosWhatsAppOutbox $outbox): int
    {
        $this->info('Processed due entries: ' . $outbox->drain((int) $this->option('limit')));
        return self::SUCCESS;
    }
}
