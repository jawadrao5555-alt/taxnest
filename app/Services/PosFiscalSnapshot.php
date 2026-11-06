<?php
namespace App\Services;
use App\Models\Company;
use App\Models\PosTransaction;
use Illuminate\Validation\ValidationException;
final class PosFiscalSnapshot
{
    public static function capture(Company $company, PosTransaction $sale, bool $allowStoredRecovery=false): array
    {
        abort_unless((int)$sale->company_id === (int)$company->id && $sale->status === 'completed',422);
        abort_unless(in_array($sale->transaction_type ?? 'sale',['sale','return'],true),422);
        $sourceLines=$sale->items()->orderBy('id')->get();
        $recovered=null;
        if ($allowStoredRecovery && $sourceLines->contains(fn($l)=>$l->taxable_base===null || $l->exempt_base===null || $l->tax_amount===null)) {
            $recovered=PosStoredFiscalRecovery::recover($sale);
        }
        $lines=$recovered['lines'] ?? $sourceLines->flatMap(function($line) use($sale,$allowStoredRecovery){
            $deal=$line->deal_snapshot;
            if ($allowStoredRecovery && is_array($deal) && PosDealAllocation::supported($deal)
                && collect($deal)->every(fn($c)=>isset($c['allocation']['taxable_base'],$c['allocation']['exempt_base'],
                    $c['allocation']['tax'],$c['allocation']['tax_rate'],$c['allocation']['total']))) {
                return array_map(fn($c)=>['name'=>$c['ItemName'],'quantity'=>(float)$c['Quantity'],
                    'base'=>(float)$c['SaleValue'],'pra_tax'=>(float)$c['TaxCharged'],'tax_rate'=>(float)$c['TaxRate']],
                    PosDealAllocation::payloadItems($deal,(float)$line->quantity));
            }
            if ($line->taxable_base === null || $line->exempt_base === null || $line->tax_amount === null) {
                throw ValidationException::withMessages(['transaction_id'=>'Stored line tax snapshots are missing. Legacy amounts will not be guessed.']);
            }
            return [['name'=>$line->item_name,'quantity'=>(float)$line->quantity,
                'base'=>round((float)$line->taxable_base+(float)$line->exempt_base,2),'pra_tax'=>(float)$line->tax_amount,
                'tax_rate'=>$line->tax_rate ?? $sale->tax_rate]];
        })->values()->all();
        abort_if(!$lines,422,'The sale has no lines.');
        $ntn=trim($company->fbr_registration_no ?: $company->ntn ?: '');
        if (preg_match('/^([0-9]{7})-[0-9]$/',$ntn,$m)) $ntn=$m[1];
        if (preg_match('/^[0-9]{5}-[0-9]{7}-[0-9]$/',$ntn)) $ntn=str_replace('-','',$ntn);
        return ['company_name'=>$company->name,'seller_ntn'=>$company->ntn,
            'seller_fbr_name'=>$company->fbr_business_name ?: $company->name,'seller_fbr_id'=>$ntn,
            'seller_address'=>$company->address,'seller_province'=>$company->province,
            'invoice_number'=>$sale->invoice_number,'date'=>$sale->created_at->format('Y-m-d'),
            'date_time'=>$sale->created_at->format('Y-m-d H:i:s'),
            'transaction_type'=>$sale->transaction_type ?? 'sale','parent_transaction_id'=>$sale->parent_transaction_id,
            'subtotal'=>(float)$sale->subtotal,'discount'=>(float)$sale->discount_amount,
            'pra_tax'=>(float)$sale->tax_amount,'total'=>(float)$sale->total_amount,
            'fbr_service_charge'=>(float)($sale->fbr_service_charge ?? 0),
            'customer_id'=>$sale->customer_id,'customer_name'=>$sale->customer_name,
            'payment_method'=>$sale->payment_method,'pra_status'=>$sale->pra_status,'pra_number'=>$sale->pra_invoice_number,'lines'=>$lines]
            + ($recovered ? ['recovery'=>$recovered['recovery']] : []);
    }
}
