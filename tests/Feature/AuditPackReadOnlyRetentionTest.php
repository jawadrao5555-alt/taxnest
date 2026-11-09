<?php

namespace Tests\Feature;

use App\Models\AuditPack;
use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class AuditPackReadOnlyRetentionTest extends TestCase
{
    use RefreshDatabase;

    private function owner(): User
    {
        $company = Company::create(['name' => 'Fictional retention company', 'ntn' => uniqid('AUD'),
            'product_type' => 'di', 'status' => 'approved', 'company_status' => 'active', 'onboarding_completed' => true, 'is_internal_account' => true]);

        return User::factory()->create(['company_id' => $company->id, 'role' => 'company_admin', 'is_active' => true]);
    }

    private function pack(User $owner, int $days = 8, string $status = 'ready'): AuditPack
    {
        $pack = AuditPack::create(['company_id' => $owner->company_id, 'user_id' => $owner->id, 'status' => $status,
            'date_from' => '2026-01-01', 'date_to' => '2026-01-31']);
        $pack->forceFill(['created_at' => now()->subDays($days), 'file_path' => 'audit-packs/company_'.$owner->company_id.'/fbr-audit-pack-'.$pack->id.'.zip', 'file_size' => 18])->save();
        Storage::disk('local')->put($pack->file_path, 'SYNTHETIC ZIP DATA');

        return $pack;
    }

    public function test_compliance_get_is_read_only_even_for_expired_exports(): void
    {
        Storage::fake('local');
        $owner = $this->owner();
        $pack = $this->pack($owner);
        $before = $pack->fresh()->getAttributes();
        $this->actingAs($owner, 'web')->get('/compliance')->assertOk();
        $this->assertSame($before, $pack->fresh()?->getAttributes());
        Storage::disk('local')->assertExists($pack->file_path);
    }

    public function test_expired_downloads_are_denied_without_cleanup_or_an_older_valid_signature_bypass(): void
    {
        Storage::fake('local');
        $owner = $this->owner();
        $pack = $this->pack($owner);
        $before = $pack->fresh()->getAttributes();
        $this->actingAs($owner, 'web')->get(route('compliance.packs.download', $pack))->assertGone();
        $signed = URL::temporarySignedRoute('compliance.packs.download-signed', now()->addDay(), ['pack' => $pack->id]);
        $this->get($signed)->assertGone();
        $this->assertSame($before, $pack->fresh()?->getAttributes());
        Storage::disk('local')->assertExists($pack->file_path);
    }

    public function test_status_get_does_not_advance_stale_work_and_expired_status_has_no_download(): void
    {
        Storage::fake('local');
        $owner = $this->owner();
        $pack = $this->pack($owner, 1, 'pending');
        $pack->forceFill(['updated_at' => now()->subMinute()])->save(['timestamps' => false]);
        $before = $pack->fresh()->getAttributes();
        $this->actingAs($owner, 'web')->get(route('compliance.packs.status', $pack))->assertOk()
            ->assertJsonPath('status', 'pending')->assertJsonPath('advance_url', route('compliance.packs.advance', $pack));
        $this->assertSame($before, $pack->fresh()->getAttributes());
        $old = $this->pack($owner);
        $this->get(route('compliance.packs.status', $old))->assertOk()->assertJsonPath('status', 'expired')->assertJsonPath('download_url', null)->assertJsonPath('advance_url', null);
        $this->post(route('compliance.packs.advance', $old))->assertGone();
        $other = $this->owner();
        $this->actingAs($other, 'web')->post(route('compliance.packs.advance', $pack))->assertForbidden();
        $this->assertSame($before, $pack->fresh()->getAttributes());
    }

    public function test_bounded_scheduled_cleanup_preserves_history_active_work_and_current_exports(): void
    {
        Storage::fake('local');
        $owner = $this->owner();
        $invoiceId = \Illuminate\Support\Facades\DB::table('invoices')->insertGetId([
            'company_id' => $owner->company_id, 'invoice_number' => 'RC-EXPORT-RETENTION',
            'internal_invoice_number' => 'RC-EXPORT-RETENTION', 'invoice_date' => now()->toDateString(),
            'status' => 'locked', 'fbr_status' => 'sandbox', 'buyer_name' => 'Fictional export buyer',
            'total_amount' => 0, 'total_sales_tax' => 0, 'total_value_excluding_st' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $invoiceBefore = (array) \Illuminate\Support\Facades\DB::table('invoices')->find($invoiceId);
        $old = $this->pack($owner);
        $old2 = $this->pack($owner);
        $fresh = $this->pack($owner, 1);
        $active = $this->pack($owner, 10, 'processing');
        $this->artisan('audit-packs:prune', ['--limit' => 1])->assertSuccessful();
        $this->assertSame('expired', $old->fresh()->status);
        $this->assertNull($old->fresh()->file_path);
        Storage::disk('local')->assertMissing($old->file_path);
        Storage::disk('local')->assertExists($old2->file_path);
        Storage::disk('local')->assertExists($fresh->file_path);
        Storage::disk('local')->assertExists($active->file_path);
        $this->assertSame('2026-01-01', $old->fresh()->date_from->toDateString());
        $this->artisan('audit-packs:prune', ['--limit' => 10])->assertSuccessful();
        $this->artisan('audit-packs:prune', ['--limit' => 10])->assertSuccessful();
        $this->assertDatabaseCount('audit_packs', 4);
        $this->assertSame('ready', $fresh->fresh()->status);
        $this->assertSame('processing', $active->fresh()->status);
        $this->assertSame($invoiceBefore, (array) \Illuminate\Support\Facades\DB::table('invoices')->find($invoiceId));
    }

    public function test_explicit_delete_refuses_a_foreign_export_path(): void
    {
        Storage::fake('local');
        $owner = $this->owner();
        $other = $this->owner();
        $foreign = $this->pack($other, 1);
        $pack = $this->pack($owner, 1);
        $pack->update(['file_path' => $foreign->file_path]);
        $before = $pack->fresh()->getAttributes();
        $this->actingAs($owner, 'web')->delete(route('compliance.packs.destroy', $pack))->assertStatus(409);
        Storage::disk('local')->assertExists($foreign->file_path);
        $this->assertSame($before, $pack->fresh()->getAttributes());
    }

    public function test_cleanup_refuses_a_cross_company_path_and_preserves_its_bytes(): void
    {
        Storage::fake('local');
        $owner = $this->owner();
        $other = $this->owner();
        $foreign = $this->pack($other, 1);
        $pack = $this->pack($owner);
        $pack->update(['file_path' => $foreign->file_path]);
        $before = $pack->fresh()->getAttributes();
        $this->artisan('audit-packs:prune', ['--limit' => 10])->assertFailed();
        Storage::disk('local')->assertExists($foreign->file_path);
        $this->assertSame($before,$pack->fresh()->getAttributes());
    }
}
