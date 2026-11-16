<?php
namespace Tests\Feature\Reporting;
use App\Services\Reporting\{TenantBackupEvidence, DiagnosticSourceEvidence};
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{DB, Schema};
use Illuminate\Support\Str;
use Tests\TestCase;
class TenantBackupEvidenceTest extends TestCase
{
    private string $source; private string $token;
    protected function setUp(): void {
        parent::setUp();Schema::dropAllTables();config(['app.timezone'=>'UTC','reporting_connector.timezone'=>'UTC']);
        $this->travelTo(\Illuminate\Support\Carbon::parse('2026-11-15 12:00:00', 'UTC'));
        Schema::create('companies',fn(Blueprint $t)=>[$t->id(),$t->string('name'),$t->string('product_type')]);
        DB::table('companies')->insert([['id'=>1,'name'=>'Synthetic tenant','product_type'=>'pos'],['id'=>2,'name'=>'Other tenant','product_type'=>'pos']]);
        (require database_path('migrations/2026_12_25_030000_create_tenant_backup_evidence.php'))->up();
        $this->source=(string)Str::uuid();$this->token=str_repeat('synthetic-token-',4);
        config(['backup_evidence.sources'=>[$this->source=>['enabled'=>true,'token_sha256'=>hash('sha256',$this->token),'company_ids'=>[1]]]]);
    }
    private function payload(array $extra=[]): array {return array_replace(['company_id'=>1,'event_id'=>(string)Str::uuid(),'reported_status'=>'retained','event_at'=>'2026-11-15T10:00:00+00:00','reported_retained_until'=>'2026-11-22T10:00:00+00:00'],$extra);}
    private function submit(array $data,?string $token=null){return $this->withToken($token??$this->token)->postJson('/api/diagnostics/backup-evidence/'.$this->source,$data);}
    public function test_authenticated_tenant_metadata_is_idempotent_conflicts_preserve_original_and_read_is_partial(): void {
        $data=$this->payload();$first=$this->submit($data)->assertOk()->assertJsonPath('backup_verified',false)->json();
        $this->travel(1)->hours();$second=$this->submit($data)->assertOk()->json();$this->assertSame($first,$second);
        $before=DB::table('tenant_backup_evidence')->first();
        $this->submit(array_replace($data,['reported_status'=>'failed','reported_retained_until'=>null]))->assertStatus(409);
        $this->assertEquals($before,DB::table('tenant_backup_evidence')->first());
        $r=app(DiagnosticSourceEvidence::class)->read(['company_name'=>'Synthetic tenant','source'=>'pos.backup_evidence','date_from'=>'2026-11-15'],[1]);
        $this->assertSame('PARTIAL',$r['status']);$this->assertFalse($r['coverage']['seven_day_complete']);$this->assertFalse($r['backup_evidence']['restore_verified']);
        $this->assertSame($this->source,$r['rows'][0]['source_id']);$this->assertSame('authenticated_producer_report',$r['rows'][0]['provenance']);
        $this->assertStringNotContainsString($this->token,json_encode($r));$this->assertArrayNotHasKey('content_hash',$r['rows'][0]);
    }
    public function test_default_off_revocation_wrong_token_and_foreign_tenant_are_rejected(): void {
        $this->submit($this->payload(),'bad-token')->assertUnauthorized();$this->submit($this->payload(['company_id'=>2]))->assertForbidden();
        config(['backup_evidence.sources.'.$this->source.'.enabled'=>false]);$this->submit($this->payload())->assertUnauthorized();
        config(['backup_evidence.sources'=>[]]);$this->submit($this->payload())->assertUnauthorized();$this->assertSame(0,DB::table('tenant_backup_evidence')->count());
    }
    public function test_raw_payloads_paths_completeness_and_invalid_time_are_refused(): void {
        foreach(['path','password','payload','complete','restore_verified'] as $key)$this->submit($this->payload([$key=>'PRIVATE-CANARY']))->assertUnprocessable();
        $this->submit($this->payload(['event_at'=>'2026-11-16T12:00:00+00:00']))->assertUnprocessable();
        $this->submit($this->payload(['reported_retained_until'=>'2026-11-01T12:00:00+00:00']))->assertUnprocessable();
        $this->assertSame(0,DB::table('tenant_backup_evidence')->count());
    }
    public function test_empty_window_and_other_tenant_have_no_healthy_or_global_attribution(): void {
        $this->submit($this->payload())->assertOk();
        $r=app(DiagnosticSourceEvidence::class)->read(['company_name'=>'Other tenant','source'=>'pos.backup_evidence','date_from'=>'2026-11-15'],[2]);
        $this->assertSame('NO_RETAINED_EVENTS',$r['status']);$this->assertSame([],$r['rows']);$this->assertFalse($r['coverage']['complete']);
        $this->expectException(\Illuminate\Validation\ValidationException::class);
        app(DiagnosticSourceEvidence::class)->read(['company_name'=>'Synthetic tenant','source'=>'pos.backup_evidence','date_from'=>'2026-11-15'],[2]);
    }
    public function test_seven_dates_are_not_proof_of_completeness_and_branch_scope_is_unavailable(): void {
        for($i=0;$i<7;$i++)$this->submit($this->payload(['event_at'=>now()->subDays($i)->setTime(10,0)->toIso8601String()]))->assertOk();
        $r=app(DiagnosticSourceEvidence::class)->read(['company_name'=>'Synthetic tenant','source'=>'pos.backup_evidence','date_from'=>'2026-11-09','date_to'=>'2026-11-15','limit'=>2],[1]);
        $this->assertSame(7,$r['observed']['retained_rows_in_window']);$this->assertFalse($r['coverage']['seven_day_complete']);$this->assertTrue($r['pagination']['has_more']);
        Schema::create('branches',fn(Blueprint $t)=>[$t->id(),$t->unsignedBigInteger('company_id')]);DB::table('branches')->insert(['id'=>1,'company_id'=>1]);
        $r=app(DiagnosticSourceEvidence::class)->read(['company_name'=>'Synthetic tenant','branch_id'=>1,'source'=>'pos.backup_evidence','date_from'=>'2026-11-15'],[1]);
        $this->assertSame('BRANCH_SCOPE_UNAVAILABLE',$r['status']);$this->assertSame([],$r['rows']);
    }
    public function test_large_requests_and_per_source_rate_are_bounded(): void {
        $this->submit($this->payload(['payload'=>str_repeat('x',5000)]))->assertStatus(413);
        \Illuminate\Support\Facades\RateLimiter::clear('backup-evidence:'.hash('sha256',$this->source));
        for($i=0;$i<60;$i++)\Illuminate\Support\Facades\RateLimiter::hit('backup-evidence:'.hash('sha256',$this->source),60);
        $this->submit($this->payload())->assertStatus(429);
    }
}
