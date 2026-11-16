<?php

namespace Tests\Feature;

use App\Jobs\RetryFbrPosSubmissionJob;
use App\Jobs\SyncFbrPosOfflineInvoicesJob;
use App\Models\AdminAuditLog;
use App\Models\AdminUser;
use App\Models\Company;
use App\Models\FbrPosTransaction;
use App\Services\FbrService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Hard-block evidence only: these tests do not certify any external fiscal route. */
class PilotFiscalActivationBoundaryTest extends TestCase
{
    use RefreshDatabase;

    private function owner(): AdminUser
    {
        $owner = AdminUser::create(['name' => 'Synthetic owner', 'email' => uniqid().'@example.test',
            'password' => 'Synthetic-only-123', 'role' => 'super_admin']);
        $this->actingAs($owner, 'admin');
        Http::preventStrayRequests();
        return $owner;
    }

    private function company(string $category): Company
    {
        return Company::create(['name' => 'Synthetic '.$category, 'ntn' => uniqid(),
            'product_type' => 'fbrpos', 'pos_type' => $category, 'business_category' => $category,
            // Deliberately stale legacy configuration must not bypass the category guard.
            'fbr_reporting_enabled' => true]);
    }

    private function url(Company $company): string
    {
        return '/admin/companies/'.$company->id.'/pilot-fiscal-control';
    }

    public function test_client_supplied_route_evidence_and_foreign_company_id_cannot_activate_either_category(): void
    {
        $owner = $this->owner();
        $school = $this->company('education');
        $hospital = $this->company('hospital');
        $before = DB::table('companies')->orderBy('id')->get()->toArray();

        foreach ([$school, $hospital] as $company) {
            $this->postJson($this->url($company), [
                'enabled' => true, 'company_id' => $company->id === $school->id ? $hospital->id : $school->id,
                'category' => 'retail', 'verified' => true, 'sandbox_verified' => true,
                'status' => 'verified', 'official_route_verified' => true,
                'route' => 'ims', 'endpoint' => 'https://synthetic.invalid/not-an-official-route',
                'fbr_invoice_number' => 'SYNTHETIC-FORGED-ACK', 'qr_code' => 'SYNTHETIC-FORGED-QR',
                'historical_submission' => true,
            ])->assertStatus(422)->assertJsonPath('enabled', false)
                ->assertJsonPath('reason', 'category_route_unverified')
                ->assertJsonPath('historical_submission', false);

            $audit = AdminAuditLog::where('target_id', $company->id)->sole();
            $this->assertSame($owner->id, $audit->admin_id);
            $this->assertSame('Pilot fiscal ON rejected', $audit->action);
            $this->assertFalse($audit->metadata['effective_enabled']);
            $this->assertFalse($audit->metadata['historical_submission']);
            $this->assertSame($company->isSchoolFeePilot() ? 'school' : 'hospital', $audit->metadata['category']);
        }
        $this->assertEquals($before, DB::table('companies')->orderBy('id')->get()->toArray());
        Http::assertNothingSent();
    }

    public function test_repeated_off_on_attempts_direct_dispatch_and_retry_preserve_all_historical_states(): void
    {
        $this->owner();
        $companies = [$this->company('education'), $this->company('hospital')];
        $ids = [];
        foreach ($companies as $company) {
            foreach (['success', 'verification_pending', 'failed', 'pending', 'offline', 'local'] as $status) {
                $ids[] = DB::table('fbr_pos_transactions')->insertGetId([
                    'company_id' => $company->id, 'invoice_number' => 'SYN-'.$company->id.'-'.$status,
                    'transaction_type' => 'sale', 'status' => 'completed',
                    'invoice_mode' => $status === 'local' ? 'local' : 'fbr', 'fbr_status' => $status,
                    'fbr_invoice_number' => $status === 'success' ? 'SYN-ACK-'.$company->id : null,
                    'fbr_submission_hash' => $status === 'verification_pending' ? hash('sha256', 'uncertain-'.$company->id) : null,
                    'fbr_auto_retry_count' => 2, 'total_amount' => '125.50',
                    'created_at' => now()->subDay(), 'updated_at' => now()->subDay(),
                ]);
            }
        }
        $history = DB::table('fbr_pos_transactions')->whereIn('id', $ids)->orderBy('id')->get()->toArray();
        foreach ($companies as $company) {
            $other = $companies[$company->id === $companies[0]->id ? 1 : 0];
            $foreign = DB::table('companies')->find($other->id);
            for ($attempt = 0; $attempt < 2; $attempt++) {
                $this->postJson($this->url($company), ['enabled' => false])->assertOk()->assertJsonPath('enabled', false);
                $this->postJson($this->url($company), ['enabled' => true])->assertStatus(422)->assertJsonPath('enabled', false);
            }
            $this->assertEquals($foreign, DB::table('companies')->find($other->id));
            $this->assertFalse($company->fresh()->fbr_reporting_enabled);
            foreach ([false, true] as $automatic) {
                $result = app(FbrService::class)->submitPosDiPayload([], 'synthetic-not-a-token', 'sandbox', $company->id, $automatic);
                $this->assertSame('blocked', $result['state']);
                $this->assertNull($result['reference']);
                $this->assertSame([$company->isSchoolFeePilot() ? 'school_route_unverified' : 'hospital_route_unverified'], $result['error_codes']);
            }
        }
        foreach ($ids as $id) {
            $result = app(FbrService::class)->submitFbrPosTransaction(FbrPosTransaction::findOrFail($id));
            $this->assertSame('blocked', $result['status']);
            (new RetryFbrPosSubmissionJob($id))->handle();
            (new RetryFbrPosSubmissionJob($id))->handle();
        }
        (new SyncFbrPosOfflineInvoicesJob())->handle();
        (new SyncFbrPosOfflineInvoicesJob())->handle();
        $this->assertEquals($history, DB::table('fbr_pos_transactions')->whereIn('id', $ids)->orderBy('id')->get()->toArray());
        $this->assertSame(4, AdminAuditLog::where('action', 'Pilot fiscal ON rejected')->count());
        $this->assertSame(4, AdminAuditLog::where('action', 'Pilot fiscal OFF confirmed')->count());
        Http::assertNothingSent();
    }

    public function test_pilot_control_does_not_reconfigure_existing_retail_company(): void
    {
        $this->owner();
        $retail = $this->company('retail');
        $before = DB::table('companies')->find($retail->id);
        foreach ([false, true] as $enabled) {
            $this->postJson($this->url($retail), ['enabled' => $enabled])->assertNotFound();
        }
        $this->assertEquals($before, DB::table('companies')->find($retail->id));
        $this->assertTrue($retail->fresh()->fbr_reporting_enabled);
        $this->assertSame(0, AdminAuditLog::count());
        Http::assertNothingSent();
    }
}
