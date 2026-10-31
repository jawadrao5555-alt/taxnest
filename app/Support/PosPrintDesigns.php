<?php
namespace App\Support;

use App\Models\{Company, PosTransaction};

/** Optional presentation only; no routing, money, serial or stored-layout migration. */
final class PosPrintDesigns
{
    public const RECEIPTS = ['inherit'=>'Existing / current','classic'=>'Classic','compact'=>'Compact','bold_total'=>'Bold Total','clean'=>'Clean','large_text'=>'Large Text','grid'=>'Grid','minimal_ink'=>'Minimal Ink','boxed'=>'Boxed'];
    public const KOTS = ['inherit'=>'Existing / current','simple'=>'Simple','bold_items'=>'Bold Items','space_saver'=>'Space Saver','order_first'=>'Order First','table_first'=>'Table First','quantity_first'=>'Quantity First','checklist'=>'Checklist','large_text'=>'Large Text'];

    public static function receiptCss(array $options, string $paper): string
    {
        $design=$options['design'] ?? 'inherit';
        if (!isset(self::RECEIPTS[$design]) || $design==='inherit') return '';
        $small=$paper==='58mm';
        $css='/* b03-receipt-design:'.$design.' */body,body *{color:#000 !important;background-color:#fff !important;text-shadow:none !important;}.items-table td,.header h1{overflow-wrap:anywhere;}';
        return $css.match ($design) {
            'classic'=>'.header{border-bottom:3px double #000;padding-bottom:4px;}.items-table th{border-bottom:1px solid #000;}.grand-total td{border-top:3px double #000;}',
            'compact'=>'.header{margin-bottom:3px;}.items-table td,.info-table td{padding-top:1px;padding-bottom:1px;}.separator{margin:3px 0;}',
            'bold_total'=>'.grand-total td{border-top:3px solid #000;border-bottom:3px solid #000;font-size:'.($small?18:22).'px;padding:6px 2px;}',
            'clean'=>'.header{border-bottom:1px solid #000;padding-bottom:5px;}.items-table td{padding-top:4px;padding-bottom:4px;}.info-table td{padding-top:2px;}',
            'large_text'=>'.items-table td{font-size:'.($small?13:16).'px;line-height:1.35;}.grand-total td{font-size:'.($small?18:22).'px;}',
            'grid'=>'.items-table,.items-table td,.items-table th{border:1px solid #000;}.items-table td,.items-table th{padding:3px 2px;}',
            'minimal_ink'=>'.items-table td,.items-table th{border:0;}.header{border:0;}.totals-table .grand-total td{border-bottom:0;border-top:1px solid #000;}.separator{border-top:1px solid #000;}',
            'boxed'=>'.header,.totals-table{border:1px solid #000;}.header{padding:4px;}.items-table{border-left:1px solid #000;border-right:1px solid #000;}',
            default=>'',
        };
    }

    public static function kotOptions(Company $company, $order, ?PosTransaction $bill=null): array
    {
        if (!$bill && !empty($order->pos_transaction_id)) {
            $bill=PosTransaction::withoutGlobalScope('hide_archived')->where('company_id',$company->id)->find($order->pos_transaction_id);
        }
        if ($bill && (int) $bill->company_id !== (int) $company->id) $bill=null;
        return $bill ? PosReceiptOptions::forBill($company,$bill) : PosReceiptOptions::saved($company,'local');
    }

    public static function kotCss(array $options): string
    {
        $design=$options['kot_design'] ?? 'inherit';
        if (!isset(self::KOTS[$design]) || $design==='inherit') return '';
        $css='/* b03-kot-design:'.$design.' */body,body *{color:#000 !important;background-color:#fff !important;text-shadow:none !important;}.b03-kot-header{display:flex;flex-direction:column;}.items-table td{overflow-wrap:anywhere;}.priority-badge{border:2px solid #000;}';
        return $css.match ($design) {
            'simple'=>'.items-table td{padding:3px 1px;}.b03-kot-header{border-bottom:1px solid #000;padding-bottom:3px;}',
            'bold_items'=>'.items-table .name{font-weight:900;font-size:18px;}.items-table .qty{font-size:21px;}',
            'space_saver'=>'.items-table td{padding-top:1px;padding-bottom:1px;}.b03-kot-header p{margin-top:1px;margin-bottom:1px;}.separator{margin:3px 0;}',
            'order_first'=>'.b03-kot-header>.text-lg{order:-1;font-size:23px;border-bottom:2px solid #000;}',
            'table_first'=>'.b03-kot-header>.kot-table-line{order:-2;font-size:23px !important;border:2px solid #000;padding:3px;white-space:normal;overflow-wrap:anywhere;}',
            'quantity_first'=>'.items-table{direction:rtl;}.items-table td{direction:ltr;}.items-table .qty{text-align:left;padding-left:3px;}',
            'checklist'=>'.items-table .name:before{content:"\\2610  ";font-size:19px;}.items-table .qty{font-size:20px;}',
            'large_text'=>'.items-table .name{font-size:19px;line-height:1.35;}.items-table .qty{font-size:23px;}',
            default=>'',
        };
    }
}
