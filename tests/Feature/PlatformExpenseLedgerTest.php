<?php
namespace Tests\Feature;
use App\Models\AdminUser;
use App\Services\PlatformExpenseLedger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{DB,Schema};
use Illuminate\Support\Str;
use Tests\TestCase;
class PlatformExpenseLedgerTest extends TestCase
{
    use RefreshDatabase;
    private function owner(): AdminUser
    {
        $actor=AdminUser::create(['name'=>'Synthetic expense owner','email'=>'expense-'.Str::uuid().'@example.test','password'=>'Synthetic-test-only-123','role'=>'super_admin']);
        $this->actingAs($actor,'admin');return $actor;
    }
    private function data(array $extra=[]): array{return $extra+['request_id'=>(string)Str::uuid(),'entry_date'=>'2026-11-15','category'=>'hosting','amount'=>'123.45','reference'=>'SYN-EXPENSE','notes'=>'Synthetic record only'];}
    public function test_exact_money_retry_conflict_and_audit_are_atomic(): void
    {
        $actor=$this->owner();$data=$this->data();
        $first=$this->postJson('/admin/platform-expenses',$data)->assertOk()->json();
        $this->postJson('/admin/platform-expenses',$data)->assertOk()->assertJsonPath('id',$first['id']);
        foreach(['amount'=>'123.46','category'=>'office','reference'=>'OTHER','notes'=>'Changed'] as $key=>$value)$this->postJson('/admin/platform-expenses',array_replace($data,[$key=>$value]))->assertStatus(409);
        $this->assertSame(1,DB::table('platform_expense_entries')->count());
        $row=DB::table('platform_expense_entries')->sole();$this->assertSame(12345,(int)$row->amount_paisa);$this->assertSame($actor->id,(int)$row->admin_id);
        $this->assertSame(1,DB::table('admin_audit_logs')->where('target_type','PlatformExpenseEntry')->count());
        $this->assertSame('123.45',$first['amount']);
    }
    public function test_reversal_is_full_append_only_once_and_keeps_original(): void
    {
        $this->owner();$original=PlatformExpenseLedger::record($this->data());$before=json_encode($original);
        $d=['request_id'=>(string)Str::uuid(),'entry_date'=>'2026-11-16','reference'=>'SYN-REVERSAL','notes'=>'Correction'];
        $url='/admin/platform-expenses/'.$original->id.'/reverse';
        $r=$this->postJson($url,$d)->assertOk()->assertJsonPath('amount','123.45')->json();
        $this->postJson($url,$d)->assertOk()->assertJsonPath('id',$r['id']);
        $this->postJson($url,array_replace($d,['request_id'=>(string)Str::uuid()]))->assertStatus(409);
        $this->assertSame($before,json_encode(DB::table('platform_expense_entries')->find($original->id)));
        $this->assertSame(2,DB::table('platform_expense_entries')->count());
        $this->get('/admin/platform-expenses')->assertOk()->assertSee('Net recorded expenses: PKR 0.00')->assertSee('Reverses #'.$original->id);
    }
    public function test_invalid_money_category_reversal_amount_and_date_are_rejected(): void
    {
        $this->owner();
        foreach(['0','-1','1.001','1e2','10000000000','NaN'] as $amount)$this->postJson('/admin/platform-expenses',$this->data(['amount'=>$amount]))->assertStatus(422);
        $this->postJson('/admin/platform-expenses',$this->data(['category'=>' '] ))->assertStatus(422);
        $this->postJson('/admin/platform-expenses',$this->data(['category'=>'Mixed Case']))->assertStatus(422);
        $original=PlatformExpenseLedger::record($this->data());
        $d=['request_id'=>(string)Str::uuid(),'entry_date'=>'2026-11-14','reference'=>'REV'];
        $this->postJson('/admin/platform-expenses/'.$original->id.'/reverse',$d)->assertStatus(422);
        $this->postJson('/admin/platform-expenses/'.$original->id.'/reverse',array_replace($d,['entry_date'=>'2026-11-16','amount'=>'1']))->assertStatus(422);
    }
    public function test_current_actor_role_and_readonly_denial(): void
    {
        $actor=$this->owner();DB::table('admin_users')->where('id',$actor->id)->update(['role'=>'admin']);
        $this->postJson('/admin/platform-expenses',$this->data())->assertForbidden();$this->get('/admin/platform-expenses')->assertForbidden();
        DB::table('admin_users')->where('id',$actor->id)->update(['role'=>'super_admin']);
        session()->put('impersonation.readonly',true);
        try{PlatformExpenseLedger::record($this->data());$this->fail('Read-only context wrote expense');}catch(\Symfony\Component\HttpKernel\Exception\HttpException $e){$this->assertSame(403,$e->getStatusCode());}
        session()->forget('impersonation');
        $this->assertSame(0,DB::table('platform_expense_entries')->count());
    }
    public function test_filtered_totals_are_record_dates_not_tenant_revenue(): void
    {
        $this->owner();PlatformExpenseLedger::record($this->data());PlatformExpenseLedger::record($this->data(['category'=>'office','amount'=>'10.00','reference'=>'OTHER']));
        $this->get('/admin/platform-expenses?category=hosting&from=2026-11-15&to=2026-11-15')->assertOk()->assertSee('Net recorded expenses: PKR 123.45')->assertDontSee('OTHER');
        $this->get('/admin/platform-expenses?reference=OTHER')->assertOk()->assertSee('Net recorded expenses: PKR 10.00');
        $this->get('/admin/platform-expenses')->assertHeader('Cache-Control','must-revalidate, no-cache, no-store, private')->assertSee('not cash or bank balances');
    }
    public function test_audit_failure_rolls_back_entry_and_same_request_can_retry(): void
    {
        $this->owner();$data=$this->data();
        \App\Models\AdminAuditLog::creating(function(){throw new \RuntimeException('synthetic audit failure');});
        try{PlatformExpenseLedger::record($data);$this->fail('Audit failure was swallowed');}catch(\RuntimeException $e){$this->assertSame('synthetic audit failure',$e->getMessage());}
        finally{\App\Models\AdminAuditLog::flushEventListeners();}
        $this->assertSame(0,DB::table('platform_expense_entries')->count());PlatformExpenseLedger::record($data);$this->assertSame(1,DB::table('platform_expense_entries')->count());
    }
    public function test_history_survives_code_rollback_and_actor_removal_and_no_edit_route_exists(): void
    {
        $actor=$this->owner();$entry=PlatformExpenseLedger::record($this->data(['notes'=>'<script>bad</script>']));
        $this->get('/admin/platform-expenses')->assertSee('&lt;script&gt;bad&lt;/script&gt;',false)->assertDontSee('<script>bad</script>',false);
        $this->delete('/admin/platform-expenses/'.$entry->id);
        $this->assertSame(1,DB::table('platform_expense_entries')->where('id',$entry->id)->count());
        $this->assertFalse(collect(\Illuminate\Support\Facades\Route::getRoutes())->contains(fn($r)=>str_starts_with($r->uri(),'admin/platform-expenses')&&array_intersect($r->methods(),['DELETE','PUT','PATCH'])));
        $migration=require database_path('migrations/2026_12_31_235900_create_platform_expense_entries.php');$migration->down();
        DB::table('admin_users')->where('id',$actor->id)->delete();
        $this->assertSame('Synthetic expense owner',DB::table('platform_expense_entries')->find($entry->id)->actor_name);
    }
    public function test_actor_identity_and_large_exact_paisa_never_become_tenant_money(): void
    {
        $this->owner();$data=$this->data(['amount'=>'9999999999.99']);
        $r=$this->postJson('/admin/platform-expenses',$data)->assertOk()->assertJsonPath('amount','9999999999.99')->json();
        $this->assertSame('999999999999',(string)DB::table('platform_expense_entries')->find($r['id'])->amount_paisa);
        $this->assertSame(0,DB::table('health_payments')->count());
        $this->assertSame(0,DB::table('pos_payments')->count());
        auth('admin')->logout();$this->postJson('/admin/platform-expenses',$data)->assertRedirect('/admin/login');
    }
    public function test_reversal_rejects_changed_reference_and_reversal_of_reversal(): void
    {
        $this->owner();$original=PlatformExpenseLedger::record($this->data());
        $d=['request_id'=>(string)Str::uuid(),'entry_date'=>'2026-11-16','reference'=>'REV'];
        $reversal=PlatformExpenseLedger::record($d,$original->id);
        $this->postJson('/admin/platform-expenses/'.$original->id.'/reverse',array_replace($d,['reference'=>'CHANGED']))->assertStatus(409);
        $this->postJson('/admin/platform-expenses/'.$reversal->id.'/reverse',array_replace($d,['request_id'=>(string)Str::uuid()]))->assertNotFound();
        $this->assertSame(2,DB::table('platform_expense_entries')->count());
    }

}
