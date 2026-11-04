<?php
namespace App\Console\Commands;
use Illuminate\Console\Command;
use App\Models\PosFederalOutbox;
use App\Services\PosFederalAutomation;
class ProcessPosFederalOutbox extends Command
{
    protected $signature='pos:federal-outbox {--limit=25}';
    protected $description='Process reviewed automatic federal reporting intents; never retry ambiguous dispatch';
    public function handle(PosFederalAutomation $service): int
    {
        if (!$service->available()) return self::SUCCESS;
        PosFederalOutbox::where('state','processing')->where('started_at','<',now()->subMinutes(15))
            ->update(['state'=>'verification_required','error_code'=>'VERIFY_INTERRUPTED_DISPATCH','updated_at'=>now()]);
        $ids=PosFederalOutbox::where('state','queued')->orderBy('id')->limit(max(1,min(100,(int)$this->option('limit'))))->pluck('id');
        foreach ($ids as $id) $service->process($id);
        return self::SUCCESS;
    }
}
