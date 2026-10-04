<?php

namespace Tests\Feature;

use App\Exceptions\HotelStayException;
use App\Http\Controllers\PosController;
use App\Models\Company;
use App\Models\HotelFolioEntry;
use App\Models\HotelStay;
use App\Models\User;
use App\Services\HotelFolioService;
use App\Services\HotelStayService;
use App\Services\PosFeatureService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;
use App\Services\HotelCreditNotePolicy;
use App\Models\PosTransaction;

class HotelCreditNotePolicyTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(array $overrides = [], string $method = 'cash'): array
    {
        PosFeatureService::flushGateCaches();
        $company = $this->company('hotel', array_merge(['pos_tax_rate_cash' => 0], $overrides));
        $owner = $this->owner($company);
        $stays = app(HotelStayService::class);
        $room = $this->room($stays, $company, 'CN-1', 1000);
        $stay = $stays->book((int) $company->id, (int) $owner->id, [
            'room_id' => $room->id, 'check_in_date' => '2026-10-04', 'check_out_date' => '2026-10-06',
            'guest_name' => 'Synthetic credit review', 'walk_in' => true,
        ]);
        $folio = app(HotelFolioService::class);
        $paid = app(\App\Services\HotelFolioInvoiceService::class)->fiscalTotalFor($company, 2000, $method);
        $folio->postPayment($stay, ['amount' => $paid, 'payment_method' => $method], (int) $owner->id);
        $bill = $folio->settleCoveredCharges($stay, (int) $owner->id, $method)['transaction'];
        // Synthetic acceptance fixture only; no regulator call.
        $bill->update(['invoice_mode' => 'pra', 'pra_status' => 'submitted', 'pra_invoice_number' => 'TEST-FISCAL-CN']);
        return [$stay, $owner, $bill->fresh('items')];
    }

    public function test_partial_and_full_review_preserve_bill_money_and_stay(): void
    {
        [$stay, $owner, $bill] = $this->fixture();
        $item = $bill->items->first();
        $beforeBill = $bill->getAttributes();
        $beforeFolio = HotelFolioEntry::where('stay_id', $stay->id)->get()->toArray();
        $policy = app(HotelCreditNotePolicy::class);
        $partial = $policy->review($stay, $owner, (int) $bill->id, [$item->id => (float) $item->quantity / 2]);
        $this->assertSame('partial', $partial['kind']);
        $this->assertSame($bill->invoice_number, $partial['original_usin']);
        $this->assertEquals(round((float) $item->subtotal / 2, 2), $partial['lines'][0]['original_subtotal_share']);
        $this->assertTrue($partial['folio_mapping_complete']);
        $this->assertNull($partial['refund_amount']);
        $this->assertFalse($partial['issuance_enabled']);
        $this->assertSame('full_remaining', $policy->review($stay, $owner, (int) $bill->id)['kind']);
        $this->assertSame($beforeBill, $bill->fresh()->getAttributes());
        $this->assertSame($beforeFolio, HotelFolioEntry::where('stay_id', $stay->id)->get()->toArray());
        $this->assertSame('checked_in', $stay->fresh()->status);
        $this->assertDatabaseMissing('pos_transactions', ['parent_transaction_id' => $bill->id]);
    }

    public function test_foreign_actor_cannot_review_bill(): void
    {
        [$stay, $owner, $bill] = $this->fixture();
        $foreign = $this->owner($this->company('hotel'));
        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        app(HotelCreditNotePolicy::class)->review($stay, $foreign, (int) $bill->id);
    }

    public function test_over_quantity_is_rejected(): void
    {
        [$stay, $owner, $bill] = $this->fixture();
        $item = $bill->items->first();
        $this->expectException(HotelStayException::class);
        app(HotelCreditNotePolicy::class)->review($stay, $owner, (int) $bill->id, [$item->id => (float) $item->quantity + 1]);
    }

    public function test_unconfirmed_original_cannot_be_treated_as_reported(): void
    {
        [$stay, $owner, $bill] = $this->fixture();
        $bill->update(['pra_status' => 'offline']);
        $this->expectException(HotelStayException::class);
        app(HotelCreditNotePolicy::class)->review($stay, $owner, (int) $bill->id);
    }

    public function test_existing_failed_credit_must_be_resolved_first(): void
    {
        [$stay, $owner, $bill] = $this->fixture();
        $child = $bill->replicate();
        $child->invoice_number = 'TEST-CREDIT-PENDING';
        $child->parent_transaction_id = $bill->id;
        $child->transaction_type = 'return';
        $child->pra_status = 'failed';
        $child->pra_invoice_number = null;
        $child->save();
        $this->expectException(HotelStayException::class);
        app(HotelCreditNotePolicy::class)->review($stay, $owner, (int) $bill->id);
    }
    public function test_legacy_mapping_is_review_only_without_guessing_by_name(): void
    {
        [$stay, $owner, $bill] = $this->fixture();
        $bill->items->each(fn ($item) => $item->update(['hotel_folio_entry_id' => null]));
        $plan = app(HotelCreditNotePolicy::class)->review($stay, $owner, (int) $bill->id);
        $this->assertFalse($plan['folio_mapping_complete']);
        $this->assertFalse($plan['issuance_enabled']);
        $this->assertDatabaseMissing('pos_transactions', ['parent_transaction_id' => $bill->id]);
    }

    public function test_partial_credit_is_idempotent_and_refund_is_a_separate_accepted_action(): void
    {
        [$stay, $owner, $bill] = $this->fixture();
        config(['hotel_credit_notes.enabled' => true]);
        $owner->update(['pra_reporting_enabled' => true]);
        $item = $bill->items->first();
        $selection = [$item->id => (float) $item->quantity / 2];
        $plan = app(HotelCreditNotePolicy::class)->review($stay, $owner, (int) $bill->id, $selection);
        $service = app(\App\Services\HotelCreditNoteService::class);
        $key = 'synthetic-credit-request-001';
        $note = $service->issue($stay, $owner, (int) $bill->id, $selection, 'Unused synthetic night', $key, $plan['fingerprint']);
        $same = $service->issue($stay, $owner, (int) $bill->id, $selection, 'Unused synthetic night', $key, $plan['fingerprint']);
        $this->assertSame($note->id, $same->id);
        $credit = PosTransaction::findOrFail($note->credit_transaction_id);
        $this->assertEquals(1000, $credit->total_amount);
        $this->assertSame('hotel_credit_note', $credit->payment_method);
        $this->assertSame('checked_in', $stay->fresh()->status);
        $this->assertEquals(0, app(HotelFolioService::class)->totals($stay)['refunds']);
        $this->assertSame(['cash' => 0.0, 'card' => 0.0, 'other' => 0.0], \App\Support\PosPaymentBuckets::split(collect([$credit])));
        $credit->update(['pra_status' => 'submitted', 'pra_invoice_number' => 'TEST-CREDIT-ACCEPTED']);
        $refund = $service->refund($stay, $owner, (int) $note->id, 500, 'cash', 'synthetic-refund-request-001');
        $again = $service->refund($stay, $owner, (int) $note->id, 500, 'cash', 'synthetic-refund-request-001');
        $this->assertSame($refund->id, $again->id);
        $this->assertLessThanOrEqual(64, strlen($refund->idempotency_key));
        $this->assertEquals(500, app(HotelFolioService::class)->totals($stay)['refunds']);
        $this->assertEquals(500, app(\App\Services\HotelFolioInvoiceService::class)->availableTowardFiscal($stay));
        $this->assertSame('checked_in', $stay->fresh()->status);
        $this->expectException(HotelStayException::class);
        $service->refund($stay, $owner, (int) $note->id, 501, 'cash', 'synthetic-refund-request-002');
    }

    public function test_default_issuance_gate_keeps_original_and_money_unchanged(): void
    {
        [$stay, $owner, $bill] = $this->fixture();
        $plan = app(HotelCreditNotePolicy::class)->review($stay, $owner, (int) $bill->id);
        try {
            app(\App\Services\HotelCreditNoteService::class)->issue($stay, $owner, (int) $bill->id, null,
                'Synthetic wrong bill', 'synthetic-credit-request-003', $plan['fingerprint']);
            $this->fail('Unverified Hotel issuance must remain closed.');
        } catch (HotelStayException $e) {
            $this->assertDatabaseMissing('pos_transactions', ['parent_transaction_id' => $bill->id]);
            $this->assertSame('checked_in', $stay->fresh()->status);
            $this->assertEquals(0, app(HotelFolioService::class)->totals($stay)['refunds']);
        }
    }

    public function test_owner_review_http_screen_and_cashier_gate(): void
    {
        [$stay, $owner, $bill] = $this->fixture();
        $this->actingAs($owner, 'pos')->get('/pos/hotel/stays/'.$stay->id.'/credit-notes')
            ->assertOk()->assertSee('data-hotel-credit-notes', false);
        $this->post('/pos/hotel/stays/'.$stay->id.'/credit-notes/'.$bill->id.'/review', ['mode' => 'full'])
            ->assertOk()->assertSee('data-hotel-credit-review', false)->assertDontSee('name="confirmed"', false);
        $owner->update(['pos_role' => 'pos_cashier', 'role' => 'company_user']);
        $this->get('/pos/hotel/stays/'.$stay->id.'/credit-notes')->assertForbidden();
    }

    public function test_full_credit_preserves_original_bill_and_never_cancels_the_stay(): void
    {
        [$stay, $owner, $bill] = $this->fixture();
        config(['hotel_credit_notes.enabled' => true]);
        $owner->update(['pra_reporting_enabled' => true]);
        $before = $bill->getAttributes();
        $policy = app(HotelCreditNotePolicy::class);
        $service = app(\App\Services\HotelCreditNoteService::class);
        $plan = $policy->review($stay, $owner, (int) $bill->id);
        $note = $service->issue($stay, $owner, (int) $bill->id, null, 'Synthetic full adjustment',
            'synthetic-full-credit-001', $plan['fingerprint']);
        $credit = PosTransaction::findOrFail($note->credit_transaction_id);
        $this->assertEquals($bill->total_amount, $credit->total_amount);
        $this->assertSame($before, $bill->fresh()->getAttributes());
        $this->assertSame('checked_in', $stay->fresh()->status);
        $this->assertEquals(0, app(HotelFolioService::class)->totals($stay)['charges']);
        $this->assertEquals(0, app(HotelFolioService::class)->totals($stay)['refunds']);
        $this->expectException(HotelStayException::class);
        $service->refund($stay, $owner, (int) $note->id, 1, 'cash', 'pending-credit-refund-001');
    }

    public function test_changed_preview_cannot_issue_and_leaves_no_return(): void
    {
        [$stay, $owner, $bill] = $this->fixture();
        config(['hotel_credit_notes.enabled' => true]);
        $owner->update(['pra_reporting_enabled' => true]);
        $plan = app(HotelCreditNotePolicy::class)->review($stay, $owner, (int) $bill->id);
        $bill->update(['notes' => 'Changed after review']);
        try {
            app(\App\Services\HotelCreditNoteService::class)->issue($stay, $owner, (int) $bill->id, null,
                'Synthetic stale preview', 'synthetic-stale-credit-001', $plan['fingerprint']);
            $this->fail('Stale review must not issue.');
        } catch (HotelStayException $e) {
            $this->assertDatabaseMissing('pos_transactions', ['parent_transaction_id' => $bill->id]);
            $this->assertEquals(0, $bill->items->first()->fresh()->returned_quantity);
        }
    }

    public function test_credit_payload_references_original_and_keeps_original_tax_snapshot(): void
    {
        [$stay, $owner, $bill] = $this->fixture(['pos_tax_rate_cash' => 16]);
        config(['hotel_credit_notes.enabled' => true]);
        $owner->update(['pra_reporting_enabled' => true]);
        $company = Company::findOrFail($stay->company_id);
        $company->update(['pos_tax_rate_cash' => 20]);
        $item = $bill->items->first();
        $selection = [$item->id => 1.0];
        $plan = app(HotelCreditNotePolicy::class)->review($stay, $owner, (int) $bill->id, $selection);
        $note = app(\App\Services\HotelCreditNoteService::class)->issue($stay, $owner, (int) $bill->id,
            $selection, 'Synthetic unused night', 'synthetic-tax-credit-001', $plan['fingerprint']);
        $credit = PosTransaction::findOrFail($note->credit_transaction_id);
        $this->assertEquals(16, $credit->tax_rate);
        $this->assertEquals(160, $credit->tax_amount);
        $this->assertEquals(1160, $credit->total_amount);
        $payload = (new \App\Services\PraIntegrationService($company))->generatePayload($credit);
        $this->assertSame(3, $payload['InvoiceType']);
        $this->assertSame($bill->invoice_number, $payload['RefUSIN']);
        $this->assertEquals(16, $payload['Items'][0]['TaxRate']);
        $this->assertGreaterThan(0, $payload['TotalBillAmount']);
        $this->assertEquals(0, app(HotelFolioService::class)->totals($stay)['refunds']);
    }

    public function test_card_save_credit_uses_original_menu_and_card_rates(): void
    {
        [$stay, $owner, $bill] = $this->fixture(['pos_tax_pricing_mode' => 'inclusive_card_save',
            'pos_tax_rate_cash' => 16, 'pos_tax_rate_card' => 8], 'card');
        config(['hotel_credit_notes.enabled' => true]);
        $owner->update(['pra_reporting_enabled' => true]);
        $item = $bill->items->first();
        $selection = [$item->id => 1.0];
        $plan = app(HotelCreditNotePolicy::class)->review($stay, $owner, (int) $bill->id, $selection);
        $note = app(\App\Services\HotelCreditNoteService::class)->issue($stay, $owner, (int) $bill->id,
            $selection, 'Synthetic card save credit', 'synthetic-card-save-001', $plan['fingerprint']);
        $credit = PosTransaction::findOrFail($note->credit_transaction_id);
        $this->assertTrue((bool) $credit->tax_inclusive);
        $this->assertEquals(16, $credit->tax_menu_rate);
        $this->assertEquals(8, $credit->tax_rate);
        $this->assertEquals(931, $credit->total_amount);
        $this->assertEquals($plan['estimated_total'], $credit->total_amount);
        $payload = (new \App\Services\PraIntegrationService(Company::findOrFail($stay->company_id)))->generatePayload($credit);
        $this->assertEquals($credit->total_amount, $payload['TotalBillAmount']);
        $this->assertSame(2, $payload['PaymentMode']);
    }

    public function test_item_discount_credit_payload_uses_original_net_line(): void
    {
        [$stay, $owner, $bill] = $this->fixture();
        config(['hotel_credit_notes.enabled' => true]);
        $owner->update(['pra_reporting_enabled' => true]);
        $item = $bill->items->first();
        $item->update(['subtotal' => 1750, 'item_discount_amount' => 250]);
        $bill->update(['subtotal' => 1750, 'total_amount' => 1750]);
        HotelFolioEntry::findOrFail($item->hotel_folio_entry_id)->update(['amount' => 1750, 'gross_amount' => 2000, 'discount_amount' => 250]);
        $selection = [$item->id => 1.0];
        $plan = app(HotelCreditNotePolicy::class)->review($stay, $owner, (int) $bill->id, $selection);
        $note = app(\App\Services\HotelCreditNoteService::class)->issue($stay, $owner, (int) $bill->id,
            $selection, 'Synthetic discounted night', 'synthetic-item-discount-001', $plan['fingerprint']);
        $credit = PosTransaction::findOrFail($note->credit_transaction_id);
        $payload = (new \App\Services\PraIntegrationService(Company::findOrFail($stay->company_id)))->generatePayload($credit);
        $this->assertEquals(875, $credit->total_amount);
        $this->assertEquals(875, $payload['TotalBillAmount']);
        $this->assertEquals(875, $payload['Items'][0]['SaleValue']);
        $this->assertEquals(0, app(HotelFolioService::class)->totals($stay)['refunds']);
    }

    private function company(string $category, array $overrides = []): Company
    {
        $defaults = PosFeatureService::defaultsForCategory($category);
        $flags = $overrides['feature_flags'] ?? $defaults;
        unset($overrides['feature_flags']);

        return Company::create(array_merge([
            'name' => 'Hotel Test ' . $category,
            'ntn' => (string) random_int(100000000, 999999999),
            'email' => uniqid('hotel-', true) . '@test.pk',
            'status' => 'approved',
            'company_status' => 'active',
            'product_type' => 'pos',
            'pos_integration_mode' => 'pra',
            'business_category' => $category,
            'feature_flags' => $flags,
            'restaurant_mode' => PosFeatureService::restaurantModeFrom($flags),
            'is_internal_account' => true,
            'pos_setup_completed' => true,
        ], $overrides));
    }

    private function owner(Company $company): User
    {
        return User::create([
            'name' => 'Hotel Owner',
            'email' => uniqid('hotel-owner-', true) . '@test.pk',
            'password' => Hash::make('Secret@12345'),
            'company_id' => $company->id,
            'role' => 'company_admin',
            'pos_role' => 'pos_admin',
            'is_active' => true,
        ]);
    }

    private function room(HotelStayService $stays, Company $company, string $number = '101', float $rate = 5000): \App\Models\HotelRoom
    {
        return $stays->createRoom((int) $company->id, [
            'room_number' => $number,
            'room_type' => 'Deluxe',
            'capacity' => 2,
            'rate_amount' => $rate,
            'rate_unit' => 'NGT',
            'branch_id' => null,
        ]);
    }

}
