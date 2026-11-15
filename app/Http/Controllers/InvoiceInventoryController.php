<?php
namespace App\Http\Controllers;
use App\Models\Invoice;
use App\Services\{DiInvoiceInventory,AuditLogService};
use Illuminate\Http\Request;
final class InvoiceInventoryController extends Controller {
 public function retry(Request $request,Invoice $invoice) {
  abort_unless(in_array($request->user()?->role,['company_admin','super_admin'],true) && (int)$request->user()->company_id===(int)$invoice->company_id,403);
  abort_if($request->session()->get('impersonation.readonly',false),403);
  DiInvoiceInventory::retryStock($invoice);
  AuditLogService::log('di_inventory_projection_retry','Invoice',$invoice->id,null,['result'=>$invoice->inventory_posting_state]);
  return back()->with($invoice->inventory_posting_state==='reconciliation_hold'?'error':'success',$invoice->inventory_posting_state==='reconciliation_hold'?'Stock still requires reconciliation. Fiscal acceptance is preserved; no fiscal request was sent.':'Local stock projection verified. No fiscal request was sent.');
 }
}
