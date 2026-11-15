<?php
namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\Branch;
use App\Services\BranchContextService;
use App\Services\PosAccessService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** Local administrative collections only; never a fiscal or clinical record. */
class FbrPosHospitalController extends Controller
{
    private bool $unassigned = false;
    private function company(bool $allowUnassigned = false): Company
    {
        $this->unassigned = false;
        $u = Auth::guard('fbrpos')->user();
        abort_unless($u && $u->is_active && ($u->role === 'company_admin'
            || in_array($u->pos_role, ['pos_admin','pos_manager','pos_cashier'], true)), 403);
        $c = Company::findOrFail($u->company_id);
        abort_unless($c->isHospitalCollectionPilot(), 404);
        $b = app(BranchContextService::class); $b->useGuard('fbrpos');
        $campuses = $b->accessibleBranches();
        abort_if($this->branch() !== null && $this->branch() < 0, 403);
        if (Schema::hasTable('branches') && Branch::where('company_id', $c->id)->exists()) {
            abort_if($campuses->isEmpty(), 403);
            if (!$campuses->contains('id', $this->branch())) abort_unless($b->setActiveBranch((int)$campuses->first()->id), 403);
        }
        if (request()->boolean('unassigned')) {
            abort_unless($allowUnassigned && $b->isOwner(), 403);
            $this->unassigned = true;
        }
        view()->share('hospitalUnassigned', $this->unassigned);
        view()->share('hospitalIsOwner', $b->isOwner());
        view()->share('hospitalCampuses', $campuses); view()->share('hospitalCampusId', $this->branch());
        return $c;
    }
    private function branch(): ?int { if ($this->unassigned) return null; return app()->bound('currentBranchId') && app('currentBranchId') ? (int)app('currentBranchId') : null; }
    private function campus(Company $c): string { return $this->branch() === null || !Schema::hasTable('branches') ? $c->name : (string)Branch::where('company_id',$c->id)->whereKey($this->branch())->value('name'); }
    private function rows(string $table, Company $c) { return DB::table($table)->where('company_id',$c->id)->where('branch_id',$this->branch()); }
    private function writable(Request $r): void { abort_if((bool)$r->session()->get('impersonation.readonly'),403); }
    private function scope(array $d): void { if ((int)$d['expected_branch_key'] !== ($this->branch() ?? 0)) $this->error('expected_branch_key','campus_changed'); }
    private function error(string $field,string $message): never { throw ValidationException::withMessages([$field=>__('hospital.'.$message)]); }
    private function money(string $s): int { [$w,$f]=array_pad(explode('.',$s,2),2,''); return (int)$w*100+(int)str_pad($f,2,'0'); }
    private function amountRule(): array { return ['required','regex:/^\d{1,8}(?:\.\d{1,2})?$/']; }
    private function closed(Company $c,string $date): bool { return DB::table('hospital_day_closes')->where('company_id',$c->id)->where('branch_key',$this->branch()??0)->where('report_date',$date)->exists(); }
    private function response(Request $r,int $id) { $url='/fbr-pos/hospital/receipts/'.$id.($this->unassigned?'?unassigned=1':''); return $r->expectsJson()?response()->json(['success'=>true,'receipt_id'=>$id,'receipt_url'=>$url]):redirect()->away($url); }
    public function index() {
        $company=$this->company(); $requestKey=(string)Str::uuid(); $closed=$this->closed($company,today()->toDateString());
        return response()->view('fbr-pos.hospital.index',compact('company','requestKey','closed'))->header('Cache-Control','private, no-store');
    }
    public function dashboard() { return $this->index(); }
    public function switchCampus(Request $r) {
        $this->company(); $d=$r->validate(['branch_id'=>'required|integer|min:1']);
        abort_unless(app(BranchContextService::class)->setActiveBranch((int)$d['branch_id']),403);
        return redirect()->away('/fbr-pos/hospital');
    }
    public function store(Request $r) { return $this->collect($r,null); }
    public function payment(Request $r,int $id) { return $this->collect($r,$id); }
    private function collect(Request $r,?int $invoiceId) {
        $company=$this->company($invoiceId !== null); $this->writable($r);
        $rules=['request_key'=>'required|uuid','expected_branch_key'=>'required|integer|min:0',
            'amount'=>$this->amountRule(),'payment_method'=>'required|in:cash,card,bank'];
        if ($invoiceId===null) $rules+=['patient_reference'=>'required|string|max:100',
            'service_head'=>'required|in:consultation,lab,procedure','total_amount'=>$this->amountRule()];
        $d=$r->validate($rules); $this->scope($d); $amount=$this->money($d['amount']);
        if ($amount<=0) $this->error('amount','positive_amount');
        if ($invoiceId===null) {
            $d['patient_reference']=trim($d['patient_reference']); $total=$this->money($d['total_amount']);
            if ($d['patient_reference']==='') $this->error('patient_reference','positive_amount');
            if ($total<=0) $this->error('total_amount','positive_amount');
        } else $total=null;
        $id=DB::transaction(function()use($r,$company,$d,$amount,$total,$invoiceId) {
            Company::whereKey($company->id)->lockForUpdate()->firstOrFail();
            $saved=DB::table('hospital_payments')->where('company_id',$company->id)->where('request_key',$d['request_key'])->first();
            if ($saved) {
                if (($saved->branch_id===null?null:(int)$saved->branch_id)!==$this->branch()
                    || (int)$saved->amount_paisa!==$amount || $saved->payment_method!==$d['payment_method']) $this->error('request_key','retry_conflict');
                $invoice=$this->rows('hospital_invoices',$company)->where('id',$saved->invoice_id)->first();
                abort_unless($invoice,404);
                if ($invoiceId!==null ? (int)$saved->invoice_id!==$invoiceId :
                    ($invoice->request_key!==$d['request_key'] || $invoice->patient_reference!==$d['patient_reference']
                    || $invoice->service_head!==$d['service_head'] || (int)$invoice->total_paisa!==$total)) $this->error('request_key','retry_conflict');
                return (int)$saved->id;
            }
            if (!$r->boolean('payment_confirmed')) $this->error('payment_confirmed','confirm_required');
            $at=now(); if ($this->closed($company,$at->toDateString())) $this->error('amount','closed');
            $access=\App\Services\SubscriptionAccessService::hasAccess($company, $invoiceId !== null);
            abort_unless($access['allowed']??false,403,$access['reason']??'Collection access denied.');
            if ($invoiceId===null) {
                if ($amount>$total) $this->error('amount','overpayment');
                $limit=\App\Services\PlanLimitService::canCreateFbrPosBill($company->id);
                abort_unless($limit['allowed']??false,403,$limit['reason']??'Invoice limit reached.');
                $invoiceId=DB::table('hospital_invoices')->insertGetId(['company_id'=>$company->id,'branch_id'=>$this->branch(),
                    'request_key'=>$d['request_key'],'patient_reference'=>$d['patient_reference'],'service_head'=>$d['service_head'],
                    'total_paisa'=>$total,'created_by'=>Auth::guard('fbrpos')->id(),'campus_name'=>$this->campus($company),'created_at'=>$at,'updated_at'=>$at]);
                $paid=0;
            } else {
                $invoice=$this->rows('hospital_invoices',$company)->where('id',$invoiceId)->first(); abort_unless($invoice,404);
                $total=(int)$invoice->total_paisa;
                $paid=(int)$this->rows('hospital_payments',$company)->where('invoice_id',$invoiceId)->sum('amount_paisa');
                if ($amount>$total-$paid) $this->error('amount','overpayment');
            }
            return DB::table('hospital_payments')->insertGetId(['company_id'=>$company->id,'branch_id'=>$this->branch(),'invoice_id'=>$invoiceId,
                'request_key'=>$d['request_key'],'amount_paisa'=>$amount,'payment_method'=>$d['payment_method'],'balance_paisa'=>$total-$paid-$amount,
                'collected_by'=>Auth::guard('fbrpos')->id(),'cashier_name'=>Auth::guard('fbrpos')->user()->name,'campus_name'=>$this->campus($company),
                'collected_at'=>$at,'created_at'=>$at,'updated_at'=>$at]);
        },3);
        return $this->response($r,$id);
    }
    public function invoice(int $id) {
        $company=$this->company(true); $invoice=$this->rows('hospital_invoices',$company)->where('id',$id)->first(); abort_unless($invoice,404);
        $payments=$this->rows('hospital_payments',$company)->where('invoice_id',$id)->orderBy('id')->get();
        $balance=(int)$invoice->total_paisa-(int)$payments->sum('amount_paisa'); $requestKey=(string)Str::uuid(); $closed=$this->closed($company,today()->toDateString());
        return response()->view('fbr-pos.hospital.invoice',compact('company','invoice','payments','balance','requestKey','closed'))->header('Cache-Control','private, no-store');
    }
    public function receipt(int $id) {
        $company=$this->company(true); $payment=$this->rows('hospital_payments',$company)->where('id',$id)->first(); abort_unless($payment,404);
        $invoice=$this->rows('hospital_invoices',$company)->where('id',$payment->invoice_id)->first(); abort_unless($invoice,404);
        return response()->view('fbr-pos.hospital.receipt',compact('company','invoice','payment'))->header('Cache-Control','private, no-store');
    }
    public function history(Request $r) {
        $company=$this->company(true); $d=$r->validate(['search'=>'nullable|string|max:100']); $q=$this->rows('hospital_invoices',$company);
        if (!empty($d['search'])) $q->where(function($q)use($d){$q->where('patient_reference','like','%'.$d['search'].'%');if(ctype_digit($d['search']))$q->orWhere('id',(int)$d['search']);});
        $invoices=$q->orderByDesc('id')->paginate(20)->withQueryString();
        return response()->view('fbr-pos.hospital.history',compact('company','invoices'))->header('Cache-Control','private, no-store');
    }
    private function totals(Company $c,string $date): array {
        $q=$this->rows('hospital_payments',$c)->whereDate('collected_at',$date);
        return ['receipt_count'=>(clone $q)->count(),'cash_paisa'=>(int)(clone $q)->where('payment_method','cash')->sum('amount_paisa'),
            'card_paisa'=>(int)(clone $q)->where('payment_method','card')->sum('amount_paisa'),'bank_paisa'=>(int)(clone $q)->where('payment_method','bank')->sum('amount_paisa')];
    }
    public function dayClose(Request $r) {
        $company=$this->company(true); abort_unless(PosAccessService::dayCloseAllowed(Auth::guard('fbrpos')->user(),$company),403);
        $d=$r->validate(['date'=>'nullable|date_format:Y-m-d']); $date=$d['date']??today()->toDateString();
        $snapshot=DB::table('hospital_day_closes')->where('company_id',$company->id)->where('branch_key',$this->branch()??0)->where('report_date',$date)->first();
        $totals=$snapshot?(array)$snapshot:$this->totals($company,$date);
        return response()->view('fbr-pos.hospital.close',compact('company','date','snapshot','totals'))->header('Cache-Control','private, no-store');
    }
    public function close(Request $r) {
        $company=$this->company(true); $this->writable($r); abort_unless(PosAccessService::dayCloseAllowed(Auth::guard('fbrpos')->user(),$company),403);
        $d=$r->validate(['date'=>'required|date_format:Y-m-d|before_or_equal:today','confirm'=>'required|accepted','expected_branch_key'=>'required|integer|min:0',
            'expected_cash_paisa'=>'required|integer|min:0','expected_card_paisa'=>'required|integer|min:0','expected_bank_paisa'=>'required|integer|min:0',
            'expected_receipt_count'=>'required|integer|min:0','counted_cash'=>$this->amountRule(),'variance_note'=>'nullable|string|max:500']);
        $this->scope($d); $counted=$this->money($d['counted_cash']);
        $snapshot=DB::transaction(function()use($company,$d,$counted){
            Company::whereKey($company->id)->lockForUpdate()->firstOrFail();
            $q=DB::table('hospital_day_closes')->where('company_id',$company->id)->where('branch_key',$this->branch()??0)->where('report_date',$d['date']);
            if($saved=$q->first()) {if((int)$saved->counted_cash_paisa!==$counted || (string)$saved->variance_note!==trim($d['variance_note']??''))$this->error('counted_cash','close_conflict');return $saved;}
            $totals=$this->totals($company,$d['date']); foreach($totals as $key=>$value) if((int)$d['expected_'.$key]!==$value)$this->error('date','close_changed');
            $variance=$counted-$totals['cash_paisa']; $note=trim($d['variance_note']??''); if($variance!==0 && $note==='')$this->error('variance_note','variance_required');
            $id=DB::table('hospital_day_closes')->insertGetId($totals+['company_id'=>$company->id,'branch_key'=>$this->branch()??0,'report_date'=>$d['date'],
                'counted_cash_paisa'=>$counted,'variance_paisa'=>$variance,'variance_note'=>$note,'closed_by'=>Auth::guard('fbrpos')->id(),
                'cashier_name'=>Auth::guard('fbrpos')->user()->name,'campus_name'=>$this->campus($company),'closed_at'=>now(),'created_at'=>now(),'updated_at'=>now()]);
            return DB::table('hospital_day_closes')->find($id);
        },3);
        return $r->expectsJson()?response()->json(['success'=>true,'report_id'=>$snapshot->id]):redirect()->away('/fbr-pos/hospital/day-close?date='.$d['date'].($this->unassigned?'&unassigned=1':''));
    }
}
