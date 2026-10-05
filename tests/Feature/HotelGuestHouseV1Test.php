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

/**
 * Hotel / Guest House V1 — accommodation-first workflow.
 *
 * Persistence cases use RefreshDatabase (sqlite :memory:). Contract cases
 * do not need a driver.
 */
class HotelGuestHouseV1Test extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        PosFeatureService::flushGateCaches();
        PosFeatureService::assumeExtrasColumn(true);
    }

    protected function tearDown(): void
    {
        PosFeatureService::assumeExtrasColumn(null);
        PosFeatureService::flushGateCaches();
        parent::tearDown();
    }

    protected function beforeRefreshingDatabase()
    {
        if (!in_array('sqlite', \PDO::getAvailableDrivers(), true)) {
            $this->markTestSkipped('pdo_sqlite is required for these tests (phpunit.xml uses sqlite :memory:).');
        }
    }

    private function previewStay(bool $reporting = false): array
    {
        $company = $this->company('hotel', ['pra_reporting_enabled' => false, 'pra_connection_mode' => 'fiscal_device']);
        $owner = $this->owner($company);
        $owner->forceFill(['pra_reporting_enabled' => $reporting])->save();
        $service = app(HotelStayService::class);
        $room = $this->room($service, $company, 'P-101', 1000);
        $stay = $service->book((int) $company->id, (int) $owner->id, [
            'room_id' => $room->id, 'check_in_date' => now()->toDateString(),
            'check_out_date' => now()->addDay()->toDateString(), 'guest_name' => 'Preview Guest',
            'rate_amount' => 1000, 'discount_type' => 'amount', 'discount_value' => 100, 'walk_in' => true,
        ]);
        $total = app(\App\Services\HotelDeskService::class)->summary($stay, 'cash')['total'];
        app(HotelFolioService::class)->postPayment($stay, ['amount' => $total, 'payment_method' => 'cash', 'idempotency_key' => 'preview-pay'], (int) $owner->id);
        return [$company, $owner, $stay];
    }

    public function test_bill_preview_is_read_only_and_confirmation_is_idempotent(): void
    {
        [$company, $owner, $stay] = $this->previewStay();
        $data = ['flow' => 'settle', 'payment_method' => 'cash'];
        $url = route('pos.hotel.bill-preview', $stay->id).'?'.http_build_query($data);
        $count = HotelFolioEntry::count();
        $preview = $this->actingAs($owner, 'pos')->getJson($url)->assertOk()->json();
        $this->assertSame(100.0, (float) $preview['discount']);
        $this->assertFalse($preview['reporting']);
        $this->assertDatabaseCount('pos_transactions', 0);
        $this->assertSame($count, HotelFolioEntry::count());
        $this->get(route('pos.hotel.stays.show', $stay->id))->assertOk()->assertSee('data-hotel-confirm-flow="settle"', false);
        $payload = $data + ['preview_token' => $preview['preview_token'], 'idempotency_key' => (string) \Illuminate\Support\Str::uuid()];
        $result = $this->postJson(route('pos.hotel.bill-confirm', $stay->id), $payload)->assertOk()->assertJsonPath('status', 'local')->assertJsonPath('fiscal_number', null)->json();
        $this->postJson(route('pos.hotel.bill-confirm', $stay->id), $payload)->assertOk()->assertJsonPath('bill_id', $result['bill_id']);
        $this->assertDatabaseCount('pos_transactions', 1);
        $this->assertSame($count, HotelFolioEntry::count());
        $this->assertNull(\App\Models\PosTransaction::first()->pra_status);
        $this->assertStringStartsWith('L', \App\Models\PosTransaction::first()->invoice_number);
        $this->getJson($result['status_url'])->assertOk()->assertJsonPath('status', 'local')->assertJsonPath('qr', null);
    }

    public function test_bill_preview_rejects_changed_reporting_folio_tampering_and_foreign_tenant(): void
    {
        [$company, $owner, $stay] = $this->previewStay();
        $data = ['flow' => 'settle', 'payment_method' => 'cash'];
        $url = route('pos.hotel.bill-preview', $stay->id).'?'.http_build_query($data);
        $preview = $this->actingAs($owner, 'pos')->getJson($url)->assertOk()->json();
        $payload = $data + ['preview_token' => $preview['preview_token'], 'idempotency_key' => (string) \Illuminate\Support\Str::uuid()];
        $owner->forceFill(['pra_reporting_enabled' => true])->save();
        $this->postJson(route('pos.hotel.bill-confirm', $stay->id), $payload)->assertStatus(409);
        $owner->forceFill(['pra_reporting_enabled' => false])->save();
        HotelFolioEntry::where('stay_id', $stay->id)->where('entry_type', 'charge')->update(['description' => 'Changed charge']);
        $this->postJson(route('pos.hotel.bill-confirm', $stay->id), $payload)->assertStatus(409);
        $payload['preview_token'] = 'tampered';
        $this->postJson(route('pos.hotel.bill-confirm', $stay->id), $payload)->assertStatus(409);
        $this->assertDatabaseCount('pos_transactions', 0);
        $other = $this->company('hotel');
        $this->actingAs($this->owner($other), 'pos')->getJson($url)->assertNotFound();
        $this->postJson(route('pos.hotel.bill-confirm', $stay->id), $payload)->assertNotFound();
    }

    public function test_bill_preview_pra_pending_is_not_accepted_and_checkout_repeats_once(): void
    {
        [$company, $owner, $stay] = $this->previewStay(true);
        $data = ['flow' => 'checkout', 'payment_method' => 'cash', 'amount' => 0];
        $preview = $this->actingAs($owner, 'pos')->getJson(route('pos.hotel.bill-preview', $stay->id).'?'.http_build_query($data))->assertOk()->assertJsonPath('reporting', true)->json();
        $payload = $data + ['preview_token' => $preview['preview_token'], 'idempotency_key' => (string) \Illuminate\Support\Str::uuid()];
        $result = $this->postJson(route('pos.hotel.bill-confirm', $stay->id), $payload)->assertOk()->assertJsonPath('status', 'pending')->assertJsonPath('qr', null)->assertJsonPath('fiscal_number', null)->json();
        $this->postJson(route('pos.hotel.bill-confirm', $stay->id), $payload)->assertOk()->assertJsonPath('bill_id', $result['bill_id']);
        $this->assertDatabaseCount('pos_transactions', 1);
        $this->assertSame('checked_out', $stay->fresh()->status);
        $bill = \App\Models\PosTransaction::findOrFail($result['bill_id']);
        // Fictional acceptance fixture only; no external PRA request.
        $bill->update(['pra_status' => 'submitted', 'pra_invoice_number' => 'SYNTHETIC-PRA-123']);
        $this->getJson($result['status_url'])->assertOk()->assertJsonPath('status', 'submitted')->assertJsonPath('fiscal_number', 'SYNTHETIC-PRA-123')->assertJsonStructure(['qr', 'receipt_url']);
        $bill->update(['pra_status' => 'failed', 'pra_invoice_number' => null]);
        $this->getJson($result['status_url'])->assertOk()->assertJsonPath('status', 'failed')->assertJsonPath('qr', null);
        $other = $this->company('hotel');
        $this->actingAs($this->owner($other), 'pos')->getJson($result['status_url'])->assertNotFound();
    }

    public function test_one_dialog_collection_and_already_billed_checkout_are_idempotent(): void
    {
        [$company, $owner, $stay] = $this->previewStay();
        HotelFolioEntry::where('stay_id', $stay->id)->where('entry_type', 'payment')->delete();
        $this->actingAs($owner, 'pos');
        $data = ['flow' => 'collect', 'payment_method' => 'cash', 'amount' => 100];
        $quote = $this->getJson(route('pos.hotel.bill-preview', $stay->id).'?'.http_build_query($data))->assertOk()->assertJsonPath('will_issue', false)->json();
        $payload = $data + ['preview_token' => $quote['preview_token'], 'idempotency_key' => (string) \Illuminate\Support\Str::uuid()];
        $this->assertDatabaseCount('pos_transactions', 0);
        $this->postJson(route('pos.hotel.bill-confirm', $stay->id), $payload)->assertOk()->assertJsonPath('bill_id', null);
        $this->postJson(route('pos.hotel.bill-confirm', $stay->id), $payload)->assertOk()->assertJsonPath('bill_id', null);
        $this->assertEquals(100, HotelFolioEntry::where('stay_id', $stay->id)->where('entry_type', 'payment')->sum('amount'));
        $this->assertSame('checked_in', $stay->fresh()->status);
        $changed = $payload; $changed['amount'] = 101;
        $this->postJson(route('pos.hotel.bill-confirm', $stay->id), $changed)->assertStatus(409);
        $data['amount'] = app(\App\Services\HotelDeskService::class)->summary($stay, 'cash')['balance'];
        $quote = $this->getJson(route('pos.hotel.bill-preview', $stay->id).'?'.http_build_query($data))->assertOk()->assertJsonPath('will_issue', true)->assertJsonPath('credit_note_url', route('pos.hotel.credit-notes', $stay->id))->json();
        $payload = $data + ['preview_token' => $quote['preview_token'], 'idempotency_key' => (string) \Illuminate\Support\Str::uuid()];
        $bill = $this->postJson(route('pos.hotel.bill-confirm', $stay->id), $payload)->assertOk()->json();
        $this->postJson(route('pos.hotel.bill-confirm', $stay->id), $payload)->assertOk()->assertJsonPath('bill_id', $bill['bill_id']);
        $this->assertDatabaseCount('pos_transactions', 1);
        $this->assertSame('checked_in', $stay->fresh()->status);
        $data = ['flow' => 'checkout', 'payment_method' => 'cash', 'amount' => 0];
        $quote = $this->getJson(route('pos.hotel.bill-preview', $stay->id).'?'.http_build_query($data))->assertOk()->json();
        $payload = $data + ['preview_token' => $quote['preview_token'], 'idempotency_key' => (string) \Illuminate\Support\Str::uuid()];
        $this->postJson(route('pos.hotel.bill-confirm', $stay->id), $payload)->assertOk()->assertJsonPath('bill_id', $bill['bill_id'])->assertJsonPath('stay_status', 'checked_out');
        $this->postJson(route('pos.hotel.bill-confirm', $stay->id), $payload)->assertOk()->assertJsonPath('bill_id', $bill['bill_id']);
        $this->assertDatabaseCount('pos_transactions', 1);
    }

    public function test_reported_bill_hides_correction_and_direct_request_stays_blocked(): void
    {
        [$company, $owner, $stay] = $this->previewStay(true);
        $bill = app(HotelFolioService::class)->settleCoveredCharges($stay, (int) $owner->id, 'cash')['transaction'];
        $bill->update(['pra_status' => 'submitted', 'pra_invoice_number' => 'SYNTHETIC-FISCAL-UI']);
        $this->actingAs($owner, 'pos');
        $this->get(route('pos.hotel.folios'))->assertOk()->assertDontSee(route('pos.hotel.stays.correction', $stay->id), false);
        $this->get(route('pos.hotel.stays.show', $stay->id))->assertOk()->assertDontSee(route('pos.hotel.stays.correction', $stay->id), false)->assertDontSee('data-hotel-void-error="1"', false);
        $policy = app(\App\Services\HotelCorrectionService::class)->preview($stay, $owner);
        $this->assertNotEmpty($policy['blocked']);
        $this->post(route('pos.hotel.stays.correct', $stay->id), ['reason' => 'Synthetic cancellation attempt',
            'fingerprint' => $policy['fingerprint'], 'payment_method' => 'cash', 'confirmed' => 1])->assertSessionHas('error');
        $this->assertSame('checked_in', $stay->fresh()->status);
        $this->assertDatabaseCount('pos_transactions', 1);
    }

    public function test_full_unpaid_hotel_invoice_and_later_checkout_record_only_actual_money(): void
    {
        $this->travelTo(\Carbon\Carbon::parse('2026-10-05 14:00:00', 'Asia/Karachi'));
        [$company, $owner, $stay] = $this->previewStay();
        HotelFolioEntry::where('stay_id', $stay->id)->where('entry_type', 'payment')->delete();
        $stay->update(['hotel_money_from_folio' => true]);
        $this->actingAs($owner, 'pos');
        $data = ['flow' => 'collect', 'payment_method' => 'cash', 'amount' => 200];
        $preview = $this->getJson(route('pos.hotel.bill-preview', $stay->id).'?'.http_build_query($data))->assertOk()->assertJsonPath('will_issue', true)->json();
        $payload = $data + ['preview_token' => $preview['preview_token'], 'idempotency_key' => (string) \Illuminate\Support\Str::uuid()];
        $billId = $this->postJson(route('pos.hotel.bill-confirm', $stay->id), $payload)->assertOk()->assertJsonPath('credit_note_url', route('pos.hotel.credit-notes', $stay->id))->json('bill_id');
        $bill = \App\Models\PosTransaction::findOrFail($billId);
        $this->assertGreaterThan(200, (float) $bill->total_amount);
        $this->assertTrue($bill->hotel_money_from_folio);
        $this->assertEquals(0, \App\Support\PosPaymentBuckets::split(collect([$bill]))['cash']);
        $this->assertEquals(-200, \App\Services\HotelCreditNoteRefundReporting::buckets($company->id, '2026-10-05')['cash']);
        $this->assertSame('hotel_credit_required', \App\Services\PosReturnService::returnableReason($bill));
        $this->postJson(route('pos.hotel.bill-confirm', $stay->id), $payload)->assertOk()->assertJsonPath('bill_id', $billId);
        $this->assertDatabaseCount('pos_transactions', 1);
        $this->travelTo(\Carbon\Carbon::parse('2026-10-06 14:00:00', 'Asia/Karachi'));
        $balance = app(\App\Services\HotelDeskService::class)->summary($stay->fresh(), 'card')['balance'];
        $this->assertEquals((float) $bill->total_amount - 200, $balance);
        $data = ['flow' => 'checkout', 'payment_method' => 'card', 'amount' => $balance];
        $preview = $this->getJson(route('pos.hotel.bill-preview', $stay->id).'?'.http_build_query($data))->assertOk()->assertJsonPath('will_issue', false)->json();
        $payload = $data + ['preview_token' => $preview['preview_token'], 'idempotency_key' => (string) \Illuminate\Support\Str::uuid()];
        $this->postJson(route('pos.hotel.bill-confirm', $stay->id), $payload)->assertOk()->assertJsonPath('bill_id', $billId);
        $this->assertSame('checked_out', $stay->fresh()->status);
        $this->assertDatabaseCount('pos_transactions', 1);
        $money = \App\Services\HotelCreditNoteRefundReporting::buckets($company->id, '2026-10-06');
        $this->assertEquals(-$balance, $money['card']);
        $this->assertEquals(0, $money['cash']);
        $this->assertEquals(0, \App\Services\HotelCreditNoteRefundReporting::buckets($company->id + 999, '2026-10-06')['card']);
        $closed = app(PosController::class)->performDayClose($company->id, '2026-10-06', $owner->id)['report'];
        $this->assertNotNull($closed);
        $this->assertEquals($balance, $closed->card_amount);
        $this->assertEquals(0, $closed->total_amount);
        $this->travelBack();
    }

    public function test_full_invoice_unpaid_tax_remains_due_on_board_and_blocks_direct_checkout(): void
    {
        [$company, $owner, $stay] = $this->previewStay();
        $company->update(['pos_tax_rate_cash' => 16, 'pos_tax_pricing_mode' => 'exclusive', 'hotel_checkout_outstanding' => 'block']);
        HotelFolioEntry::where('stay_id', $stay->id)->where('entry_type', 'payment')->delete();
        $stay->update(['hotel_money_from_folio' => true]);
        app(HotelFolioService::class)->postPayment($stay, ['amount' => 900, 'payment_method' => 'cash'], $owner->id);
        $bill = app(HotelFolioService::class)->settleCoveredCharges($stay, $owner->id, 'cash', 'unpaid-tax-invoice')['transaction'];
        $this->assertEquals(1044, $bill->total_amount);
        $this->assertEquals(144, app(HotelFolioService::class)->totals($stay)['outstanding']);
        $dues = app(HotelFolioService::class)->chargeDuesForStayIds($company->id, [$stay->id]);
        $this->assertEquals(144, $dues[$stay->id]);
        try {
            app(HotelStayService::class)->checkOut($stay, $owner->id);
            $this->fail('Unpaid fiscal tax must prevent direct checkout.');
        } catch (HotelStayException $e) {
            $this->assertSame('checked_in', $stay->fresh()->status);
        }
        app(HotelFolioService::class)->postPayment($stay, ['amount' => 144, 'payment_method' => 'card'], $owner->id);
        $this->actingAs($owner, 'pos');
        $data = ['flow' => 'checkout', 'payment_method' => 'card', 'amount' => 0];
        $preview = $this->getJson(route('pos.hotel.bill-preview', $stay->id).'?'.http_build_query($data))->assertOk()
            ->assertJsonPath('will_issue', false)->assertJsonPath('remaining', fn ($amount) => abs((float) $amount) < 0.009)->json();
        $this->postJson(route('pos.hotel.bill-confirm', $stay->id), $data + ['preview_token' => $preview['preview_token'],
            'idempotency_key' => (string) \Illuminate\Support\Str::uuid()])->assertOk()->assertJsonPath('bill_id', $bill->id);
        $this->assertDatabaseCount('pos_transactions', 1);
        $this->assertSame('checked_out', $stay->fresh()->status);
        $this->assertDatabaseCount('hotel_folio_entries', 3);
    }

    public function test_guest_directory_edit_and_delete_preserve_stay_and_invoice_history(): void
    {
        [$company, $owner, $stay] = $this->previewStay();
        $name = $stay->guest_name;
        $stay->update(['guest_phone' => '03001111111', 'guest_cnic' => '1111111111111']);
        $this->actingAs($owner, 'pos')->get(route('pos.hotel.guests.edit', $stay->id))->assertOk();
        $this->patch(route('pos.hotel.guests.update', $stay->id), ['guest_name' => 'Updated Guest', 'guest_phone' => '03000000000', 'guest_cnic' => '1234567890123'])->assertRedirect(route('pos.hotel.guests'));
        $this->assertSame($name, $stay->fresh()->guest_name);
        $this->get(route('pos.hotel.guests'))->assertOk()->assertSee('Updated Guest');
        $this->get(route('pos.hotel.stays.create'))->assertOk()->assertSee('Updated Guest');
        $this->get(route('pos.hotel.stays.show', $stay->id))->assertOk()->assertSee($name);
        $this->patch(route('pos.hotel.guests.update', $stay->id), ['guest_name' => 'Updated Guest', 'guest_phone' => null, 'guest_cnic' => null])->assertRedirect();
        $this->get(route('pos.hotel.guests.edit', $stay->id))->assertOk()->assertDontSee('03001111111')->assertDontSee('1111111111111');
        $this->assertSame('03001111111', $stay->fresh()->guest_phone);
        $other = $this->company('hotel');
        $this->actingAs($this->owner($other), 'pos')->patchJson(route('pos.hotel.guests.update', $stay->id), ['guest_name' => 'Foreign'])->assertNotFound();
        $this->deleteJson(route('pos.hotel.guests.delete', $stay->id))->assertNotFound();
        $this->actingAs($owner, 'pos')->delete(route('pos.hotel.guests.delete', $stay->id))->assertRedirect(route('pos.hotel.guests'));
        $this->get(route('pos.hotel.guests'))->assertOk()->assertDontSee('Updated Guest');
        $this->assertDatabaseHas('hotel_stays', ['id' => $stay->id, 'guest_name' => $name]);
        $this->assertGreaterThan(0, HotelFolioEntry::where('stay_id', $stay->id)->count());
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

    private function staff(Company $company, string $posRole, ?array $customAccess = null): User
    {
        // pos_custom_access is intentionally not fillable (mass-assignment
        // safety); Team saves use forceFill — tests must too or the set is dropped.
        $user = User::create([
            'name' => 'Hotel Staff ' . $posRole,
            'email' => uniqid('hotel-staff-', true) . '@test.pk',
            'password' => Hash::make('Secret@12345'),
            'company_id' => $company->id,
            'role' => 'staff',
            'pos_role' => $posRole,
            'is_active' => true,
        ]);
        if ($customAccess !== null) {
            $user->forceFill(['pos_custom_access' => json_encode($customAccess)])->save();
        }

        return $user->fresh();
    }

    public function test_new_hotel_preset_is_accommodation_first(): void
    {
        $defaults = PosFeatureService::defaultsForCategory('hotel');
        $this->assertTrue((bool) ($defaults['rooms'] ?? false));
        $this->assertFalse((bool) ($defaults['kitchen'] ?? false));
        $this->assertFalse((bool) ($defaults['tables'] ?? false));
        $this->assertFalse((bool) ($defaults['kot'] ?? false));
        $this->assertFalse(PosFeatureService::restaurantModeFrom($defaults));
    }

    public function test_rooms_switch_off_redirects_hotel_urls(): void
    {
        $company = $this->company('hotel', [
            'feature_flags' => array_merge(PosFeatureService::defaultsForCategory('hotel'), ['rooms' => false]),
        ]);
        $owner = $this->owner($company);
        $this->actingAs($owner, 'pos')->get('/pos/hotel')
            ->assertRedirect('/pos/dashboard');
    }

    public function test_hotel_front_desk_is_reachable_when_rooms_are_on(): void
    {
        $company = $this->company('hotel');
        $owner = $this->owner($company);
        $this->actingAs($owner, 'pos')->get('/pos/hotel')->assertOk()
            ->assertSee(__('pos.hotel_front_desk'), false);
    }

    public function test_stays_are_isolated_by_company(): void
    {
        $a = $this->company('hotel');
        $b = $this->company('hotel');
        $stays = app(HotelStayService::class);
        $roomA = $this->room($stays, $a, '101');
        $this->room($stays, $b, '101');
        $ownerA = $this->owner($a);
        $stay = $stays->book((int) $a->id, (int) $ownerA->id, [
            'room_id' => $roomA->id,
            'check_in_date' => '2026-09-20',
            'check_out_date' => '2026-09-22',
            'guest_name' => 'Ali Khan',
        ]);
        $this->assertNull(HotelStay::where('company_id', $b->id)->where('id', $stay->id)->first());
        // Panel not-found is rendered as a redirect to /pos/dashboard (bootstrap/app.php),
        // not a raw 404 — still must not expose the foreign stay.
        $this->actingAs($this->owner($b), 'pos')
            ->get('/pos/hotel/stays/' . $stay->id)
            ->assertRedirect('/pos/dashboard');
    }

    public function test_overlapping_assignments_on_the_same_room_are_refused(): void
    {
        $company = $this->company('hotel');
        $stays = app(HotelStayService::class);
        $room = $this->room($stays, $company);
        $user = $this->owner($company);
        $stays->book((int) $company->id, (int) $user->id, [
            'room_id' => $room->id,
            'check_in_date' => '2026-09-20',
            'check_out_date' => '2026-09-23',
            'guest_name' => 'First Guest',
        ]);
        $this->expectException(HotelStayException::class);
        $stays->book((int) $company->id, (int) $user->id, [
            'room_id' => $room->id,
            'check_in_date' => '2026-09-22',
            'check_out_date' => '2026-09-24',
            'guest_name' => 'Second Guest',
        ]);
    }

    public function test_folio_advance_is_not_sold_twice_and_deposit_is_not_revenue(): void
    {
        $company = $this->company('hotel', [
            'pos_tax_rate_cash' => 0,
            'pos_tax_rate_card' => 0,
        ]);
        $stays = app(HotelStayService::class);
        $folio = app(HotelFolioService::class);
        $room = $this->room($stays, $company, '201', 4000);
        $user = $this->owner($company);
        $stay = $stays->book((int) $company->id, (int) $user->id, [
            'room_id' => $room->id,
            'check_in_date' => '2026-09-20',
            'check_out_date' => '2026-09-22',
            'guest_name' => 'Bilal Ahmed',
            'walk_in' => true,
        ]);
        $this->assertSame(HotelStay::STATUS_CHECKED_IN, $stay->status);
        $this->assertSame('HS001', $stay->stay_number);

        $folio->postPayment($stay, [
            'amount' => 8000,
            'payment_method' => 'cash',
            'idempotency_key' => 'pay-1',
        ], (int) $user->id);
        $folio->postDeposit($stay, [
            'amount' => 2000,
            'payment_method' => 'cash',
            'idempotency_key' => 'dep-1',
        ], (int) $user->id);
        $folio->postPayment($stay, [
            'amount' => 8000,
            'payment_method' => 'cash',
            'idempotency_key' => 'pay-1',
        ], (int) $user->id);

        $totals = $folio->totals($stay->fresh());
        $this->assertEquals(8000.0, $totals['charges']);
        $this->assertEquals(8000.0, $totals['payments']);
        $this->assertEquals(2000.0, $totals['deposits']);
        $this->assertEquals(0.0, $totals['outstanding']);
        $this->assertEquals(0.0, $totals['advance_credit']);
        $this->assertEquals(2000.0, $totals['deposit_held']);
        $this->assertEquals(8000.0, $totals['uninvoiced_charges']);

        $settled = $folio->settleCoveredCharges($stay->fresh(), (int) $user->id, 'cash', 'inv-1');
        $this->assertNotNull($settled['transaction']);
        $this->assertNotSame($stay->stay_number, $settled['transaction']->invoice_number);
        $this->assertDoesNotMatchRegularExpression('/^HS/i', (string) $settled['transaction']->invoice_number);
        $again = $folio->settleCoveredCharges($stay->fresh(), (int) $user->id, 'cash', 'inv-2');
        $this->assertNull($again['transaction']);

        $after = $folio->totals($stay->fresh());
        $this->assertEquals(8000.0, $after['invoiced']);
        $this->assertEquals(2000.0, $after['deposit_held']);
        $this->assertEquals(0.0, $after['outstanding']);

        $charge = HotelFolioEntry::where('stay_id', $stay->id)->where('entry_type', 'charge')->first();
        $this->expectException(HotelStayException::class);
        $folio->reverseCharge($stay->fresh(), (int) $charge->id, (int) $user->id);
    }

    public function test_exclusive_pos_tax_must_be_collected_before_the_fiscal_bill(): void
    {
        $company = $this->company('hotel', [
            'pos_tax_rate_cash' => 16,
            'pos_tax_rate_card' => 16,
            'pos_tax_inclusive' => false,
            'pos_tax_pricing_mode' => 'exclusive',
        ]);
        $stays = app(HotelStayService::class);
        $folio = app(HotelFolioService::class);
        $room = $this->room($stays, $company, '301', 4000);
        $user = $this->owner($company);
        $stay = $stays->book((int) $company->id, (int) $user->id, [
            'room_id' => $room->id,
            'check_in_date' => '2026-09-20',
            'check_out_date' => '2026-09-22',
            'guest_name' => 'Sana Malik',
            'walk_in' => true,
        ]);
        $folio->postPayment($stay, [
            'amount' => 8000,
            'payment_method' => 'cash',
            'idempotency_key' => 'tax-pay-1',
        ], (int) $user->id);
        $this->assertGreaterThan(0, $folio->totals($stay->fresh())['outstanding']);

        $this->expectException(HotelStayException::class);
        $folio->settleCoveredCharges($stay->fresh(), (int) $user->id, 'cash', 'tax-inv-short');
    }

    public function test_exclusive_tax_bill_issues_when_tax_is_collected(): void
    {
        $company = $this->company('hotel', [
            'pos_tax_rate_cash' => 16,
            'pos_tax_rate_card' => 16,
            'pos_tax_inclusive' => false,
            'pos_tax_pricing_mode' => 'exclusive',
        ]);
        $stays = app(HotelStayService::class);
        $folio = app(HotelFolioService::class);
        $room = $this->room($stays, $company, '302', 4000);
        $user = $this->owner($company);
        $stay = $stays->book((int) $company->id, (int) $user->id, [
            'room_id' => $room->id,
            'check_in_date' => '2026-09-20',
            'check_out_date' => '2026-09-22',
            'guest_name' => 'Omar Sheikh',
            'walk_in' => true,
        ]);
        $folio->postPayment($stay, [
            'amount' => 9280,
            'payment_method' => 'cash',
            'idempotency_key' => 'tax-pay-full',
        ], (int) $user->id);
        $settled = $folio->settleCoveredCharges($stay->fresh(), (int) $user->id, 'cash', 'tax-inv-full');
        $this->assertNotNull($settled['transaction']);
        $this->assertEquals(9280.0, (float) $settled['transaction']->total_amount);
        $this->assertEquals(0.0, $settled['totals']['outstanding']);
        $this->assertDoesNotMatchRegularExpression('/^HS/i', (string) $settled['transaction']->invoice_number);
    }

    public function test_open_stays_are_not_counted_as_restaurant_open_orders(): void
    {
        $company = $this->company('hotel');
        $stays = app(HotelStayService::class);
        $room = $this->room($stays, $company);
        $user = $this->owner($company);
        $stays->book((int) $company->id, (int) $user->id, [
            'room_id' => $room->id,
            'check_in_date' => now()->toDateString(),
            'check_out_date' => now()->addDays(2)->toDateString(),
            'guest_name' => 'In House Guest',
            'walk_in' => true,
        ]);
        $this->assertSame(1, HotelStay::where('company_id', $company->id)->where('status', HotelStay::STATUS_CHECKED_IN)->count());

        $method = new \ReflectionMethod(PosController::class, 'pendingRestaurantOrderCounts');
        $method->setAccessible(true);
        [$open] = $method->invoke(new PosController(), (int) $company->id, true);
        $this->assertSame(0, $open);
    }

    public function test_board_lists_available_dirty_pending_and_dues(): void
    {
        $company = $this->company('hotel', [
            'pos_tax_rate_cash' => 0,
            'pos_tax_rate_card' => 0,
        ]);
        $stays = app(HotelStayService::class);
        $folio = app(HotelFolioService::class);
        $user = $this->owner($company);
        $roomA = $this->room($stays, $company, '401', 5000);
        $roomB = $this->room($stays, $company, '402', 5000);
        $stays->setHousekeeping($roomB, 'dirty');
        $stay = $stays->book((int) $company->id, (int) $user->id, [
            'room_id' => $roomA->id,
            'check_in_date' => now()->toDateString(),
            'check_out_date' => now()->addDays(2)->toDateString(),
            'guest_name' => 'Due Guest',
            'walk_in' => true,
        ]);
        $board = $stays->board((int) $company->id, null);
        $this->assertTrue($board['available']->contains('id', $roomB->id) || $board['dirty']->contains('id', $roomB->id));
        $this->assertTrue($board['dirty']->contains('id', $roomB->id));
        $this->assertTrue($board['inHouse']->contains('id', $stay->id));
        $this->assertGreaterThan(0, $board['dues'][$stay->id] ?? 0);
        $this->assertTrue($board['pending']->contains('id', $stay->id));
        $folio->postPayment($stay, [
            'amount' => 10000,
            'payment_method' => 'cash',
            'idempotency_key' => 'board-pay',
        ], (int) $user->id);
        $board2 = $stays->board((int) $company->id, null);
        $this->assertFalse($board2['pending']->contains('id', $stay->id));
    }

    public function test_housekeeping_only_staff_cannot_open_front_desk(): void
    {
        $company = $this->company('hotel');
        $hk = $this->staff($company, 'pos_cashier', ['dashboard', 'hotel_housekeeping']);
        $this->actingAs($hk, 'pos')->get('/pos/hotel')->assertRedirect();
        $this->actingAs($hk, 'pos')->get('/pos/hotel/rooms')->assertOk();
    }

    public function test_cashier_without_hotel_grant_is_blocked(): void
    {
        $company = $this->company('hotel');
        $cashier = $this->staff($company, 'pos_cashier', ['dashboard', 'orders']);
        $this->actingAs($cashier, 'pos')->get('/pos/hotel')->assertRedirect();
        $this->actingAs($cashier, 'pos')->get('/pos/hotel/rooms')->assertRedirect();
    }

    public function test_branch_scoped_stay_url_is_not_found_on_other_branch(): void
    {
        if (!\Illuminate\Support\Facades\Schema::hasTable('branches')) {
            $this->markTestSkipped('branches table required');
        }
        $company = $this->company('hotel');
        $stays = app(HotelStayService::class);
        $user = $this->owner($company);
        $branchA = \App\Models\Branch::create([
            'company_id' => $company->id,
            'name' => 'Tower A',
            'code' => 'A',
            'is_active' => true,
        ]);
        $branchB = \App\Models\Branch::create([
            'company_id' => $company->id,
            'name' => 'Tower B',
            'code' => 'B',
            'is_active' => true,
        ]);
        $room = $stays->createRoom((int) $company->id, [
            'room_number' => '501',
            'room_type' => 'Deluxe',
            'capacity' => 2,
            'rate_amount' => 4000,
            'rate_unit' => 'NGT',
            'branch_id' => $branchA->id,
        ]);
        $stay = $stays->book((int) $company->id, (int) $user->id, [
            'room_id' => $room->id,
            'check_in_date' => '2026-09-20',
            'check_out_date' => '2026-09-22',
            'guest_name' => 'Branch Guest',
        ]);
        session(['active_branch_id' => $branchB->id]);
        $this->actingAs($user, 'pos')
            ->withSession(['active_branch_id' => $branchB->id])
            ->get('/pos/hotel/stays/' . $stay->id)
            ->assertRedirect('/pos/dashboard');
        session(['active_branch_id' => $branchA->id]);
        $this->actingAs($user, 'pos')
            ->withSession(['active_branch_id' => $branchA->id])
            ->get('/pos/hotel/stays/' . $stay->id)
            ->assertOk();
    }

    public function test_occupancy_is_hidden_without_hotel_or_housekeeping_access(): void
    {
        $company = $this->company('hotel');
        $stays = app(HotelStayService::class);
        $this->room($stays, $company, '101');
        $denied = $this->staff($company, 'pos_cashier', ['dashboard', 'orders', 'day_close']);
        $hk = $this->staff($company, 'pos_cashier', ['dashboard', 'hotel_housekeeping', 'day_close']);
        $owner = $this->owner($company);
        $label = __('pos.hotel_stat_in_house');

        $this->assertNull($stays->occupancyForViewer($denied, $company));
        $this->assertNotNull($stays->occupancyForViewer($hk, $company));
        $this->assertNotNull($stays->occupancyForViewer($owner, $company));

        $this->actingAs($denied, 'pos')->get('/pos/dashboard')
            ->assertOk()
            ->assertDontSee($label, false);
        // Hotel native shell: Manage/login home is Front Desk / Housekeeping,
        // not the generic POS dashboard — occupancy lives on Hotel screens.
        $this->actingAs($hk, 'pos')->get('/pos/dashboard')
            ->assertRedirect('/pos/hotel/housekeeping');
        $this->actingAs($hk, 'pos')->get('/pos/hotel/housekeeping')
            ->assertOk();
        $this->actingAs($owner, 'pos')->get('/pos/dashboard')
            ->assertRedirect('/pos/hotel');
        $this->actingAs($owner, 'pos')->get('/pos/hotel')
            ->assertOk()
            ->assertSee(__('pos.hotel_in_house'), false);

        $this->actingAs($denied, 'pos')->get('/pos/day-close')
            ->assertOk()
            ->assertDontSee($label, false);
        $this->actingAs($hk, 'pos')->get('/pos/day-close')
            ->assertOk()
            ->assertSee($label, false);
        $this->actingAs($owner, 'pos')->get('/pos/day-close')
            ->assertOk()
            ->assertSee($label, false);
    }

    public function test_restaurant_dashboard_hides_occupancy_without_hotel_access(): void
    {
        $flags = PosFeatureService::defaultsForCategory('restaurant');
        $flags['rooms'] = true;
        $company = $this->company('restaurant', ['feature_flags' => $flags]);
        $stays = app(HotelStayService::class);
        $this->room($stays, $company, '101');
        $denied = $this->staff($company, 'pos_cashier', ['dashboard', 'orders', 'day_close']);
        $owner = $this->owner($company);
        $label = __('pos.hotel_stat_in_house');

        $this->assertNull($stays->occupancyForViewer($denied, $company));
        $this->assertNotNull($stays->occupancyForViewer($owner, $company));

        // Restaurant dashboard is owner/manager-only; cashiers never see it.
        $this->actingAs($denied, 'pos')->get('/pos/restaurant/dashboard')
            ->assertRedirect('/pos/invoice/create');
        $this->actingAs($owner, 'pos')->get('/pos/restaurant/dashboard')
            ->assertOk()
            ->assertSee($label, false);
    }

    public function test_stay_cannot_move_to_another_branch_room(): void
    {
        if (!\Illuminate\Support\Facades\Schema::hasTable('branches')) {
            $this->markTestSkipped('branches table required');
        }
        $company = $this->company('hotel');
        $stays = app(HotelStayService::class);
        $user = $this->owner($company);
        $branchA = \App\Models\Branch::create([
            'company_id' => $company->id,
            'name' => 'Tower A',
            'code' => 'TA',
            'is_active' => true,
        ]);
        $branchB = \App\Models\Branch::create([
            'company_id' => $company->id,
            'name' => 'Tower B',
            'code' => 'TB',
            'is_active' => true,
        ]);
        $roomA = $stays->createRoom((int) $company->id, [
            'room_number' => '101',
            'room_type' => 'Deluxe',
            'capacity' => 2,
            'rate_amount' => 4000,
            'rate_unit' => 'NGT',
            'branch_id' => $branchA->id,
        ]);
        $roomB = $stays->createRoom((int) $company->id, [
            'room_number' => '202',
            'room_type' => 'Deluxe',
            'capacity' => 2,
            'rate_amount' => 4000,
            'rate_unit' => 'NGT',
            'branch_id' => $branchB->id,
        ]);
        $stay = $stays->book((int) $company->id, (int) $user->id, [
            'room_id' => $roomA->id,
            'check_in_date' => '2026-09-20',
            'check_out_date' => '2026-09-22',
            'guest_name' => 'Cross Branch',
        ]);
        $this->expectException(HotelStayException::class);
        $this->expectExceptionMessage(__('pos.hotel_room_other_branch'));
        $stays->moveRoom($stay, (int) $roomB->id, (int) $user->id);
    }

    public function test_checked_in_room_move_marks_previous_room_dirty(): void
    {
        $company = $this->company('hotel');
        $stays = app(HotelStayService::class);
        $user = $this->owner($company);
        $from = $this->room($stays, $company, '101', 4000);
        $to = $this->room($stays, $company, '102', 4000);
        $stay = $stays->book((int) $company->id, (int) $user->id, [
            'room_id' => $from->id,
            'check_in_date' => now()->toDateString(),
            'check_out_date' => now()->addDays(2)->toDateString(),
            'guest_name' => 'Mover',
            'walk_in' => true,
        ]);
        $this->assertSame('clean', $from->fresh()->housekeeping);
        $stays->moveRoom($stay, (int) $to->id, (int) $user->id);
        $this->assertSame('dirty', $from->fresh()->housekeeping);
        $this->assertSame((int) $to->id, (int) $stay->fresh()->room_id);

        $reservedFrom = $this->room($stays, $company, '201', 4000);
        $reservedTo = $this->room($stays, $company, '202', 4000);
        $reserved = $stays->book((int) $company->id, (int) $user->id, [
            'room_id' => $reservedFrom->id,
            'check_in_date' => '2026-10-01',
            'check_out_date' => '2026-10-03',
            'guest_name' => 'Not Yet In',
        ]);
        $stays->moveRoom($reserved, (int) $reservedTo->id, (int) $user->id);
        $this->assertSame('clean', $reservedFrom->fresh()->housekeeping);
    }

    public function test_reselecting_current_room_does_not_duplicate_stay_assignment(): void
    {
        $company = $this->company('hotel');
        $stays = app(HotelStayService::class);
        $user = $this->owner($company);
        $room = $this->room($stays, $company, '101');
        $stay = $stays->book((int) $company->id, (int) $user->id, [
            'room_id' => $room->id,
            'check_in_date' => now()->toDateString(),
            'check_out_date' => now()->addDays(2)->toDateString(),
            'guest_name' => 'Same Room',
            'walk_in' => true,
        ]);

        $page = $this->actingAs($user, 'pos')->get('/pos/hotel/stays/'.$stay->id)->assertOk()->getContent();
        $this->assertStringContainsString('name="room_id" required', $page);
        $this->assertStringNotContainsString('<option value="'.$room->id.'" selected', $page);

        $original = $stay->assignments()->first();
        $stays->moveRoom($stay, (int) $room->id, (int) $user->id);
        $this->assertSame(1, $stay->assignments()->count());
        $this->assertSame($original->to_date->toDateString(), $original->fresh()->to_date->toDateString());
        $this->assertSame('clean', $room->fresh()->housekeeping);
    }

    public function test_overpayment_shows_advance_credit_separate_from_deposit(): void
    {
        $company = $this->company('hotel', [
            'pos_tax_rate_cash' => 0,
            'pos_tax_rate_card' => 0,
        ]);
        $stays = app(HotelStayService::class);
        $folio = app(HotelFolioService::class);
        $room = $this->room($stays, $company, '301', 4000);
        $user = $this->owner($company);
        $stay = $stays->book((int) $company->id, (int) $user->id, [
            'room_id' => $room->id,
            'check_in_date' => '2026-09-20',
            'check_out_date' => '2026-09-22',
            'guest_name' => 'Credit Guest',
            'walk_in' => true,
        ]);
        $folio->postPayment($stay, [
            'amount' => 12000,
            'payment_method' => 'cash',
            'idempotency_key' => 'adv-pay',
        ], (int) $user->id);
        $folio->postDeposit($stay, [
            'amount' => 2000,
            'payment_method' => 'cash',
            'idempotency_key' => 'adv-dep',
        ], (int) $user->id);
        $totals = $folio->totals($stay->fresh());
        $this->assertEquals(8000.0, $totals['charges']);
        $this->assertEquals(0.0, $totals['outstanding']);
        $this->assertEquals(4000.0, $totals['advance_credit']);
        $this->assertEquals(2000.0, $totals['deposit_held']);
        $this->assertNotEquals($totals['advance_credit'], $totals['deposit_held']);

        $html = $this->actingAs($user, 'pos')
            ->get('/pos/hotel/stays/' . $stay->id)
            ->assertOk()
            ->assertSee(__('pos.hotel_folio_advance_credit'), false)
            ->assertSee('Rs 4,000', false)
            ->getContent();
        $this->assertStringContainsString(__('pos.hotel_folio_advance_credit'), $html);
        $this->assertStringContainsString(__('pos.hotel_folio_deposit'), $html);
    }

    public function test_stay_detail_room_list_is_scoped_to_active_branch(): void
    {
        if (!\Illuminate\Support\Facades\Schema::hasTable('branches')) {
            $this->markTestSkipped('branches table required');
        }
        $company = $this->company('hotel');
        $stays = app(HotelStayService::class);
        $user = $this->owner($company);
        $branchA = \App\Models\Branch::create([
            'company_id' => $company->id,
            'name' => 'Tower A',
            'code' => 'SA',
            'is_active' => true,
        ]);
        $branchB = \App\Models\Branch::create([
            'company_id' => $company->id,
            'name' => 'Tower B',
            'code' => 'SB',
            'is_active' => true,
        ]);
        $roomA = $stays->createRoom((int) $company->id, [
            'room_number' => 'ROOM-A-11',
            'room_type' => 'Deluxe',
            'capacity' => 2,
            'rate_amount' => 4000,
            'rate_unit' => 'NGT',
            'branch_id' => $branchA->id,
        ]);
        $stays->createRoom((int) $company->id, [
            'room_number' => 'ROOM-B-99',
            'room_type' => 'Deluxe',
            'capacity' => 2,
            'rate_amount' => 4000,
            'rate_unit' => 'NGT',
            'branch_id' => $branchB->id,
        ]);
        $stay = $stays->book((int) $company->id, (int) $user->id, [
            'room_id' => $roomA->id,
            'check_in_date' => '2026-09-20',
            'check_out_date' => '2026-09-22',
            'guest_name' => 'List Guest',
        ]);
        $this->actingAs($user, 'pos')
            ->withSession(['active_branch_id' => $branchA->id])
            ->get('/pos/hotel/stays/' . $stay->id)
            ->assertOk()
            ->assertSee('ROOM-A-11', false)
            ->assertDontSee('ROOM-B-99', false);
    }

    public function test_owner_correction_reverses_issued_sale_payment_and_deposit_once(): void
    {
        $company = $this->company('hotel', ['pos_tax_rate_cash' => 0, 'pos_tax_rate_card' => 0]);
        $owner = $this->owner($company);
        $stays = app(HotelStayService::class);
        $folio = app(HotelFolioService::class);
        $room = $this->room($stays, $company, 'COR-1', 4000);
        $stay = $stays->book((int) $company->id, (int) $owner->id, [
            'room_id' => $room->id, 'check_in_date' => '2026-10-04', 'check_out_date' => '2026-10-06',
            'guest_name' => 'Synthetic correction guest', 'walk_in' => true,
        ]);
        $folio->postPayment($stay, ['amount' => 8000, 'payment_method' => 'cash'], (int) $owner->id);
        $folio->postDeposit($stay, ['amount' => 2000, 'payment_method' => 'cash'], (int) $owner->id);
        $issued = $folio->settleCoveredCharges($stay, (int) $owner->id, 'cash');
        $this->assertNotNull($issued['transaction']);
        $service = app(\App\Services\HotelCorrectionService::class);
        $plan = $service->preview($stay->fresh(), $owner);
        $this->assertNull($plan['blocked']);
        $this->actingAs($owner, 'pos')->get('/pos/hotel/stays/'.$stay->id.'/correction')->assertOk()->assertSee('data-hotel-correction', false);
        $results = $service->correct($stay, $owner, $plan['fingerprint'], 'Duplicate synthetic entry', 'cash');
        $this->assertCount(1, $results);
        $this->assertSame('return', $results[0]['return']->transaction_type);
        $this->assertEquals(8000, $results[0]['return']->total_amount);
        $this->assertSame($issued['transaction']->id, $results[0]['return']->parent_transaction_id);
        $totals = $folio->totals($stay->fresh());
        $this->assertEquals(0, $totals['charges']);
        $this->assertEquals(0, $totals['invoiced']);
        $this->assertEquals(0, $totals['deposit_held']);
        $this->assertEquals(8000, $totals['refunds']);
        $this->assertSame('cancelled', $stay->fresh()->status);
        $this->assertDatabaseHas('audit_logs', ['action' => 'hotel_stay_corrected', 'entity_id' => $stay->id, 'company_id' => $company->id]);
        try {
            $folio->postPayment($stay, ['amount' => 100, 'payment_method' => 'cash'], (int) $owner->id);
            $this->fail('Cancelled stay accepted new money');
        } catch (HotelStayException $e) {
            $this->assertEquals(8000, $folio->totals($stay)['payments']);
        }
        $count = \App\Models\HotelFolioEntry::where('stay_id', $stay->id)->count();
        try {
            $service->correct($stay, $owner, $plan['fingerprint'], 'Duplicate synthetic entry', 'cash');
            $this->fail('Repeated correction accepted');
        } catch (HotelStayException $e) {
            $this->assertSame($count, \App\Models\HotelFolioEntry::where('stay_id', $stay->id)->count());
        }
    }

    public function test_owner_correction_refuses_stale_preview_and_foreign_actor(): void
    {
        $company = $this->company('hotel'); $owner = $this->owner($company);
        $stays = app(HotelStayService::class); $folio = app(HotelFolioService::class);
        $room = $this->room($stays, $company, 'COR-2', 1000);
        $stay = $stays->book((int) $company->id, (int) $owner->id, [
            'room_id' => $room->id, 'check_in_date' => '2026-10-04', 'check_out_date' => '2026-10-05',
            'guest_name' => 'Synthetic stale guest', 'walk_in' => true,
        ]);
        $service = app(\App\Services\HotelCorrectionService::class);
        $plan = $service->preview($stay->fresh(), $owner);
        $folio->postPayment($stay, ['amount' => 100, 'payment_method' => 'cash'], (int) $owner->id);
        try {
            $service->correct($stay, $owner, $plan['fingerprint'], 'Wrong synthetic entry', 'cash');
            $this->fail('Stale correction accepted');
        } catch (HotelStayException $e) {
            $this->assertSame('checked_in', $stay->fresh()->status);
            $this->assertEquals(0, $folio->totals($stay)['refunds']);
        }
        $foreign = $this->owner($this->company('hotel'));
        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        $service->preview($stay, $foreign);
    }

    public function test_owner_correction_cashier_cannot_use_route_and_prior_adjustment_blocks_preview(): void
    {
        $company = $this->company('hotel'); $owner = $this->owner($company);
        $stays = app(HotelStayService::class); $folio = app(HotelFolioService::class);
        $room = $this->room($stays, $company, 'COR-3', 1000);
        $stay = $stays->book((int) $company->id, (int) $owner->id, [
            'room_id' => $room->id, 'check_in_date' => '2026-10-04', 'check_out_date' => '2026-10-05',
            'guest_name' => 'Synthetic guarded guest', 'walk_in' => true,
        ]);
        $cashier = $this->staff($company, 'pos_cashier');
        $this->actingAs($cashier, 'pos')->get('/pos/hotel/stays/'.$stay->id.'/correction')->assertForbidden();
        $this->actingAs($cashier, 'pos')->post('/pos/hotel/stays/'.$stay->id.'/correction', [])->assertForbidden();
        $charge = \App\Models\HotelFolioEntry::where('stay_id', $stay->id)->where('entry_type', 'charge')->firstOrFail();
        $folio->reverseCharge($stay, (int) $charge->id, (int) $owner->id);
        $plan = app(\App\Services\HotelCorrectionService::class)->preview($stay->fresh(), $owner);
        $this->assertNotNull($plan['blocked']);
    }
    public function test_owner_correction_locks_fiscal_and_ambiguous_bills_without_mutation(): void
    {
        $company = $this->company('hotel', ['pos_tax_rate_cash' => 0]);
        $owner = $this->owner($company);
        $stays = app(HotelStayService::class);
        $folio = app(HotelFolioService::class);
        $service = app(\App\Services\HotelCorrectionService::class);
        foreach (['pending', 'submitted', 'offline', 'failed', null] as $state) {
            $room = $this->room($stays, $company, 'LOCK-'.($state ?? 'legacy'), 1000);
            $stay = $stays->book((int) $company->id, (int) $owner->id, [
                'room_id' => $room->id, 'check_in_date' => '2026-10-04', 'check_out_date' => '2026-10-05',
                'guest_name' => 'Synthetic locked bill', 'walk_in' => true,
            ]);
            $folio->postPayment($stay, ['amount' => 1000, 'payment_method' => 'cash'], (int) $owner->id);
            $bill = $folio->settleCoveredCharges($stay, (int) $owner->id, 'cash')['transaction'];
            $bill->update(['invoice_mode' => 'pra', 'pra_status' => $state, 'invoice_number' => 'P-LOCK-'.($state ?? 'legacy'), 'pra_invoice_number' => $state === 'submitted' ? 'SYNTHETIC-FISCAL' : null]);
            $before = $bill->fresh()->getAttributes();
            $entries = HotelFolioEntry::where('stay_id', $stay->id)->count();
            $plan = $service->preview($stay->fresh(), $owner);
            $this->assertSame(__('hotel_correction.fiscal_locked'), $plan['blocked']);
            $this->actingAs($owner, 'pos')->get('/pos/hotel/stays/'.$stay->id.'/correction')->assertOk()->assertDontSee('name="confirmed"', false);
            try {
                $service->correct($stay, $owner, $plan['fingerprint'], 'Synthetic wrong entry', 'cash');
                $this->fail('Fiscal correction accepted');
            } catch (HotelStayException $e) {
                $this->assertSame(__('hotel_correction.fiscal_locked'), $e->getMessage());
            }
            $this->assertSame($before, $bill->fresh()->getAttributes());
            $this->assertSame($entries, HotelFolioEntry::where('stay_id', $stay->id)->count());
            $this->assertSame('checked_in', $stay->fresh()->status);
            $this->assertDatabaseMissing('pos_transactions', ['parent_transaction_id' => $bill->id]);
        }
    }


    public function test_room_boards_use_natural_sequence_and_keep_other_tenants_out(): void
    {
        $company = $this->company('hotel');
        $stays = app(HotelStayService::class);
        foreach (['10', '2', '1', 'A10', 'A2'] as $number) {
            $this->room($stays, $company, $number, 1000);
        }
        $foreign = $this->company('hotel');
        $this->room($stays, $foreign, '3', 1000);
        $this->assertSame(['1', '2', '10', 'A2', 'A10'], $stays->board((int) $company->id)['available']->pluck('room_number')->all());
        $cards = $stays->roomCards((int) $company->id);
        $this->assertSame(['1', '2', '10', 'A2', 'A10'], array_map(fn ($c) => $c['room']->room_number, $cards));
    }

    public function test_hotel_reporting_header_uses_saved_account_mode_and_existing_toggle(): void
    {
        $company = $this->company('hotel', ['pra_reporting_enabled' => false]);
        $owner = $this->owner($company);
        $owner->update(['pra_reporting_enabled' => false, 'pos_billing_scope' => 'both']);
        $this->actingAs($owner, 'pos')->get('/pos/hotel')->assertOk()
            ->assertSee('data-hotel-pra-status', false)->assertSee('data-hotel-pra-toggle', false);
        $this->postJson('/pos/api/toggle-pra')->assertOk()->assertJson(['success' => true, 'enabled' => true]);
        $this->assertTrue((bool) $owner->fresh()->pra_reporting_enabled);
        $this->assertFalse((bool) $company->fresh()->pra_reporting_enabled);
        $this->get('/pos/customize')->assertOk()->assertSee('data-hotel-tax-cards', false)
            ->assertSee('data-hotel-tax-mode="exclusive"', false)->assertSee('data-hotel-tax-mode="inclusive"', false)
            ->assertSee('data-hotel-tax-mode="inclusive_card_save"', false);
        $this->get('/pos/hotel')->assertOk()->assertSee('data-hotel-pra-status', false);
        $this->postJson('/pos/api/toggle-pra')->assertOk()->assertJson(['success' => true, 'enabled' => false]);
    }

    public function test_hotel_reporting_cashier_and_standalone_are_status_only(): void
    {
        $company = $this->company('hotel');
        $cashier = $this->owner($company);
        $cashier->update(['pos_role' => 'pos_cashier', 'role' => 'company_user']);
        $this->actingAs($cashier, 'pos')->get('/pos/hotel')->assertOk()
            ->assertSee('data-hotel-pra-status', false)->assertDontSee('data-hotel-pra-toggle', false);
        $this->postJson('/pos/api/toggle-pra')->assertForbidden();
        $owner = $this->owner($company);
        $company->update(['pos_integration_mode' => 'standalone']);
        $this->actingAs($owner, 'pos')->get('/pos/hotel')->assertOk()
            ->assertSee('data-hotel-pra-status', false)->assertDontSee('data-hotel-pra-toggle', false);
        $this->postJson('/pos/api/toggle-pra')->assertStatus(422);
    }

}
