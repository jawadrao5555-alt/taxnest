<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\FbrPosTransaction;
use App\Models\User;
use App\Services\FbrPosOwnerReconciliation;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class FbrPosOwnerReconciliationTest extends TestCase
{
    private User $owner;
    private FbrPosTransaction $bill;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Schema::dropAllTables();
        Schema::create('companies', function (Blueprint $t) {
            $t->id(); $t->string('name'); $t->string('fbr_pos_environment'); $t->timestamps(); $t->softDeletes();
        });
        DB::table('companies')->insert(['id' => 91, 'name' => 'Fictional reconciliation lab', 'fbr_pos_environment' => 'production']);
        Schema::create('fbr_pos_transactions', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('company_id'); $t->string('invoice_number');
            $t->string('invoice_mode'); $t->string('fbr_status'); $t->string('fbr_invoice_number')->nullable();
            $t->string('fbr_response_code')->nullable(); $t->string('fbr_submission_hash')->nullable();
            $t->timestamp('fbr_agent_claimed_at')->nullable(); $t->decimal('total_amount', 12, 2); $t->timestamps();
        });
        (require database_path('migrations/2026_10_13_140001_create_fbr_pos_owner_reconciliations.php'))->up();
        $this->owner = (new User)->forceFill(['id' => 7, 'company_id' => 91, 'role' => 'company_admin']);
        $this->bill = FbrPosTransaction::create(['company_id' => 91, 'invoice_number' => 'FPOS-2026-00001',
            'invoice_mode' => 'fbr', 'fbr_status' => 'verification_pending', 'total_amount' => 1180]);
    }

    private function data(array $changes = []): array
    {
        return array_merge(['request_key' => (string) Str::uuid(), 'fingerprint' => FbrPosOwnerReconciliation::fingerprint($this->bill),
            'decision' => 'accepted', 'evidence_source' => 'ims_log', 'evidence_reference' => 'fictional IMS log 2026-10-13',
            'environment' => 'production', 'fiscal_number' => '900000000001', 'confirmed' => '1'], $changes);
    }

    public function test_legacy_pending_claim_has_audited_recovery_without_claim_timestamp(): void
    {
        $this->bill->forceFill(['fbr_status'=>'pending','fbr_submission_hash'=>'legacy-dispatch'])->save();
        $this->assertFalse(FbrPosOwnerReconciliation::eligible($this->bill));
        $this->travel(4)->minutes();
        $this->assertTrue(FbrPosOwnerReconciliation::eligible($this->bill->fresh()));
        $this->assertSame([$this->bill->id],FbrPosOwnerReconciliation::recoveryQueue(91)->pluck('id')->all());
        app(FbrPosOwnerReconciliation::class)->resolve($this->owner,91,$this->bill->id,$this->data(['decision'=>'not_accepted','fiscal_number'=>null]));
        $this->assertSame('failed',$this->bill->fresh()->fbr_status);
    }

    public function test_reporting_off_recovery_list_preserves_holds_and_isolates_company_and_provisionals(): void
    {
        $foreign = FbrPosTransaction::create(['company_id'=>92,'invoice_number'=>'FOREIGN','invoice_mode'=>'fbr','fbr_status'=>'verification_pending','total_amount'=>1180]);
        FbrPosTransaction::create(['company_id'=>91,'invoice_number'=>'LOCAL','invoice_mode'=>'local','fbr_status'=>'verification_pending','total_amount'=>1180]);
        FbrPosTransaction::create(['company_id'=>91,'invoice_number'=>'FAILED','invoice_mode'=>'fbr','fbr_status'=>'failed','total_amount'=>1180]);
        $this->assertSame([$this->bill->id], FbrPosOwnerReconciliation::recoveryQueue(91)->pluck('id')->all());
        $this->assertSame([$foreign->id], FbrPosOwnerReconciliation::recoveryQueue(92)->pluck('id')->all());
    }

    public function test_accepted_owner_evidence_is_audited_idempotently_without_fabricating_code_100(): void
    {
        $data = $this->data();
        $service = app(FbrPosOwnerReconciliation::class);
        $service->resolve($this->owner, 91, $this->bill->id, $data);
        $service->resolve($this->owner, 91, $this->bill->id, $data);
        $bill = $this->bill->fresh();
        $this->assertSame('submitted', $bill->fbr_status);
        $this->assertSame('900000000001', $bill->fbr_invoice_number);
        $this->assertNull($bill->fbr_response_code);
        $this->assertSame(1, DB::table('fbr_pos_owner_reconciliations')->count());
        $audit = DB::table('fbr_pos_owner_reconciliations')->first();
        $this->assertSame(7, $audit->actor_id);
        $this->assertSame(hash('sha256', '900000000001'), $audit->fiscal_number_hash);
        Http::assertNothingSent();
    }

    public function test_local_confirmed_rejection_releases_same_usin_without_submitting(): void
    {
        $this->bill->update(['fbr_submission_hash' => 'claimed', 'fbr_agent_claimed_at' => now()->subMinutes(4)]);
        app(FbrPosOwnerReconciliation::class)->resolve($this->owner, 91, $this->bill->id,
            $this->data(['decision' => 'not_accepted', 'fiscal_number' => null]));
        $bill = $this->bill->fresh();
        $this->assertSame('failed', $bill->fbr_status);
        $this->assertSame('FPOS-2026-00001', $bill->invoice_number);
        $this->assertNull($bill->fbr_submission_hash);
        $this->assertNull($bill->fbr_agent_claimed_at);
        Http::assertNothingSent();
    }

    public function test_portal_absence_cannot_release_invoice(): void
    {
        $this->expectException(ValidationException::class);
        app(FbrPosOwnerReconciliation::class)->resolve($this->owner, 91, $this->bill->id,
            $this->data(['decision' => 'not_accepted', 'evidence_source' => 'fbr_portal', 'fiscal_number' => null]));
    }

    public function test_confirmation_and_company_environment_change_fail_closed(): void
    {
        $data = $this->data();
        unset($data['confirmed']);
        try { app(FbrPosOwnerReconciliation::class)->resolve($this->owner, 91, $this->bill->id, $data); $this->fail('Missing confirmation accepted'); }
        catch (ValidationException $e) { $this->assertArrayHasKey('confirmed', $e->errors()); }
        $data = $this->data();
        Company::findOrFail(91)->update(['fbr_pos_environment' => 'sandbox']);
        try { app(FbrPosOwnerReconciliation::class)->resolve($this->owner, 91, $this->bill->id, $data); $this->fail('Changed environment accepted'); }
        catch (ValidationException $e) { $this->assertNotEmpty($e->errors()); }
        $this->assertSame(0, DB::table('fbr_pos_owner_reconciliations')->count());
    }

    public function test_stale_form_does_not_overwrite_late_callback(): void
    {
        $data = $this->data();
        $this->bill->update(['fbr_status' => 'submitted', 'fbr_invoice_number' => '900000000099']);
        try { app(FbrPosOwnerReconciliation::class)->resolve($this->owner, 91, $this->bill->id, $data); $this->fail('Stale form accepted'); }
        catch (ValidationException $e) { $this->assertArrayHasKey('fingerprint', $e->errors()); }
        $this->assertSame('900000000099', $this->bill->fresh()->fbr_invoice_number);
        $this->assertSame(0, DB::table('fbr_pos_owner_reconciliations')->count());
    }

    public function test_environment_mismatch_and_known_number_cannot_be_released(): void
    {
        foreach ([['environment' => 'sandbox'], ['decision' => 'not_accepted', 'fiscal_number' => null]] as $change) {
            $this->bill->update(['fbr_invoice_number' => '900000000001']);
            try { app(FbrPosOwnerReconciliation::class)->resolve($this->owner, 91, $this->bill->id, $this->data($change)); $this->fail('Unsafe release accepted'); }
            catch (ValidationException $e) { $this->assertNotEmpty($e->errors()); }
        }
        $this->assertSame('verification_pending', $this->bill->fresh()->fbr_status);
    }

    public function test_non_owner_and_other_company_are_denied(): void
    {
        foreach ([['company_id' => 91, 'role' => 'staff'], ['company_id' => 92, 'role' => 'company_admin']] as $attrs) {
            $actor = (new User)->forceFill($attrs + ['id' => 8]);
            try { app(FbrPosOwnerReconciliation::class)->resolve($actor, 91, $this->bill->id, $this->data()); $this->fail('Unauthorized actor accepted'); }
            catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) { $this->assertSame(403, $e->getStatusCode()); }
        }
        $this->assertSame(0, DB::table('fbr_pos_owner_reconciliations')->count());
    }

    public function test_other_tenant_invoice_is_not_found_and_provisional_is_never_reconciled(): void
    {
        $data = $this->data();
        $this->bill->update(['company_id' => 92]);
        try { app(FbrPosOwnerReconciliation::class)->resolve($this->owner, 91, $this->bill->id, $data); $this->fail('Cross-tenant bill accepted'); }
        catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) { $this->assertTrue(true); }
        $this->bill->update(['company_id' => 91, 'invoice_mode' => 'local']);
        $this->assertFalse(FbrPosOwnerReconciliation::eligible($this->bill));
    }
}
