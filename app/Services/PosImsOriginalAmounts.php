<?php
namespace App\Services;

use App\Models\Company;
use App\Models\PosTransaction;

/** Reuse the original PRA bill's existing, deterministic rounding projection. */
final class PosImsOriginalAmounts
{
    public static function snapshot(Company $company, PosTransaction $sale, array $snapshot): array
    {
        $p=(new PraIntegrationService($company))->generatePayload($sale);
        $items=$p['Items'] ?? [];
        if (count($items)!==count($snapshot['lines']) || !$items
            || abs((float)($p['TotalBillAmount'] ?? -1)-((float)$sale->total_amount-(float)($sale->fbr_service_charge ?? 0)))>0.01) return $snapshot;
        $lines=[];
        foreach($snapshot['lines'] as $i=>$line) {
            $item=$items[$i];
            if (abs((float)$item['Quantity']-(float)$line['quantity'])>0.00001
                || (string)$item['ItemName']!==preg_replace('/[^a-zA-Z0-9\s]/','',$line['name'])
                || abs((float)$item['SaleValue']-(float)$line['base'])>1.0
                || abs((float)$item['TaxCharged']-(float)$line['pra_tax'])>1.0
                || !isset($line['tax_rate']) || abs((float)$item['TaxRate']-(float)$line['tax_rate'])>0.00001) return $snapshot;
            $lines[]=array_replace($line,['base'=>(float)$item['SaleValue'],'pra_tax'=>(float)$item['TaxCharged']]);
        }
        $snapshot['stored_line_facts']=$snapshot['lines'];
        $snapshot['lines']=$lines;
        $snapshot['rounding_source']='original_pra_bill_projection';
        return $snapshot;
    }
}
