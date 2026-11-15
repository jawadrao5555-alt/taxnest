<?php
namespace App\Services;

use App\Models\{Company,User};
use App\Services\Reporting\{CompanyReadReports,LocalCollectionReports};
use Illuminate\Support\Facades\{DB,Schema};

/** Exact, SELECT-only intents. No model-provided SQL, dates, identifiers or row text. */
final class MadadgarCompanyReadTools
{
    private const INTENTS=[
        'today billing'=>'billing','aaj ki sales'=>'billing','today sales'=>'billing',
        'today collections'=>'payments','today payments'=>'payments','aaj ki collections'=>'payments',
        'today tax breakdown'=>'tax','aaj ka tax breakdown'=>'tax',
        'stock summary'=>'stock','inventory summary'=>'stock',
        'recipe cost and servings'=>'recipe_capacity','recipe summary'=>'recipes','today day close'=>'closing','aaj ka day close'=>'closing',
    ];

    public static function answer(string $question,string $product,?Company $company,?User $actor):?array
    {
        $intent=self::INTENTS[mb_strtolower(trim($question))]??null;
        if (!$intent) return null;
        $denied=fn(string $reason)=>['text'=>$reason,'source'=>'scope','scope_reason'=>'company_read_unavailable','escalation'=>null];
        // Persisted owner and unambiguous owner branch tier, never manager/cashier escalation.
        if (!$company || !$actor || $actor->role!=='company_admin' || !in_array($actor->pos_role,[null,'','pos_admin'],true)) {
            return $denied('Company-data answers abhi sirf current company owner ke liye hain. Cashier/manager access is read tool mein supported nahin; apna authorized report use karein.');
        }
        if (!Schema::hasTable('madadgar_read_audits')) return $denied('Audited read service unavailable hai; koi company total verify nahin hua.');
        $guard=$product==='di'?'web':$product;
        if (!in_array($product,['pos','fbrpos','di'],true) || $company->product_type!==$product) return $denied('Product context unavailable.');
        $sessionActor=auth($guard)->user();
        if (!$sessionActor || (int)$sessionActor->id!==(int)$actor->id || (int)$sessionActor->company_id!==(int)$company->id) return $denied('Authenticated panel context unavailable hai; koi data nahin padha gaya.');
        $branches=new BranchContextService;
        $branches->useGuard($guard);
        // Refuse a forged/stale explicit campus instead of silently broadening the scope.
        $requested=session(BranchContextService::SESSION_KEY);
        if ($requested!==null && $requested!==BranchContextService::ALL && (!is_numeric($requested) || !$branches->canAccess((int)$requested))) return $denied('Selected campus authorized nahin hai. Apne panel se valid campus select karein.');
        $branch=$branches->getActiveBranchId();
        if ($branch!==null && ($branch<1 || !$branches->canAccess($branch))) return $denied('Authorized campus unavailable hai; company-wide total nahin dikhaya gaya.');
        $tz=$company->isLocalCollectionPilot() ? config('app.timezone','UTC') : config('reporting_connector.timezone','Asia/Karachi');
        $date=now($tz)->toDateString();
        try {
            return DB::transaction(function()use($company,$actor,$product,$intent,$branch,$date,$tz){
                $current=User::whereKey($actor->id)->lockForUpdate()->first();
                abort_unless($current && $current->is_active && (int)$current->company_id===(int)$company->id && $current->role==='company_admin' && in_array($current->pos_role,[null,'','pos_admin'],true),403);
                $out=self::read($company,$intent,$branch,$date);
                $evidence=['intent'=>$intent,'company_id'=>(int)$company->id,'branch_id'=>$branch,'report_date'=>$date,'timezone'=>$tz,
                    'sources'=>$out['sources'],'outcome'=>$out['outcome'],'values'=>$out['values'],'read_at'=>now()->toIso8601String()];
                $hash=hash('sha256',json_encode($evidence,JSON_THROW_ON_ERROR));
                $audit=DB::table('madadgar_read_audits')->insertGetId(['company_id'=>$company->id,'user_id'=>$actor->id,'branch_id'=>$branch,
                    'intent'=>$intent,'outcome'=>$out['outcome'],'report_date'=>$date,'timezone'=>$tz,'sources'=>json_encode($out['sources']),
                    'result_sha256'=>$hash,'created_at'=>now()]);
                $text=$out['text']."\nSource: ".($out['sources']?implode(', ',$out['sources']):'unavailable').". Date: {$date} ({$tz}); ".($branch ? "campus #{$branch}" : 'company-wide authorized scope').'. Read at: '.now()->toIso8601String().".\nRetained records only; physical cash, complete retention aur independent fiscal acceptance verify nahin hui.";
                return ['text'=>$text,'source'=>'company_read','scope_reason'=>'bounded_company_read','escalation'=>null,
                    'read_evidence'=>$evidence+['audit_id'=>$audit,'result_sha256'=>$hash]];
            });
        } catch (\Throwable $e) {
            // Do not expose SQL, names, schema internals or partially read figures.
            return $denied('Selected source ya audited read unavailable hai. Total unknown hai, zero nahin; authorized report/support se check karein.');
        }
    }

    private static function read(Company $company,string $intent,?int $branch,string $date):array
    {
        $unknown=fn(string $note,array $sources=[])=>['text'=>$note,'sources'=>$sources,'outcome'=>'unavailable','values'=>[]];
        $args=['company_name'=>$company->name,'company_id'=>$company->id,'date_from'=>$date,'date_to'=>$date];
        if ($branch!==null) $args['branch_id']=$branch;
        if ($company->isLocalCollectionPilot()) {
            if (in_array($intent,['stock','recipes','recipe_capacity','tax'],true)) return $unknown('Local School/Hospital pilot mein yeh retail/tax read supported nahin. Fiscal submission OFF/blocked hai.');
            $report=(new LocalCollectionReports)->read($company,$args);
            $tables=$company->isSchoolFeePilot()?['school_fee_accounts','school_fee_receipts','school_fee_day_closes']:['hospital_invoices','hospital_payments','hospital_day_closes'];
            if (($report['coverage']['status']??'')==='UNAVAILABLE') return $unknown('Required local ledger unavailable; amount unknown hai, zero nahin.',$tables);
            $values=match($intent){'billing'=>$report['billing'],'payments'=>array_intersect_key($report['payments'],array_flip(['receipt_count','collected_paisa'])),default=>array_intersect_key($report['closings'],array_flip(['snapshot_count','cash_paisa','counted_cash_paisa','variance_paisa']))};
            $labels=['documents'=>'Billed documents','principal_paisa'=>'Billed principal PKR','legacy_fully_paid_documents'=>'Legacy paid receipts','receipt_count'=>'Receipt count','collected_paisa'=>'Recorded collections PKR','snapshot_count'=>'Saved closing snapshots','cash_paisa'=>'Saved cash PKR','counted_cash_paisa'=>'Counted cash PKR','variance_paisa'=>'Variance PKR'];
            return self::numericAnswer($values,$labels,$tables,'Local collection evidence. Fiscal submission OFF/blocked. Missing closing is unknown, not proof of an open day.');
        }
        if (in_array($intent,['billing','tax','payments'],true)) {
            $reports=new CompanyReadReports;
            $table=match($company->product_type){'pos'=>'pos_transactions','di'=>'invoices',default=>'fbr_pos_transactions'};
            $items=match($company->product_type){'pos'=>'pos_transaction_items','di'=>'invoice_items',default=>'fbr_pos_transaction_items'};
            if ($intent==='payments') {
                $r=$reports->payments($args,[$company->id]);$cash=$r['stored_pos_cash_fields'];
                if (!$cash) return $unknown('Stored payment fields unavailable. Actual collected total unknown hai.',[$table]);
                return self::numericAnswer((array)$cash,['stored_sale_cash_received'=>'Recorded tendered cash PKR','stored_sale_change_due'=>'Recorded change PKR','stored_return_cash_received'=>'Recorded return cash field PKR'],[$table],'These stored cash fields are not a universal collections total or proof of card/bank settlement.');
            }
            $r=$reports->summary($args,[$company->id]);
            if ($intent==='billing') return self::numericAnswer(array_intersect_key($r['totals'],array_flip(['completed_documents','sales_documents','return_documents','sales_total','return_credit_note_total','net_billing','net_tax','tax_unknown_documents'])),
                ['completed_documents'=>'Completed documents','sales_documents'=>'Sales documents','return_documents'=>'Returns','sales_total'=>'Billed sales PKR','return_credit_note_total'=>'Return billing PKR','net_billing'=>'Net billed PKR','net_tax'=>'Stored net tax PKR','tax_unknown_documents'=>'Unknown tax documents'],[$table],'Billing is invoice value, not collected money. Returns subtracted once. DI locked documents can include sandbox; this is not verified production revenue.');
            if (!($r['tax_items']['available']??false)) return $unknown('Stored item tax/exempt classifications unavailable; zero rate ko exempt assume nahin kiya gaya.',[$items]);
            $values=[];$labels=[];
            foreach($r['tax_items']['groups'] as $group) {
                if(!in_array($group->classification,['taxable','exempt','zero_rate','unknown'],true)) continue;
                foreach(['item_rows','net_item_tax','tax_unknown_rows'] as $key){$name=$group->classification.'_'.$key;$values[$name]=$group->$key;$labels[$name]=$group->classification.' '.str_replace('_',' ',$key).($key==='net_item_tax'?' PKR':'');}
            }
            return self::numericAnswer($values,$labels,[$items],'Stored sale-time classifications only, not current tax/legal advice. Empty rows do not prove exemption.');
        }
        if ($intent==='closing') return self::retailClosing($company,$branch,$date);
        if ($company->product_type!=='pos') return $unknown('Inventory/recipe read is currently supported only for PRA POS, not this product.');
        if (!PosFeatureService::moduleAvailable($company,in_array($intent,['recipes','recipe_capacity'],true)?'recipes':'inventory')) return $unknown('Yeh module current company/package mein available nahin.');
        if ($intent==='recipe_capacity') {
            $r=MadadgarRecipeEvidence::read((int)$company->id,$branch);
            return self::numericAnswer($r['values'],$r['labels'],$r['sources'],$r['note']);
        }
        if ($intent==='recipes') {
            if (!Schema::hasColumns('product_recipes',['company_id','product_id','ingredient_id','is_active']) || !Schema::hasTable('ingredients') || !Schema::hasTable('pos_products')) return $unknown('Recipe source unavailable.');
            if ($branch!==null) return $unknown('Recipes company-level hain; campus-filtered recipe read available nahin, company total nahin dikhaya gaya.',['product_recipes']);
            $q=DB::table('product_recipes as r')->join('pos_products as p',fn($j)=>$j->on('p.id','=','r.product_id')->on('p.company_id','=','r.company_id'))->join('ingredients as i',fn($j)=>$j->on('i.id','=','r.ingredient_id')->on('i.company_id','=','r.company_id'))->where('r.company_id',$company->id)->where('r.is_active',true);
            return self::numericAnswer(['active_recipe_lines'=>$q->count(),'products_with_recipe'=>(clone$q)->distinct()->count('r.product_id')],['active_recipe_lines'=>'Active recipe ingredient lines','products_with_recipe'=>'Products with active recipes'],['product_recipes','pos_products','ingredients'],'Catalogue structure only; preparation capacity, costs and physical stock are not inferred.');
        }
        if (!Schema::hasColumns('inventory_stocks',['company_id','branch_id','quantity','product_id']) || !Schema::hasColumns('pos_products',['company_id','id','is_active'])) return $unknown('Inventory source unavailable.');
        $q=DB::table('inventory_stocks as s')->join('pos_products as p',fn($j)=>$j->on('p.id','=','s.product_id')->on('p.company_id','=','s.company_id'))->where('s.company_id',$company->id)->where('p.is_active',true);
        if($branch!==null)$q->where('s.branch_id',$branch);
        return self::numericAnswer(['tracked_rows'=>(clone$q)->whereNotNull('s.quantity')->count(),'nonpositive_rows'=>(clone$q)->whereNotNull('s.quantity')->where('s.quantity','<=',0)->count(),'unknown_rows'=>(clone$q)->whereNull('s.quantity')->count()],['tracked_rows'=>'Tracked stock rows','nonpositive_rows'=>'Nonpositive stock rows','unknown_rows'=>'Untracked quantity rows'],['inventory_stocks','pos_products'],'Rows are not summed across mixed units. No stock healing or mutation performed; missing rows do not mean zero stock.');
    }

    private static function retailClosing(Company $company,?int $branch,string $date):array
    {
        $unknown=fn($note,$sources=[])=>['text'=>$note,'sources'=>$sources,'outcome'=>'unavailable','values'=>[]];
        if (!in_array($company->product_type,['pos','fbrpos'],true)) return $unknown('DI has no retained retail cash closing source.');
        $table=$company->product_type==='pos'?'pos_day_close_reports':'fbr_day_close_reports';
        if (!Schema::hasColumns($table,['company_id','report_date'])) return $unknown('Saved closing source unavailable; cash unknown.',[$table]);
        if ($branch!==null && !Schema::hasColumn($table,'branch_id')) return $unknown('Saved closing cannot establish selected campus; no company-wide fallback.',[$table]);
        $q=DB::table($table)->where('company_id',$company->id)->whereDate('report_date',$date);
        if($branch!==null)$q->where('branch_id',$branch);
        $count=(clone$q)->count();
        if($count!==1)return self::numericAnswer(['snapshot_count'=>$count],['snapshot_count'=>'Saved closing snapshots'],[$table],$count?'Multiple saved closes can overlap; cash amounts not added or selected arbitrarily.':'No saved close. Day status and cash unknown, not zero.');
        $fields=['cash_amount','expected_cash','counted_cash','cash_variance'];
        $available=array_values(array_filter($fields,fn($f)=>Schema::hasColumn($table,$f)));
        $row=$q->first(array_merge(['company_id'],$available));$values=['snapshot_count'=>1];
        foreach($fields as$f)$values[$f]=$row->$f??null;
        return self::numericAnswer($values,['snapshot_count'=>'Saved closing snapshots','cash_amount'=>'Saved cash PKR','expected_cash'=>'Saved expected cash PKR','counted_cash'=>'Saved counted cash PKR','cash_variance'=>'Saved variance PKR'],[$table],'Frozen saved business-date snapshot only; no recalculation, current open-day inference or physical cash verification.');
    }

    private static function numericAnswer(array $values,array $labels,array $sources,string $note):array
    {
        $lines=[$note];$clean=[];
        foreach($values as $key=>$value){if(!isset($labels[$key]))continue;$clean[$key]=$value===null?null:(is_numeric($value)?0+$value:null);$display=$clean[$key]===null?'unknown':(str_ends_with($key,'_paisa')?number_format($clean[$key]/100,2):number_format($clean[$key],str_contains($labels[$key],'PKR')?2:0));$lines[]=$labels[$key].': '.$display;}
        return ['text'=>implode("\n",$lines),'values'=>$clean,'sources'=>$sources,'outcome'=>count($clean)?'recorded':'empty'];
    }
}
