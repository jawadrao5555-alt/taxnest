<?php
declare(strict_types=1);

/** Called only by the guarded native inventory probe after real synthetic posting. */
function proveDiRetainedStockDiagnostics(\App\Models\Company $company, \App\Models\Invoice $invoice): void
{
    if (getenv('APP_ENV') !== 'rc-mariadb' || !getenv('LD_PRELOAD')
        || !preg_match('/^taxnest_rc_[a-z0-9_]+_full$/D', (string)getenv('DB_DATABASE'))) {
        throw new RuntimeException('Unsafe retained stock diagnostics target');
    }
    $db=\Illuminate\Support\Facades\DB::class;
    $assert=static function(bool $ok,string $why):void { if (!$ok) throw new RuntimeException($why); };
    $bill=static fn()=>app(\App\Services\Reporting\DiagnosticBillEvidence::class)->read(
        ['company_name'=>$company->name,'company_id'=>$company->id,'bill_id'=>$invoice->id],[$company->id]);
    $posting=$db::table('di_invoice_inventory_postings')->where('company_id',$company->id)->where('invoice_id',$invoice->id)->sole();
    $day=\Illuminate\Support\Carbon::parse($posting->created_at,config('app.timezone'))->setTimezone(config('reporting_connector.timezone','Asia/Karachi'))->toDateString();
    $source=static fn(array $extra=[])=>app(\App\Services\Reporting\DiagnosticSourceEvidence::class)->read($extra+
        ['company_name'=>$company->name,'company_id'=>$company->id,'source'=>'di.stock_postings','date_from'=>$day],[$company->id]);
    $before=[];
    foreach(['invoices','invoice_items','di_invoice_inventory_postings','inventory_movements','inventory_stocks'] as $table) {
        $before[$table]=$db::table($table)->orderBy('id')->get()->toJson();
    }
    $r=$bill();
    $assert($r['sources']['stock']['status']==='PARTIAL','Native retained stock not linked');
    $assert($r['sources']['stock']['rows']===[['id'=>(int)$posting->id,'movement_id'=>(int)$posting->movement_id]],'Native retained stock identity differs');
    $assert(!in_array('stock',$r['unimplemented_links'],true),'Native stock still marked unimplemented');
    $r=$source();$assert($r['status']==='PARTIAL'&&in_array((int)$posting->id,array_column($r['rows'],'id'),true),'Native source omitted actual posting');
    $page=$source(['limit'=>1]);$assert(count($page['rows'])===1,'Native source limit ignored');
    if($page['pagination']['has_more']) {
        $next=$source(['limit'=>1,'after_id'=>$page['pagination']['next_after_id']]);
        $assert(count($next['rows'])===1&&$next['rows'][0]['id']>$page['rows'][0]['id'],'Native source cursor replayed row');
    }
    $assert($r['coverage']['complete']===false,'Native source claimed complete evidence');
    $level=$db::transactionLevel();$db::beginTransaction();
    try {
        // Synthetic tampering never commits and never invokes fiscal dispatch.
        $other=\App\Models\Company::create(['name'=>'Synthetic diagnostic foreign','ntn'=>bin2hex(random_bytes(6)),
            'product_type'=>'di','status'=>'approved','company_status'=>'active']);
        $db::table('inventory_movements')->where('id',$posting->movement_id)->update(['company_id'=>$other->id]);
        $bad=$bill()['sources']['stock'];
        $assert($bad['status']==='INCONSISTENT_RETAINED_LINKS'&&$bad['rows']===[],'Native forged movement leaked or looked healthy');
        $bad=$source();$assert($bad['status']==='INCONSISTENT_RETAINED_LINKS'&&$bad['rows']===[]&&$bad['observed']===null,'Native forged source exported observations');
        $db::table('inventory_movements')->where('id',$posting->movement_id)->update(['company_id'=>$company->id]);
        $foreignProduct=\App\Models\Product::withoutGlobalScopes()->create(['company_id'=>$other->id,'name'=>'Synthetic foreign retained identity','hs_code'=>'33049900','uom'=>'KG','is_active'=>true]);
        $db::table('di_invoice_inventory_postings')->where('id',$posting->id)->update(['product_id'=>$foreignProduct->id]);
        $db::table('inventory_movements')->where('id',$posting->movement_id)->update(['product_id'=>$foreignProduct->id]);
        $assert($bill()['sources']['stock']['status']==='INCONSISTENT_RETAINED_LINKS','Native matched forgery bypassed frozen item product');
        $db::table('di_invoice_inventory_postings')->where('id',$posting->id)->update(['product_id'=>$posting->product_id]);
        $db::table('inventory_movements')->where('id',$posting->movement_id)->update(['product_id'=>$posting->product_id]);
        $branch=\App\Models\Branch::create(['company_id'=>$company->id,'name'=>'Synthetic diagnostic mismatch','is_active'=>true]);
        $db::table('invoices')->where('id',$invoice->id)->update(['branch_id'=>$branch->id]);
        $assert($bill()['sources']['stock']['status']==='INCONSISTENT_RETAINED_LINKS','Native invoice branch drift concealed');
        $db::table('invoices')->where('id',$invoice->id)->update(['branch_id'=>$posting->branch_id]);
        $db::table('inventory_movements')->where('id',$posting->movement_id)->update(['branch_id'=>$branch->id]);
        $assert($bill()['sources']['stock']['status']==='INCONSISTENT_RETAINED_LINKS','Native movement branch drift concealed');
    } finally {
        while($db::transactionLevel()>$level) $db::rollBack();
    }
    foreach($before as $table=>$bytes) $assert($bytes===$db::table($table)->orderBy('id')->get()->toJson(),'Native diagnostic probe changed retained '.$table);
    $assert($bill()['sources']['stock']['status']==='PARTIAL','Native rollback did not restore diagnostics');
    echo "PASS: native DI retained stock diagnostic links exact scoped pagination forged tenant and branch rejected rollback preserved\n";
}
