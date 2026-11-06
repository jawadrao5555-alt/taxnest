<?php
namespace App\Services;
use App\Models\{PosTransaction, PosFederalOutbox, PosImsSubmission, PosDiSandboxSubmission};
use Illuminate\Support\Facades\Schema;

/** Current acknowledgement from original-sale evidence, shared by all thermal designs. */
class PosFiscalReceipt
{
    public function federal(PosTransaction $sale): ?array
    {
        if (!$sale->exists || !$sale->company_id) return null;
        $row=Schema::hasTable('pos_federal_outbox') ? PosFederalOutbox::where('company_id',$sale->company_id)
            ->where('source_transaction_id',$sale->id)->first() : null;
        $records=[];
        foreach (['production', 'sandbox'] as $environment) {
            foreach (['ims'=>PosImsSubmission::class,'di'=>PosDiSandboxSubmission::class] as $channel=>$model) {
                if ($row && $row->environment === $environment && $channel !== $row->channel) continue;
                if (!Schema::hasTable((new $model)->getTable())) continue;
                $matches = $model::where('company_id', $sale->company_id)->where('source_transaction_id', $sale->id)
                    ->where('environment', $environment)->orderByDesc('id')->limit(2)->get();
                foreach ($matches as $record) $records[] = [$channel, $record];
            }
            // A production record or queue entry takes precedence over any sandbox history.
            if ($records || $row?->environment === $environment) break;
        }
        if (count($records)>1) return ['label'=>'FBR','environment'=>'Review','state'=>'Multiple fiscal records · review','reference'=>null];
        if ($records) {
            [$channel,$record]=$records[0];
            $reference=$channel==='ims' ? $record->fiscal_number : $record->fiscalReference();
            $accepted=in_array($record->state,$channel==='ims' ? ['accepted','local_accepted'] : ['accepted'],true);
            return ['label'=>$channel==='ims' ? 'FBR POS' : 'FBR DI','environment'=>strtoupper($record->environment),
                'state'=>$record->state==='local_accepted' ? 'Local accepted · central unverified' : str_replace('_',' ',$record->state),
                'reference'=>$accepted && $reference ? $reference : null];
        }
        return $row ? ['label'=>$row->channel==='ims' ? 'FBR POS' : 'FBR DI','environment'=>strtoupper($row->environment),
            'state'=>str_replace('_',' ',$row->state),'reference'=>null] : null;
    }
}
