<?php

namespace App\Services\Reporting;

use App\Models\Company;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/** SELECT-only local ledgers. Never mix invoice principal, received money and frozen cash. */
final class LocalCollectionReports
{
    private array $availableSources = [];

    public function read(Company $company, array $args = []): array
    {
        return DB::transaction(fn () => $this->snapshot($company, $args));
    }

    private function snapshot(Company $company, array $args): array
    {
        abort_unless($company->isLocalCollectionPilot(), 422);
        $v = validator($args, [
            'date_from'=>'nullable|date_format:Y-m-d', 'date_to'=>'nullable|date_format:Y-m-d|after_or_equal:date_from',
            'date_basis'=>'nullable|in:calendar', 'branch_id'=>'nullable|integer|min:0',
        ])->validate();
        foreach (['terminal_id','payment_method','stream'] as $unsupported) {
            if (isset($args[$unsupported])) throw ValidationException::withMessages([$unsupported=>'This local ledger summary cannot apply that filter. Use company, campus and calendar dates.']);
        }
        $v['date_to'] ??= now()->toDateString();
        if (isset($v['date_from']) && $v['date_from'] > $v['date_to']) throw ValidationException::withMessages(['date_from'=>'Start date is after the reporting end date.']);
        $school = $company->isSchoolFeePilot();
        $tables = $school ? ['school_fee_accounts','school_fee_receipts','school_fee_day_closes'] : ['hospital_invoices','hospital_payments','hospital_day_closes'];
        [$accounts,$payments,$closes] = $tables;
        $principal = $school ? 'payable_paisa' : 'total_paisa';
        $foreign = $school ? 'account_id' : 'invoice_id';
        $cashColumn = $school ? 'cash_amount_paisa' : 'cash_paisa';
        $out = [
            'company'=>['id'=>$company->id,'name'=>$company->name,'product'=>'fbrpos','category'=>$school?'school':'hospital'],
            'period'=>['from'=>$v['date_from']??null,'to'=>$v['date_to']??null,'basis'=>'stored_calendar_date','timezone'=>config('app.timezone')],
            'filters'=>array_intersect_key($v,array_flip(['branch_id'])), 'as_of'=>now()->toIso8601String(),
            'currency'=>'PKR','money_unit'=>'paisa','authority'=>'LOCAL_ONLY_FISCAL_BLOCKED',
            'coverage'=>['status'=>'UNAVAILABLE','complete'=>false,'retention_verified'=>false,'physical_cash_verified'=>false],
            'billing'=>null,'payments'=>null,'balances'=>null,'closings'=>null,
            'notes'=>[
                'Invoice principal is billed value, not money collected. Payments are individual immutable receipts; never add these to principal or closing cash.',
                'Billed and payment totals use their own stored calendar dates. Outstanding is all principal created by the range end less payments received by that end, not only invoices created within the range.',
                'Closing cash is the saved snapshot, not rebuilt from current receipts. Missing close rows do not prove an open day or zero cash.',
                'Legacy School receipts without an account are included once as fully paid local cash; no old outstanding balance is invented.',
                'No local refund/reversal event ledger or physical-payment evidence is registered. Refund settlement and historical completeness remain unverified; no zero-refund or healthy claim is made.',
            ],
        ];
        $required = [
            $accounts=>['id','company_id','branch_id','created_at',$principal],
            $payments=>['id','company_id','branch_id',$foreign,'amount_paisa','payment_method','collected_at'],
            $closes=>['id','company_id','branch_key','report_date','receipt_count',$cashColumn,'counted_cash_paisa','variance_paisa','closed_at'],
        ];
        foreach ($required as $table=>$columns) {
            if (!($this->availableSources[$table] ??= Schema::hasTable($table) && Schema::hasColumns($table,$columns))) {
                $out['coverage']['reason']='Required local ledger source/columns unavailable: '.$table;
                return $out;
            }
        }
        if (isset($v['branch_id']) && $v['branch_id'] > 0 && (!Schema::hasTable('branches') || !DB::table('branches')->where('company_id',$company->id)->where('id',$v['branch_id'])->exists())) {
            throw ValidationException::withMessages(['branch_id'=>'Campus is not part of this company.']);
        }
        $scope = function(string $table, string $branch='branch_id')use($company,$v) {
            $q=DB::table($table)->where('company_id',$company->id);
            if (isset($v['branch_id'])) $q->where($branch,$branch==='branch_key'?(int)$v['branch_id']:($v['branch_id']?:null));
            return $q;
        };
        $range = function($q,string $date,bool $asOf=false)use($v) {
            if (!$asOf && isset($v['date_from'])) $q->whereDate($date,'>=',$v['date_from']);
            if (isset($v['date_to'])) $q->whereDate($date,'<=',$v['date_to']);
            return $q;
        };
        $bills=$range($scope($accounts),'created_at');
        $received=$range($scope($payments),'collected_at');
        $legacy=$school?$range($scope($payments)->whereNull($foreign),'collected_at'):null;
        $out['billing']=['documents'=>(clone $bills)->count()+($legacy?(clone $legacy)->count():0),
            'principal_paisa'=>(int)(clone $bills)->sum($principal)+($legacy?(int)(clone $legacy)->sum('amount_paisa'):0),
            'legacy_fully_paid_documents'=>$legacy?(clone $legacy)->count():0];
        $out['payments']=['receipt_count'=>(clone $received)->count(),'collected_paisa'=>(int)(clone $received)->sum('amount_paisa'),'methods'=>[]];
        foreach ((clone $received)->selectRaw('payment_method, COUNT(*) as receipts, SUM(amount_paisa) as amount')->groupBy('payment_method')->get() as $method) {
            $out['payments']['methods'][$method->payment_method]=['receipt_count'=>(int)$method->receipts,'collected_paisa'=>(int)$method->amount];
        }
        // Aggregate per account before summing: joining principal directly to each payment would multiply it.
        $asOfBills=$range($scope($accounts),'created_at',true);
        $asOfPayments=$range($scope($payments),'collected_at',true);
        $sumPayments=(clone $asOfPayments)->whereNotNull($foreign)->select($foreign,'company_id','branch_id')->selectRaw('SUM(amount_paisa) as paid')->groupBy($foreign,'company_id','branch_id');
        $balances=DB::query()->fromSub($asOfBills,'a')->leftJoinSub($sumPayments,'p',function($j)use($foreign) {
            $j->on('a.id','=','p.'.$foreign)->on('a.company_id','=','p.company_id')->whereRaw('(a.branch_id = p.branch_id OR (a.branch_id IS NULL AND p.branch_id IS NULL))');
        });
        $balance=$balances->selectRaw('COALESCE(SUM(a.'.$principal.' - COALESCE(p.paid,0)),0) as outstanding, SUM(CASE WHEN a.'.$principal.' = COALESCE(p.paid,0) THEN 1 ELSE 0 END) as settled, SUM(CASE WHEN a.'.$principal.' > COALESCE(p.paid,0) THEN 1 ELSE 0 END) as unpaid, SUM(CASE WHEN a.'.$principal.' < COALESCE(p.paid,0) THEN 1 ELSE 0 END) as overpaid')->first();
        $orphanPayments=(clone $asOfPayments)->whereNotNull($foreign)->whereNotExists(function($q)use($accounts,$payments,$foreign){$q->selectRaw('1')->from($accounts.' as a')->whereColumn('a.id',$payments.'.'.$foreign)->whereColumn('a.company_id',$payments.'.company_id')->whereRaw('(a.branch_id = '.$payments.'.branch_id OR (a.branch_id IS NULL AND '.$payments.'.branch_id IS NULL))');})->count();
        $out['balances']=['as_of_date'=>$v['date_to']??now()->toDateString(),'outstanding_paisa'=>$orphanPayments?null:(int)$balance->outstanding,'settled_accounts'=>(int)$balance->settled,'outstanding_accounts'=>(int)$balance->unpaid,'overpaid_accounts'=>(int)$balance->overpaid,'unmatched_payment_receipts'=>$orphanPayments];
        $closing=$range($scope($closes,'branch_key'),'report_date');
        $count=(clone $closing)->count();
        $out['closings']=['status'=>$count?'RECORDED_SNAPSHOTS':'NO_RECORDED_CLOSE','snapshot_count'=>$count,
            'cash_paisa'=>$count?(int)(clone $closing)->sum($cashColumn):null,
            'counted_cash_paisa'=>$count && !(clone $closing)->whereNull('counted_cash_paisa')->exists()?(int)(clone $closing)->sum('counted_cash_paisa'):null,
            'variance_paisa'=>$count && !(clone $closing)->whereNull('variance_paisa')->exists()?(int)(clone $closing)->sum('variance_paisa'):null,
            'latest_closed_at'=>(clone $closing)->max('closed_at')];
        $out['coverage']['status']='PARTIAL';
        $out['coverage']['reason']=$orphanPayments?'Some payments have no matching principal in this company/campus. Outstanding is unknown; do not treat this as a healthy reconciled balance.':'Live retained ledger SELECT; refund events, physical cash and complete historical retention are not established.';
        return $out;
    }
}
