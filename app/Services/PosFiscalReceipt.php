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
        foreach (['ims'=>PosImsSubmission::class,'di'=>PosDiSandboxSubmission::class] as $channel=>$model) {
            if ($row && $channel!==$row->channel) continue;
            if (!Schema::hasTable((new $model)->getTable())) continue;
            $q=$model::where('company_id',$sale->company_id)->where('source_transaction_id',$sale->id);
            if ($row) $q->where('environment',$row->environment);
            foreach ($q->orderByDesc('id')->limit(2)->get() as $record) $records[]=[$channel,$record];
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
