<?php
namespace App\Services;
use App\Models\{Company,Invoice,InvoiceItem,Product};
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\{DB,Schema};
use Illuminate\Validation\ValidationException;

/** Optional DI-only projection. Never reconstruct stock from historical invoices. */
final class DiInvoiceInventory {
 public static function enabled(Company $company): bool {return $company->product_type==='di' && (bool)$company->inventory_enabled;}
 public static function eligibility(Company $company): array {
  return Schema::hasColumn('invoices','inventory_enabled_snapshot') ? ['inventory_enabled_snapshot'=>self::enabled($company)] : [];
 }
 public static function normalize(array $items,Company $company,?int $branchId,string $documentType,?Invoice $editing=null,?string $reference=null): array {
  $enabled=$editing ? $editing->inventory_enabled_snapshot===true : self::enabled($company);
  foreach($items as $index=>&$item) {
   unset($item['inventory_snapshot']);
   if(!$enabled || !Schema::hasColumn('invoice_items','inventory_snapshot'))continue;
   $old=$editing && !empty($item['invoice_item_id']) ? $editing->items()->whereKey($item['invoice_item_id'])->first() : null;
   $selling=$item['selling_snapshot']??null;
   $productId=(int)($selling['product_id']??$item['product_id']??$old?->inventory_snapshot['product_id']??0);
   if(!$productId)continue; // Free-text/services have no inferred inventory identity.
   if(!in_array($documentType,['Sale Invoice','Credit Note'],true))continue; // A monetary debit note does not imply goods movement.
   $product=Product::withoutGlobalScopes()->where('company_id',$company->id)->whereKey($productId)->first();
   if(!$product)self::reject('Selected inventory product does not belong to this company.');
   if($branchId && !DB::table('branches')->where('company_id',$company->id)->where('id',$branchId)->exists())self::reject('Inventory branch does not belong to this company.');
   $qty=self::decimal($selling['base_quantity']??$item['quantity']??0);
   if(!$qty->isPositive() || $qty->isGreaterThan('99999999999999.9999'))self::reject('Inventory quantity must be positive and within supported precision.');
   $sourceId=(int)($selling['source_invoice_item_id']??$item['source_invoice_item_id']??$old?->inventory_snapshot['source_invoice_item_id']??0);
   $source=null;
   if($documentType==='Credit Note') {
    $source=InvoiceItem::whereKey($sourceId)->whereHas('invoice',fn($q)=>$q->withoutGlobalScopes()->where('company_id',$company->id)->where('status','locked')->where('document_type','Sale Invoice')->where(fn($q)=>$q->where('invoice_number',$reference)->orWhere('internal_invoice_number',$reference)->orWhere('fbr_invoice_number',$reference)))->first();
    $snapshot=$source?->inventory_snapshot;
    if(!$snapshot || (int)$snapshot['product_id']!==$productId || !DB::table('di_invoice_inventory_postings')->where('company_id',$company->id)->where('invoice_item_id',$sourceId)->where('direction','sale')->exists())self::reject('Return stock only against an original posted sale line.');
    if((int)($snapshot['branch_id']??0)!==(int)($branchId??0))self::reject('Return must use the original inventory branch.');
    $uom=$snapshot['base_uom'];
    if(app(FbrService::class)->resolveUomForHsCode(null,(string)($item['default_uom']??''))!==app(FbrService::class)->resolveUomForHsCode(null,$uom))self::reject('Return must retain original base UOM.');
   } else {
    $uom=(string)($selling['base_uom']??$item['default_uom']??$product->uom);
    $a=app(FbrService::class)->resolveUomForHsCode(null,$uom);$b=app(FbrService::class)->resolveUomForHsCode(null,$product->uom);
    if($a!==$b || preg_replace('/\D/','',(string)($item['hs_code']??''))!==preg_replace('/\D/','',(string)$product->hs_code))self::reject('Inventory product HS/UOM must match the selected invoice line.');
   }
   $item['inventory_snapshot']=['version'=>1,'company_id'=>(int)$company->id,'product_id'=>$productId,'branch_id'=>$branchId,'base_uom'=>$uom,'base_quantity'=>(string)$qty,'source_invoice_item_id'=>$source?->id];
  }unset($item);return $items;
 }
 /** Reserve original posted quantities across saved credit drafts, matching existing packing-return semantics. */
 public static function validateReturns(array $items,int $companyId,?int $editingId=null): void {
  $groups=collect($items)->filter(fn($i)=>!empty($i['inventory_snapshot']['source_invoice_item_id']))->groupBy('inventory_snapshot.source_invoice_item_id')->sortKeys();
  foreach($groups as $id=>$lines) {
   $source=DB::table('di_invoice_inventory_postings')->where('company_id',$companyId)->where('invoice_item_id',$id)->where('direction','sale')->lockForUpdate()->first();
   if(!$source)self::reject('Original stock deduction is unavailable.');
   $used=InvoiceItem::where('inventory_snapshot->source_invoice_item_id',(int)$id)->whereHas('invoice',fn($q)=>$q->withoutGlobalScopes()->where('company_id',$companyId)->where('document_type','Credit Note')->where(fn($q)=>$q->whereNull('inventory_environment_snapshot')->orWhere('inventory_environment_snapshot','!=','sandbox'))->when($editingId,fn($q)=>$q->where('id','!=',$editingId)))->orderBy('id')->lockForUpdate()->get()->reduce(fn($sum,$item)=>$sum->plus(self::decimal($item->inventory_snapshot['base_quantity'])),BigDecimal::zero());
   foreach($lines as $line)$used=$used->plus(self::decimal($line['inventory_snapshot']['base_quantity']));
   if($used->isGreaterThan($source->base_quantity))self::reject('Return exceeds original stock quantity after earlier saved credit notes.');
  }
 }
 /** Validate known snapshot/stock problems BEFORE any external fiscal dispatch. */
 public static function preflight(Invoice $invoice,?string $environment=null): void {
  if($invoice->inventory_enabled_snapshot!==true)return;
  if(Company::find($invoice->company_id)?->product_type!=='di')self::reject('Frozen inventory projection is limited to Digital Invoicing.');
  if(!$invoice->inventory_environment_snapshot) {
   if(!in_array($environment,['sandbox','production'],true))self::reject('Select the original fiscal environment before inventory finalization.');
   $invoice->inventory_environment_snapshot=$environment;
  }
  if($invoice->inventory_environment_snapshot!=='production' || $environment!=='production')return;
  $items=$invoice->items()->get();
  foreach($items as $item) {
   $s=$item->inventory_snapshot;if(!$s)continue;
   $product=Product::withoutGlobalScopes()->where('company_id',$invoice->company_id)->whereKey($s['product_id'])->first();
   if(!$product || app(FbrService::class)->resolveUomForHsCode(null,$product->uom)!==app(FbrService::class)->resolveUomForHsCode(null,$s['base_uom']))self::reject('Frozen stock unit requires reconciliation before fiscal dispatch.');
   if((int)$s['company_id']!==(int)$invoice->company_id || (int)($s['branch_id']??0)!==(int)($invoice->branch_id??0)
    || !Product::withoutGlobalScopes()->where('company_id',$invoice->company_id)->whereKey($s['product_id'])->exists()
    || !self::decimal($s['base_quantity'])->isEqualTo(self::decimal($item->getRawOriginal('quantity'))))self::reject('Frozen inventory identity requires correction before fiscal submission.');
   if(DB::table('inventory_stocks')->where('company_id',$invoice->company_id)->where('product_id',$s['product_id'])->where('branch_id',$s['branch_id'])->count()>1)self::reject('Duplicate legacy stock rows require reconciliation before submission.');
  }
  self::reserveReturns($invoice);
 }
 /** Durable reservation committed with the fiscal lease, never a database lock held over network. */
 /** Read committed rows after the source mutex, not an earlier REPEATABLE READ snapshot. */
 private static function currentQuantity(\Illuminate\Database\Query\Builder $query): string {
  $total=self::decimal('0');
  foreach($query->orderBy('id')->lockForUpdate()->get(['base_quantity']) as $row)$total=$total->plus(self::decimal($row->base_quantity));
  return (string)$total;
 }
 public static function reserveReturns(Invoice $invoice): void {
  if($invoice->inventory_enabled_snapshot!==true || $invoice->document_type!=='Credit Note')return;
  foreach($invoice->items()->get()->sortBy(fn($i)=>(int)($i->inventory_snapshot['source_invoice_item_id']??0)) as $item) {
   $s=$item->inventory_snapshot;if(!$s)continue;
   $source=DB::table('di_invoice_inventory_postings')->where('company_id',$invoice->company_id)->where('invoice_item_id',$s['source_invoice_item_id']??0)->where('direction','sale')->lockForUpdate()->first();
   if(!$source || (int)$source->product_id!==(int)$s['product_id'] || (int)($source->branch_id??0)!==(int)($s['branch_id']??0))self::reject('Original stock posting scope is unavailable for return reservation.');
   $existing=DB::table('di_inventory_return_reservations')->where('invoice_item_id',$item->id)->lockForUpdate()->first();
   if($existing && in_array($existing->state,['reserved','consumed'],true)) {
    if((int)$existing->source_posting_id!==(int)$source->id || !self::decimal($existing->base_quantity)->isEqualTo(self::decimal($s['base_quantity'])))self::reject('Frozen return reservation no longer matches invoice line.');
    continue;
   }
   $posted=self::currentQuantity(DB::table('di_invoice_inventory_postings')->where('source_posting_id',$source->id));
   $reserved=self::currentQuantity(DB::table('di_inventory_return_reservations')->where('source_posting_id',$source->id)->where('state','reserved'));
   $qty=self::decimal($s['base_quantity']);
   if(self::decimal((string)$posted)->plus(self::decimal((string)$reserved))->plus($qty)->isGreaterThan($source->base_quantity))self::reject('Original returnable stock is already posted or reserved by another invoice.');
   $values=['company_id'=>$invoice->company_id,'invoice_id'=>$invoice->id,'invoice_item_id'=>$item->id,'source_posting_id'=>$source->id,'base_quantity'=>(string)$qty,'state'=>'reserved','updated_at'=>now()];
   if($existing)DB::table('di_inventory_return_reservations')->where('id',$existing->id)->update($values);
   else DB::table('di_inventory_return_reservations')->insert($values+['created_at'=>now()]);
  }
 }
 /** Only an unsubmitted draft or an authoritative rejection can release reservations on edit/delete. */
 public static function releaseForEdit(Invoice $invoice): void {
  if(!Schema::hasTable('di_inventory_return_reservations'))return;
  $rows=DB::table('di_inventory_return_reservations')->where('invoice_id',$invoice->id)->where('company_id',$invoice->company_id)->where('state','reserved')->orderBy('source_posting_id')->get();
  if($rows->isEmpty())return;
  if($invoice->fbr_invoice_number || $invoice->is_fbr_processing || !in_array($invoice->fiscal_submission_state,[DiFiscalSubmissionState::DRAFT,DiFiscalSubmissionState::REJECTED],true))self::reject('Uncertain fiscal return reservation must remain held for reconciliation.');
  foreach($rows as $row){DB::table('di_invoice_inventory_postings')->where('id',$row->source_posting_id)->lockForUpdate()->first();DB::table('di_inventory_return_reservations')->where('id',$row->id)->where('state','reserved')->update(['state'=>'released','updated_at'=>now()]);}
 }
 /** Fiscal acceptance is external and cannot be rolled back. Stock failure holds only the projection. */
 public static function saveAccepted(Invoice $invoice): void {
  DB::transaction(function() use($invoice){
   ProspectiveQuotaGuard::lockCompanyForInvoiceWork((int)$invoice->company_id);
   $locked=Invoice::withoutGlobalScopes()->where('company_id',$invoice->company_id)->whereKey($invoice->id)->lockForUpdate()->firstOrFail();
   $locked->forceFill($invoice->getDirty());
   if($locked->inventory_enabled_snapshot===true && !$locked->integrity_hash)$locked->integrity_hash=IntegrityHashService::generate($locked);
   $locked->save();
   if($locked->status==='locked' && $locked->inventory_enabled_snapshot===true) {
    if($locked->inventory_environment_snapshot==='sandbox' || $locked->fiscal_submission_environment==='sandbox' || $locked->inventory_posting_state==='sandbox_no_stock') {
     $locked->inventory_posting_state='sandbox_no_stock';$locked->inventory_posting_error=null;
    } elseif($locked->inventory_environment_snapshot!=='production' || $locked->fiscal_submission_environment!=='production') {
     $locked->inventory_posting_state='reconciliation_hold';$locked->inventory_posting_error='original_inventory_environment_unverified';
    } else try {
     DB::transaction(function()use($locked){self::reserveReturns($locked);self::post($locked);});
     $locked->inventory_posting_state=$locked->items()->whereNotNull('inventory_snapshot')->exists()?'posted':'not_applicable';
     $locked->inventory_posting_error=null;
    } catch(\Throwable $error) {
     // MySQL deadlocks/lost connections can roll back the ENTIRE parent transaction.
     // Never claim the local accepted-row save survived a destroyed transaction.
     if($error instanceof \Illuminate\Database\DeadlockException || $error instanceof \Illuminate\Database\LostConnectionException
       || (new \Illuminate\Database\ConcurrencyErrorDetector)->causedByConcurrencyError($error)
       || (new \Illuminate\Database\LostConnectionDetector)->causedByLostConnection($error))throw $error;
     // The savepoint rolls back ALL stock/movement writes, not the regulator reference.
     report($error);
     $locked->inventory_posting_state='reconciliation_hold';
     $locked->inventory_posting_error='stock_projection_failed_after_acknowledgement';
    }
    $locked->save();
   }
   $invoice->setRawAttributes($locked->getAttributes(),true);
  });
 }
 /** Explicit local-only repair: never invokes or reserves fiscal submission. */
 public static function retryStock(Invoice $invoice): void {
  DB::transaction(function()use($invoice){
   ProspectiveQuotaGuard::lockCompanyForInvoiceWork((int)$invoice->company_id);
   $locked=Invoice::withoutGlobalScopes()->where('company_id',$invoice->company_id)->whereKey($invoice->id)->lockForUpdate()->firstOrFail();
   if($locked->status!=='locked' || !$locked->fbr_invoice_number || $locked->inventory_enabled_snapshot!==true)self::reject('Only an acknowledged invoice can retry its local stock projection.');
   if($locked->inventory_environment_snapshot!=='production' || $locked->fiscal_submission_environment!=='production' || $locked->inventory_posting_state==='sandbox_no_stock')self::reject('Only the original production invoice can recover physical stock; sandbox or unknown environments cannot be relabelled.');
   if(!IntegrityHashService::verify($locked))self::reject('Invoice integrity must be verified before local stock recovery.');
   self::saveAccepted($locked);$invoice->setRawAttributes($locked->getAttributes(),true);
  });
 }
 private static function post(Invoice $invoice): void {
  $company=Company::findOrFail($invoice->company_id);
  if($company->product_type!=='di')self::reject('Inventory projection belongs to Digital Invoicing only.');
  $postedIds=DB::table('di_invoice_inventory_postings')->where('company_id',$invoice->company_id)->where('invoice_id',$invoice->id)->pluck('invoice_item_id');
  $items=$invoice->items()->whereNotIn('id',$postedIds)->orderBy('id')->get();
  // Lock product rows in stable order, including empty/unassigned stock creation.
  $ids=$items->map(fn($i)=>(int)($i->inventory_snapshot['product_id']??0))->filter()->unique()->sort()->values();
  foreach($ids as $id)Product::withoutGlobalScopes()->where('company_id',$company->id)->whereKey($id)->lockForUpdate()->firstOrFail();
  foreach($items as $item) {
   $s=$item->inventory_snapshot;if(!$s)continue;
   $product=Product::withoutGlobalScopes()->where('company_id',$company->id)->whereKey($s['product_id'])->firstOrFail();
   if(app(FbrService::class)->resolveUomForHsCode(null,$product->uom)!==app(FbrService::class)->resolveUomForHsCode(null,$s['base_uom']))self::reject('Frozen inventory base UOM has changed; reconcile stock without rewriting invoice.');
   if(DB::table('di_invoice_inventory_postings')->where('invoice_item_id',$item->id)->exists())continue;
   if((int)$s['company_id']!==(int)$company->id || (int)($s['branch_id']??0)!==(int)($invoice->branch_id??0))self::reject('Frozen inventory scope mismatch.');
   $qty=self::decimal($s['base_quantity']);if(!$qty->isEqualTo(self::decimal($item->getRawOriginal('quantity'))))self::reject('Frozen base quantity no longer matches invoice line.');if(!$qty->isPositive())self::reject('Frozen inventory quantity is invalid.');$return=$invoice->document_type==='Credit Note';$source=null;
   if($return) {
    $source=DB::table('di_invoice_inventory_postings')->where('company_id',$company->id)->where('invoice_item_id',$s['source_invoice_item_id']??0)->where('direction','sale')->lockForUpdate()->first();
    if(!$source || (int)$source->product_id!==(int)$s['product_id'] || (int)($source->branch_id??0)!==(int)($s['branch_id']??0))self::reject('Original stock deduction is unavailable.');
    $used=self::currentQuantity(DB::table('di_invoice_inventory_postings')->where('source_posting_id',$source->id));
    if(self::decimal((string)$used)->plus($qty)->isGreaterThan($source->base_quantity))self::reject('Return exceeds original posted quantity remaining.');
   } elseif($invoice->document_type!=='Sale Invoice')self::reject('Unsupported inventory document type.');
   $query=DB::table('inventory_stocks')->where('company_id',$company->id)->where('product_id',$s['product_id'])->where('branch_id',$s['branch_id']);
   $rows=$query->lockForUpdate()->get();if($rows->count()>1)self::reject('Duplicate legacy stock rows need reconciliation before posting.');
   $stock=$rows->first();
   if(!$stock){$id=DB::table('inventory_stocks')->insertGetId(['company_id'=>$company->id,'product_id'=>$s['product_id'],'branch_id'=>$s['branch_id'],'quantity'=>'0.0000','min_stock_level'=>0,'avg_purchase_price'=>0,'last_purchase_price'=>0,'created_at'=>now(),'updated_at'=>now()]);$stock=DB::table('inventory_stocks')->find($id);}
   $balance=self::decimal($stock->quantity);$balance=$return?$balance->plus($qty):$balance->minus($qty); // Existing policy permits negative stock.
   if($balance->abs()->isGreaterThan('99999999999999.9999'))self::reject('Stock balance exceeds supported precision.');
   DB::table('inventory_stocks')->where('id',$stock->id)->update(['quantity'=>(string)$balance,'updated_at'=>now()]);
   $knownCost=$source ? $source->unit_cost_snapshot : ((float)$stock->avg_purchase_price>0 ? (string)$stock->avg_purchase_price : null);
   $cost=$knownCost===null?'0':(string)$knownCost; // Generic movement numeric compatibility; posting explicitly preserves unknown cost as NULL.
   $movement=DB::table('inventory_movements')->insertGetId(['company_id'=>$company->id,'product_id'=>$s['product_id'],'branch_id'=>$s['branch_id'],'type'=>$return?'return_in':'sale','quantity'=>(string)$qty,'unit_price'=>$cost,'total_price'=>(string)$qty->multipliedBy($cost)->toScale(2,RoundingMode::HALF_UP),'balance_after'=>(string)$balance,'reference_type'=>'di_invoice_item','reference_id'=>$item->id,'reference_number'=>$invoice->internal_invoice_number??$invoice->invoice_number,'notes'=>'Frozen DI base-unit stock projection','created_at'=>now(),'updated_at'=>now()]);
   DB::table('di_invoice_inventory_postings')->insert(['company_id'=>$company->id,'invoice_id'=>$invoice->id,'invoice_item_id'=>$item->id,'product_id'=>$s['product_id'],'branch_id'=>$s['branch_id'],'direction'=>$return?'return':'sale','base_quantity'=>(string)$qty,'unit_cost_snapshot'=>$knownCost,'source_posting_id'=>$source?->id,'movement_id'=>$movement,'created_at'=>now()]);
   if($return)DB::table('di_inventory_return_reservations')->where('invoice_item_id',$item->id)->where('state','reserved')->update(['state'=>'consumed','updated_at'=>now()]);
  }
 }
 private static function decimal($value): BigDecimal {try{return BigDecimal::of((string)$value)->toScale(4,RoundingMode::UNNECESSARY);}catch(\Throwable){self::reject('Inventory quantity exceeds supported four-decimal precision.');}}
 private static function reject(string $message): never {throw ValidationException::withMessages(['items'=>$message]);}
}
