<?php
namespace App\Http\Controllers;
use App\Models\{Invoice,User};
use Illuminate\Support\Facades\DB;
use App\Services\{DiInvoiceInventory,AuditLogService};
use Illuminate\Http\Request;
final class InvoiceInventoryController extends Controller {
 public function retry(Request $request,Invoice $invoice) {
  abort_if($request->session()->get('impersonation.readonly',false),403);
  DB::transaction(function()use($request,$invoice){
   $sessionActor=$request->user();
   $actor=$sessionActor instanceof User ? User::whereKey($sessionActor->id)->lockForUpdate()->first() : null;
   // These are existing web User roles, not the separate SaaS AdminUser guard.
   abort_unless($actor && $actor->is_active && in_array($actor->role,['company_admin','super_admin'],true) && (int)$actor->company_id===(int)$invoice->company_id,403);
   DiInvoiceInventory::retryStock($invoice);
   AuditLogService::log('di_inventory_projection_retry','Invoice',$invoice->id,null,['result'=>$invoice->inventory_posting_state]);
  });
  return back()->with($invoice->inventory_posting_state==='reconciliation_hold'?'error':'success',$invoice->inventory_posting_state==='reconciliation_hold'?'Stock still requires reconciliation. Fiscal acceptance is preserved; no fiscal request was sent.':'Local stock projection verified. No fiscal request was sent.');
 }
}
