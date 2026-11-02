<?php
namespace App\Http\Controllers;

use App\Models\{Company,PosTransaction,PosTransactionItem,RestaurantOrder,RestaurantOrderItem};
use App\Support\PosReceiptOptions;
use Illuminate\Http\Request;

/** Read-only, synthetic output rendered by the actual print templates. */
final class PosPrintDesignController extends Controller
{
    public function preview(Request $request)
    {
        $data=$request->validate(array_merge(PosReceiptOptions::rules(),[
            'kind'=>'required|in:receipt,kot','stream'=>'required|in:local,pra','paper'=>'nullable|in:58mm,80mm',
        ]));
        $source=Company::findOrFail(app('currentCompanyId'));
        \App\Services\HotelSettingsProfile::assertWrite($source,auth('pos')->user());
        abort_unless(auth('pos')->user() && !auth('pos')->user()->posCashierBlocked(),403);
        $company=clone $source;
        $company->invoice_display_prefs=PosReceiptOptions::merge($company->invoice_display_prefs ?? [],$request);
        $paper=$data['paper'] ?? ($company->receipt_printer_size==='58mm'?'58mm':'80mm');
        app()->setLocale('en');
        $bill=new PosTransaction(['company_id'=>$company->id,'invoice_number'=>'PREVIEW-ONLY','invoice_mode'=>'pra','pra_status'=>$data['stream']==='pra'?'pending':null,'payment_method'=>'cash','subtotal'=>500,'tax_rate'=>16,'tax_amount'=>80,'discount_amount'=>0,'total_amount'=>580,'order_type'=>'takeaway','customer_name'=>'Sample customer']);
        $bill->id=0;$bill->created_at=now();
        $bill->setRelation('company',$company);
        $bill->setRelation('items',collect([new PosTransactionItem(['item_name'=>'Sample full product name with options','item_type'=>'product','quantity'=>2,'unit_price'=>250,'subtotal'=>500,'special_notes'=>'Sample item instruction'])]));
        $bill->setRelation('creator',(object)['name'=>'Sample cashier']);
        $bill->setRelation('terminal',null);$bill->setRelation('rider',null);$bill->setRelation('payments',collect());
        $bill->setRelation('restaurantOrder',null);
        if ($data['kind']==='receipt') {
            $html=view('pos.receipts.receipt_'.$paper,['company'=>$company,'transaction'=>$bill])->render();
        } else {
            $order=new RestaurantOrder(['company_id'=>$company->id,'order_number'=>'PREVIEW-ONLY','order_type'=>'takeaway','customer_name'=>'Sample customer','kot_print_count'=>1]);
            $order->id=0;$order->created_at=now();$order->setRelation('table',null);$order->setRelation('creator',(object)['name'=>'Sample cashier']);
            $item=new RestaurantOrderItem(['item_name'=>'Sample meal with complete long name','item_type'=>'product','quantity'=>2,'special_notes'=>'Sample item instruction']);
            $item->id=1;$order->setRelation('items',collect([$item]));
            $html=view('pos.restaurant.kitchen-ticket',['company'=>$company,'order'=>$order,'ticketItems'=>collect([$item]),'designBill'=>$bill,'grouped'=>collect(['ALL'=>collect([$item])]),'stationLabel'=>null,'delta'=>false,'newItemIds'=>collect()])->render();
        }
        // Sandboxed by the settings UI; no auto_print flag and no print queue writes.
        $html=str_replace('<body>','<body><p style="border:2px solid #000;padding:4px;font-weight:bold;text-align:center">DESIGN PREVIEW — NOT A SALE / TAX INVOICE</p>',$html);
        $html=preg_replace('#<script\b[^>]*>.*?</script>#is','',$html);
        return response($html)->header('Cache-Control','no-store, private')
            ->header('Content-Security-Policy', "sandbox; default-src 'self' data:; script-src 'none'; style-src 'self' 'unsafe-inline'; img-src 'self' data:");
    }
}
