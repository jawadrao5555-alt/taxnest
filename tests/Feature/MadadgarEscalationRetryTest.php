<?php
namespace Tests\Feature;
use App\Http\Controllers\MadadgarController;
use App\Models\{Company,User};
use Illuminate\Support\Facades\DB;
use Illuminate\Http\Request;
class MadadgarEscalationRetryTest extends MadadgarScopedAssistantTest
{
    protected function setUp(): void {
        parent::setUp();
        (require database_path('migrations/2026_12_26_010000_create_madadgar_escalation_requests.php'))->up();
    }
    private function actor(): User {
        $c=Company::create(['name'=>'Synthetic retry tenant','product_type'=>'pos']);
        $u=User::create(['company_id'=>$c->id,'name'=>'Synthetic cashier','role'=>'user','pos_role'=>'pos_cashier','is_active'=>true]);
        auth('pos')->setUser($u); return $u;
    }
    private function confirm(array $changes=[]): \Illuminate\Http\JsonResponse {
        return (new MadadgarController)->escalate(Request::create('/pos/madadgar/escalate','POST',array_merge([
            'session_id'=>'11111111-1111-4111-8111-111111111111','request_id'=>'22222222-2222-4222-8222-222222222222',
            'title'=>'Synthetic printer issue','summary'=>'Confirmation only','kind'=>'problem',
        ],$changes)));
    }
    public function test_lost_response_retry_returns_identical_reference_after_daily_cap(): void {
        $this->actor(); $first=$this->confirm()->getData(true);
        for($i=0;$i<4;$i++)$this->confirm(['request_id'=>null]);
        $this->assertSame(429,$this->confirm(['request_id'=>'33333333-3333-4333-8333-333333333333'])->status());
        $this->assertSame($first,$this->confirm()->getData(true));
        $history=(new MadadgarController)->history(Request::create('/pos/madadgar/history','GET',['session_id'=>'11111111-1111-4111-8111-111111111111']))->getData(true);
        $this->assertCount(1,array_filter($history['messages'],fn($m)=>$m['ref']===$first['ref'] && $m['escalated']));
        $this->assertSame(5,DB::table('feature_suggestions')->count());
        $this->assertSame(5,DB::table('madadgar_messages')->count());
        foreach(['title'=>'Different','summary'=>'Changed','kind'=>'feature_request'] as $field=>$value) {
            $this->assertSame(409,$this->confirm([$field=>$value])->status());
        }
        $this->assertSame($first,$this->confirm()->getData(true));
    }
    public function test_request_identity_never_deduplicates_other_tenant_or_session(): void {
        $this->actor(); $a=$this->confirm()->getData(true);
        $b=$this->confirm(['session_id'=>'33333333-3333-4333-8333-333333333333'])->getData(true);
        $this->actor();$c=$this->confirm()->getData(true);
        $this->assertCount(3,array_unique([$a['ref'],$b['ref'],$c['ref']]));
        $this->assertSame(3,DB::table('madadgar_escalation_requests')->count());
    }
    public function test_stale_disabled_actor_cannot_recover_or_create(): void {
        $u=$this->actor();$this->confirm();DB::table('users')->where('id',$u->id)->update(['is_active'=>false]);
        try {$this->confirm();$this->fail('disabled actor permitted');} catch(\Symfony\Component\HttpKernel\Exception\HttpException $e){$this->assertSame(403,$e->getStatusCode());}
        $this->assertSame(1,DB::table('feature_suggestions')->count());
    }
    public function test_forward_only_migration_retains_identity_and_old_clients_remain_accepted(): void {
        $this->actor();$first=$this->confirm()->getData(true);
        $migration=require database_path('migrations/2026_12_26_010000_create_madadgar_escalation_requests.php');
        $migration->down();$migration->up();
        $this->assertSame($first,$this->confirm()->getData(true));
        $this->assertSame(1,DB::table('madadgar_escalation_requests')->count());
        $this->assertSame(200,$this->confirm(['request_id'=>null])->status());
    }
    public function test_persisted_membership_change_rejects_stale_session(): void {
        $u=$this->actor();$this->confirm();DB::table('users')->where('id',$u->id)->update(['company_id'=>$u->company_id+1]);
        try {$this->confirm();$this->fail('moved actor permitted');}catch(\Symfony\Component\HttpKernel\Exception\HttpException $e){$this->assertSame(403,$e->getStatusCode());}
        $this->assertSame(1,DB::table('feature_suggestions')->count());
    }
    public function test_confirmation_failure_rolls_back_suggestion_and_identity(): void {
        $this->actor();
        \App\Models\MadadgarMessage::creating(fn()=>throw new \RuntimeException('Synthetic failed confirmation'));
        try {$this->confirm();$this->fail('must fail');}catch(\RuntimeException $e){$this->assertSame('Synthetic failed confirmation',$e->getMessage());}
        finally {\App\Models\MadadgarMessage::flushEventListeners();}
        $this->assertSame(0,DB::table('feature_suggestions')->count());
        $this->assertSame(0,DB::table('madadgar_escalation_requests')->count());
        $this->assertSame(200,$this->confirm()->status());
    }
}
