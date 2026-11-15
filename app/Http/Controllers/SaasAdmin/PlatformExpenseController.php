<?php
namespace App\Http\Controllers\SaasAdmin;
use App\Http\Controllers\Controller;
use App\Services\PlatformExpenseLedger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
final class PlatformExpenseController extends Controller
{
    public function index(Request $request)
    {
        $actor=PlatformExpenseLedger::actor();
        $v=$request->validate(['from'=>'nullable|date_format:Y-m-d','to'=>'nullable|date_format:Y-m-d|after_or_equal:from','category'=>'nullable|string|max:80','reference'=>'nullable|string|max:160']);
        $q=DB::table('platform_expense_entries');
        if(!empty($v['from']))$q->where('entry_date','>=',$v['from']);if(!empty($v['to']))$q->where('entry_date','<=',$v['to']);
        if(!empty($v['category']))$q->where('category',$v['category']);if(!empty($v['reference']))$q->where('reference',$v['reference']);
        $expense=(string)(clone $q)->where('kind','expense')->sum('amount_paisa');$reversal=(string)(clone $q)->where('kind','reversal')->sum('amount_paisa');
        $totals=['expenses'=>PlatformExpenseLedger::money($expense),'reversals'=>PlatformExpenseLedger::money($reversal),'net'=>PlatformExpenseLedger::money((string)\Brick\Math\BigInteger::of($expense)->minus($reversal))];
        $entries=$q->orderByDesc('id')->paginate(25)->withQueryString();
        $reversed=DB::table('platform_expense_entries')->whereIn('reverses_id',$entries->pluck('id'))->pluck('reverses_id')->all();
        return response()->view('saas-admin.platform-expenses',compact('entries','totals','actor','reversed'))->header('Cache-Control','no-store');
    }
    public function store(Request $request){return $this->saved(PlatformExpenseLedger::record($request->all()));}
    public function reverse(Request $request,int $id){return $this->saved(PlatformExpenseLedger::record($request->all(),$id));}
    private function saved(object $entry){return response()->json(['id'=>$entry->id,'kind'=>$entry->kind,'amount'=>PlatformExpenseLedger::money($entry->amount_paisa),'reference'=>$entry->reference,'record_only'=>true])->header('Cache-Control','no-store');}
}
