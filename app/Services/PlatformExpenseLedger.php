<?php
namespace App\Services;

use App\Models\{AdminUser,AdminAuditLog};
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Platform record keeping only. Never executes a payment or touches tenant ledgers. */
final class PlatformExpenseLedger
{
    public static function actor(bool $lock=false): AdminUser
    {
        $id=auth('admin')->id();abort_unless($id && !session('impersonation.readonly'),403);
        $query=AdminUser::whereKey($id);if($lock)$query->lockForUpdate();
        $actor=$query->first();abort_unless($actor?->isSuperAdmin(),403);return $actor;
    }
    public static function money(int|string $paisa): string
    {
        return (string)\Brick\Math\BigDecimal::of($paisa)->dividedBy(100,2);
    }
    public static function record(array $input,?int $reverse=null): object
    {
        self::actor();
        $rules=['request_id'=>'required|uuid','entry_date'=>'required|date_format:Y-m-d',
            'reference'=>'required|string|max:160','notes'=>'nullable|string|max:2000'];
        if($reverse===null)$rules+=['category'=>['required','string','regex:/^[a-z][a-z0-9_-]{0,79}$/D'],'amount'=>['required','string','regex:/^(?:0|[1-9][0-9]{0,9})(?:\.[0-9]{1,2})?$/D']];
        if($reverse!==null)$rules+=['amount'=>'prohibited','category'=>'prohibited'];
        $v=validator($input,$rules)->validate();
        foreach(['category','reference','notes'] as $key)if(isset($v[$key]))$v[$key]=trim($v[$key]);
        if($v['reference']==='' || ($reverse===null&&$v['category']===''))throw ValidationException::withMessages(['reference'=>'Category and reference cannot be blank.']);
        $v['notes']=$v['notes']??'';
        if($reverse===null){$v['amount_paisa']=(int)(string)\Brick\Math\BigDecimal::of($v['amount'])->multipliedBy(100)->toScale(0);unset($v['amount']);if($v['amount_paisa']<1)throw ValidationException::withMessages(['amount'=>'Enter a positive PKR amount.']);}
        $request=$v['request_id'];unset($v['request_id']);
        $hash=hash('sha256',json_encode(['reverses_id'=>$reverse,'data'=>$v],JSON_THROW_ON_ERROR));
        return DB::transaction(function()use($v,$request,$hash,$reverse){
            $actor=self::actor(true);
            $existing=DB::table('platform_expense_entries')->where('admin_id',$actor->id)->where('request_id',$request)->lockForUpdate()->first();
            if($existing){abort_unless(hash_equals($existing->fingerprint,$hash),409,'This request ID was already used with different details.');return $existing;}
            $data=$v;
            if($reverse!==null){
                $original=DB::table('platform_expense_entries')->where('id',$reverse)->lockForUpdate()->first();
                abort_unless($original&&$original->kind==='expense',404);
                abort_if(DB::table('platform_expense_entries')->where('reverses_id',$reverse)->lockForUpdate()->first()!==null,409,'Expense already reversed.');
                if($data['entry_date']<$original->entry_date)throw ValidationException::withMessages(['entry_date'=>'Reversal date cannot precede original expense date.']);
                $data['category']=$original->category;$data['amount_paisa']=$original->amount_paisa;
            }
            $id=DB::table('platform_expense_entries')->insertGetId($data+['admin_id'=>$actor->id,'actor_name'=>$actor->name,
                'request_id'=>$request,'fingerprint'=>$hash,'kind'=>$reverse===null?'expense':'reversal','reverses_id'=>$reverse,'currency'=>'PKR','created_at'=>now()]);
            AdminAuditLog::log($actor->id,'Platform expense '.($reverse===null?'recorded':'reversed'),'PlatformExpenseEntry',$id,
                ['request_id'=>$request,'fingerprint'=>$hash,'reverses_id'=>$reverse,'amount_paisa'=>(string)$data['amount_paisa'],'currency'=>'PKR','record_only'=>true]);
            return DB::table('platform_expense_entries')->where('id',$id)->first();
        },3);
    }
}
