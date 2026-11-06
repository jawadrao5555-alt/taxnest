<?php
namespace App\Services;

use App\Models\PosTransaction;
use Illuminate\Support\Facades\Schema;

/** Copy complete stored money facts, never infer them from today's tax rules. */
final class PosStoredFiscalRecovery
{
    public static function recover(PosTransaction $sale): ?array
    {
        if ($sale->pra_status!=='submitted' || !trim((string)$sale->pra_invoice_number)
            || ($sale->transaction_type ?? 'sale')!=='sale'
            || (float)$sale->discount_amount!==0.0 || $sale->hotel_money_from_folio
            || !Schema::hasColumn('pos_transactions','tax_inclusive')
            || $sale->tax_inclusive===null || (float)($sale->tax_menu_rate ?? 0)>0) return null;
        $lines=[]; $evidence=[]; $sumMenu=0; $sumTax=0;
        foreach ($sale->items()->orderBy('id')->get() as $item) {
            if ($item->item_type==='deal' || $item->deal_snapshot || $item->is_tax_exempt===null) return null;
            foreach (['subtotal','tax_amount','tax_rate','quantity'] as $key) {
                if ($item->$key===null || !is_numeric($item->$key) || !is_finite((float)$item->$key) || (float)$item->$key<0) return null;
            }
            if ((float)$item->quantity<=0 || (float)$item->tax_rate>100) return null;
            $net=(float)$item->subtotal; $tax=(float)$item->tax_amount;
            $base=round($net-($sale->tax_inclusive ? $tax : 0),2);
            if ($base<0 || ($item->is_tax_exempt && $tax!=0)
                || abs($tax-round($base*(float)$item->tax_rate/100,2))>0.01) return null;
            $lines[]=['name'=>$item->item_name,'quantity'=>(float)$item->quantity,'base'=>$base,
                'pra_tax'=>$tax,'tax_rate'=>(float)$item->tax_rate];
            $evidence[]=['id'=>$item->id,'subtotal'=>$net,'tax'=>$tax,'rate'=>(float)$item->tax_rate,
                'quantity'=>(float)$item->quantity,'exempt'=>(bool)$item->is_tax_exempt];
            $sumMenu+=$net; $sumTax+=$tax;
        }
        if (!$lines) return null;
        $fee=(float)($sale->fbr_service_charge ?? 0);
        $expectedSubtotal=$sale->tax_inclusive ? (float)$sale->subtotal+(float)$sale->tax_amount : (float)$sale->subtotal;
        if (abs($sumMenu-$expectedSubtotal)>($sale->tax_inclusive ? 1.0 : 0.01)
            || abs($sumTax-(float)$sale->tax_amount)>1.0
            || abs(round($sumMenu+($sale->tax_inclusive ? 0 : $sumTax))+$fee-(float)$sale->total_amount)>0.001) return null;
        return ['lines'=>$lines,'recovery'=>['source'=>'stored_original_bill','version'=>1,
            'pra_reference'=>(string)$sale->pra_invoice_number,
            'evidence_sha256'=>hash('sha256',json_encode([$sale->company_id,$sale->id,$sale->subtotal,
                $sale->tax_amount,$sale->total_amount,$sale->tax_inclusive,$fee,$evidence],JSON_THROW_ON_ERROR))]];
    }
}
