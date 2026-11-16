<?php
namespace App\Support;
/** Presentation only: no query, credential, permission, or provider access. */
final class DiReadPresentation
{
    public static function rows(array $evidence): array
    {
        $counts=['completed_documents','sales_documents','return_documents','tax_unknown_documents'];
        $money=['sales_total','return_credit_note_total','net_billing','net_tax'];
        $rows=[];
        foreach (($evidence['values'] ?? []) as $key=>$value) {
            $tax=preg_match('/^(taxable|exempt|zero_rate|unknown)_(item_rows|net_item_tax|tax_unknown_rows)$/D',(string)$key,$m);
            if (!in_array($key,$counts,true) && !in_array($key,$money,true) && !$tax) continue;
            $isMoney=in_array($key,$money,true)||($tax && $m[2]==='net_item_tax');
            $rows[]=['key'=>$key,'classification'=>$tax?$m[1]:null,'label_key'=>$tax?$m[2]:$key,
                'value'=>$value===null||!is_numeric($value)?null:number_format((float)$value,$isMoney?2:0), 'money'=>$isMoney];
        }
        return $rows;
    }
}
