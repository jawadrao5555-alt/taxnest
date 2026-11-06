<?php
namespace App\Services;
use App\Models\{PosFederalOutbox, PosImsSubmission, PosDiSandboxSubmission};

/** Reviewed mapping or explicit original-bill disclosure; never infers an exemption. */
class PosFederalPayload
{
    public function build(PosFederalOutbox $row, $record, $service): array
    {
        $m=$row->rule_snapshot['mapping'];
        $p=$service->template($record);
        $parent=null;
        if (($record->snapshot['transaction_type'] ?? 'sale')==='return') {
            $model=$row->channel==='ims' ? PosImsSubmission::class : PosDiSandboxSubmission::class;
            $parent=$model::where('company_id',$row->company_id)->where('source_transaction_id',$record->snapshot['parent_transaction_id'])
                ->where('environment',$row->environment)->whereIn('state',$row->channel==='ims' ? ['accepted','local_accepted'] : ['accepted'])->first();
            abort_unless($parent,409,'Original federal acknowledgement is required before reporting its return.');
        }
        if ($row->channel==='ims') {
            $p['RefUSIN']=$parent?->fiscal_number;
            foreach ($p['Items'] as $i=>&$line) {
                // Match standalone FBR POS's existing no-catalogue-code transport
                // default. This is not an exemption or a tax classification.
                $line['PCTCode']=empty($m['hs_code']) ? '00000000' : str_replace('.','',$m['hs_code']);
                if (($m['tax_source'] ?? null)==='original_bill') {
                    $original=$record->snapshot['lines'][$i];
                    abort_unless(isset($original['tax_rate']) && is_numeric($original['tax_rate']),422,'Original bill tax rate snapshot is missing; review the bill in the IMS workbench.');
                    $line['TaxRate']=(float)$original['tax_rate'];
                    $line['TaxCharged']=(float)$original['pra_tax'];
                } else {
                    $line['TaxRate']=(float)$m['tax_rate'];
                    $line['TaxCharged']=round($line['SaleValue']*$line['TaxRate']/100,2);
                }
                $line['TotalAmount']=round($line['SaleValue']+$line['TaxCharged'],2);
                $line['RefUSIN']=$p['RefUSIN'];
            }
            unset($line);
            $p['TotalTaxCharged']=round(array_sum(array_column($p['Items'],'TaxCharged')),2);
            $p['TotalBillAmount']=round($p['TotalSaleValue']+$p['TotalTaxCharged'],2);
            if (($m['tax_source'] ?? null)==='original_bill') {
                abort_unless(abs($p['TotalBillAmount']-((float)$record->snapshot['total']-(float)($record->snapshot['fbr_service_charge'] ?? 0)))<0.01,
                    422,'Original bill totals require review; automatic reporting will not change customer money.');
            }
        } else {
            $name=strtolower(trim((string)($record->snapshot['customer_name'] ?? '')));
            abort_unless(empty($record->snapshot['customer_id']) && in_array($name,['','walk-in customer','walk-in','cash customer'],true),422,'Review the actual buyer identity in the federal workbench.');
            $p['buyerBusinessName']='Walk-in customer';
            $p['buyerProvince']=$m['buyer_province']; $p['buyerAddress']=$m['buyer_address'];
            $p['invoiceRefNo']=$parent?->fiscalReference() ?? '';
            if ($row->environment==='sandbox') $p['scenarioId']=$m['scenario_id'];
            $originalTax = ($m['tax_source'] ?? null) === 'original_bill';
            foreach ($p['items'] as $index => &$line) {
                $line['hsCode']=$m['hs_code']; $line['uoM']=$m['uom'];
                $line['saleType']=$m['sale_type'];
                $original = $record->snapshot['lines'][$index];
                abort_if($originalTax && (!is_numeric($original['tax_rate'] ?? null) || !is_numeric($original['pra_tax'] ?? null)),
                    422, 'Original item tax and rate are required for automatic DI.');
                $line['rate'] = ($originalTax ? $original['tax_rate'] : $m['tax_rate']).'%';
                $line['salesTaxApplicable'] = $originalTax ? round((float) $original['pra_tax'], 2)
                    : round($line['valueSalesExcludingST'] * (float) $m['tax_rate'] / 100, 2);
                $line['totalValues']=round($line['valueSalesExcludingST']+$line['salesTaxApplicable'],2);
                $line['sroScheduleNo']=$m['sro_schedule'] ?? ''; $line['sroItemSerialNo']=$m['sro_item'] ?? '';
            }
            unset($line);
            if ($originalTax) {
                $snapshot = $record->snapshot;
                $total = (float) $snapshot['total'] - (float) ($snapshot['fbr_service_charge'] ?? 0);
                abort_unless(abs(array_sum(array_column($p['items'], 'totalValues')) - $total) < 0.01,
                    422, 'Original bill totals require review before automatic DI.');
            }
        }
        return $p;
    }
}
