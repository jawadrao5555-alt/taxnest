<?php

namespace App\Services;

use App\Models\Company;
use App\Models\FbrPosTransaction;
use App\Models\FbrPosTransactionItem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class HospitalPaymentImsService
{
    private const COLUMNS=['fbr_pos_transaction_id','fbr_status','fbr_invoice_number','fbr_response_code','fbr_error_message','fbr_qr_payload'];

    public static function queueFinalizedReceipt(int $companyId,int $paymentId): array
    {
        if(!Schema::hasTable('hospital_payments')||!Schema::hasColumns('hospital_payments',self::COLUMNS))return ['queued'=>false,'reason'=>'hospital_ims_schema_pending'];
        return DB::transaction(function()use($companyId,$paymentId){
            $company=Company::whereKey($companyId)->lockForUpdate()->firstOrFail();
            abort_unless($company->isHospitalCollectionPilot(),404);
            $payment=DB::table('hospital_payments')->where('company_id',$companyId)->where('id',$paymentId)->lockForUpdate()->firstOrFail();
            $snapshot=self::snapshot($payment->fiscal_route_snapshot??null);
            if(($snapshot['route']??'off')!=='ims'||!($snapshot['eligible']??false))return ['queued'=>false,'reason'=>'local_only'];
            abort_unless((int)$payment->amount_paisa>0&&$payment->collected_at!==null,422);
            $existing=$payment->fbr_pos_transaction_id?FbrPosTransaction::where('company_id',$companyId)->find($payment->fbr_pos_transaction_id):null;
            if($existing){self::copyBack($paymentId,$existing);return ['queued'=>true,'transaction_id'=>$existing->id,'existing'=>true];}
            $invoice=DB::table('hospital_invoices')->where('company_id',$companyId)->where('id',$payment->invoice_id)->firstOrFail();
            [$pct,$rate]=self::fiscalDefaults($companyId,$invoice);
            $gross=round(((int)$payment->amount_paisa)/100,2);
            $subtotal=$rate>0?round($gross/(1+($rate/100)),2):$gross;
            $tax=round($gross-$subtotal,2);
            $method=($payment->payment_method??'cash')==='bank'?'bank_transfer':($payment->payment_method??'cash');
            $mirror=FbrPosTransaction::create(['company_id'=>$companyId,'branch_id'=>$payment->branch_id,
                'invoice_number'=>'HP-'.$paymentId,'invoice_mode'=>'fbr','customer_name'=>null,'customer_phone'=>null,'customer_ntn'=>null,
                'subtotal'=>$subtotal,'discount_amount'=>0,'tax_rate'=>$rate,'tax_amount'=>$tax,'total_amount'=>$gross,
                'payment_method'=>$method,'status'=>'completed','transaction_type'=>'sale','fbr_status'=>'pending',
                'created_by'=>$payment->collected_by,'cash_received'=>$gross,'change_due'=>0]);
            $mirror->forceFill(['created_at'=>$payment->collected_at,'updated_at'=>$payment->collected_at])->save();
            FbrPosTransactionItem::create(['transaction_id'=>$mirror->id,'item_name'=>'Hospital payment','hs_code'=>$pct?:null,
                'uom'=>'Numbers, pieces, units','quantity'=>1,'unit_price'=>$subtotal,'discount'=>0,'item_discount'=>0,
                'tax_rate'=>$rate,'tax_amount'=>$tax,'subtotal'=>$subtotal,'total'=>$gross,'is_tax_exempt'=>$rate<=0]);
            DB::table('hospital_payments')->where('id',$paymentId)->update(['fbr_pos_transaction_id'=>$mirror->id,
                'fbr_status'=>'pending','fbr_error_message'=>null,'updated_at'=>now()]);
            return ['queued'=>true,'transaction_id'=>$mirror->id,'existing'=>false];
        },3);
    }

    public static function reconcileTransaction(FbrPosTransaction $transaction): void
    {
        if(!Schema::hasTable('hospital_payments')||!Schema::hasColumns('hospital_payments',self::COLUMNS))return;
        foreach(DB::table('hospital_payments')->where('company_id',$transaction->company_id)->where('fbr_pos_transaction_id',$transaction->id)->pluck('id') as $id)self::copyBack((int)$id,$transaction);
    }
    public static function reconcileReceipt(int $companyId,int $paymentId): void
    {
        if(!Schema::hasTable('hospital_payments')||!Schema::hasColumns('hospital_payments',self::COLUMNS))return;
        $mirrorId=DB::table('hospital_payments')->where('company_id',$companyId)->where('id',$paymentId)->value('fbr_pos_transaction_id');
        if($mirrorId&&($mirror=FbrPosTransaction::where('company_id',$companyId)->find($mirrorId)))self::copyBack($paymentId,$mirror);
    }
    private static function copyBack(int $paymentId,FbrPosTransaction $mirror): void
    {
        $number=trim((string)($mirror->fbr_invoice_number??''));
        DB::table('hospital_payments')->where('id',$paymentId)->where('company_id',$mirror->company_id)->update([
            'fbr_status'=>$mirror->fbr_status,'fbr_invoice_number'=>$number!==''?$number:null,
            'fbr_response_code'=>$mirror->fbr_response_code,'fbr_error_message'=>$mirror->fbr_error_message,
            'fbr_qr_payload'=>$number!==''?$number:null,'updated_at'=>now()]);
    }
    private static function snapshot(?string $raw): array
    {
        if(!$raw)return[];try{$v=json_decode($raw,true,512,JSON_THROW_ON_ERROR);return is_array($v)?$v:[];}catch(\JsonException){return[];}
    }
    private static function fiscalDefaults(int $companyId,object $invoice): array
    {
        if(!Schema::hasTable('hospital_services')||!Schema::hasColumns('hospital_services',['ims_pct_code','ims_tax_rate']))return[null,0.0];
        $lines=json_decode((string)($invoice->service_lines??'[]'),true);
        $codes=collect(is_array($lines)?$lines:[])->pluck('service_head')->filter()->unique()->values();
        if($codes->isEmpty()&&!empty($invoice->service_head))$codes=collect([$invoice->service_head]);
        $services=DB::table('hospital_services')->where('company_id',$companyId)->whereIn('code',$codes)->get(['ims_pct_code','ims_tax_rate']);
        $pcts=$services->pluck('ims_pct_code')->filter()->unique()->values();
        $rates=$services->pluck('ims_tax_rate')->filter(fn($v)=>$v!==null)->map(fn($v)=>round((float)$v,2))->unique()->values();
        return[$pcts->count()===1?$pcts->first():null,$rates->count()===1?(float)$rates->first():0.0];
    }
}
