<?php

namespace Tests\Feature;

use App\Models\HotelRoom;

use App\Models\Branch;
use App\Models\Company;
use App\Models\HotelStay;
use App\Models\AdminUser;
use App\Models\User;
use App\Services\HotelShell;
use App\Services\HotelStayService;
use App\Services\PosFeatureService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class HotelSimpleDeskPricingTest extends TestCase
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

    private function company(string $category = 'hotel', array $overrides = []): Company
    {
        $defaults = PosFeatureService::defaultsForCategory($category);
        $flags = $overrides['feature_flags'] ?? $defaults;
        unset($overrides['feature_flags']);

        return Company::create(array_merge([
            'name' => 'Hotel Native ' . $category,
            'ntn' => (string) random_int(100000000, 999999999),
            'email' => uniqid('hotel-ui-', true) . '@test.pk',
            'status' => 'approved',
            'company_status' => 'active',
            'product_type' => 'pos',
            'pos_integration_mode' => 'pra',
            'business_category' => $category,
            'feature_flags' => $flags,
            'restaurant_mode' => PosFeatureService::restaurantModeFrom($flags),
            'is_internal_account' => true,
            'pos_setup_completed' => true,
            'pos_tax_rate_cash' => 0,
            'pos_tax_rate_card' => 0,
        ], $overrides));
    }

    private function owner(Company $company): User
    {
        return User::create([
            'name' => 'Hotel Owner',
            'email' => uniqid('hotel-ui-owner-', true) . '@test.pk',
            'password' => Hash::make('Secret@12345'),
            'company_id' => $company->id,
            'role' => 'company_admin',
            'pos_role' => 'pos_admin',
            'is_active' => true,
        ]);
    }

    private function staff(Company $company, string $posRole, ?array $customAccess = null): User
    {
        $user = User::create([
            'name' => 'Hotel Staff',
            'email' => uniqid('hotel-ui-staff-', true) . '@test.pk',
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

    private function fixture(array $overrides = []): array
    {
        $company = $this->company('hotel', $overrides);
        $owner = $this->owner($company);
        $room = app(HotelStayService::class)->createRoom($company->id, ['room_number' => '101', 'rate_amount' => 5000, 'capacity' => 3]);
        return [$company, $owner, $room];
    }

    private function booking($room, array $overrides = []): array
    {
        return array_merge(['room_id' => $room->id, 'guest_name' => 'Simple Desk Guest', 'check_in_date' => now()->toDateString(), 'check_out_date' => now()->addDays(2)->toDateString(), 'walk_in' => true], $overrides);
    }

    public function test_walk_in_keeps_agreed_rate_discount_and_advance_without_changing_room_master(): void
    {
        [$company, $owner, $room] = $this->fixture(['pos_tax_rate_cash' => 16, 'pos_tax_pricing_mode' => 'exclusive']);
        $data = $this->booking($room, ['rate_amount' => 4500, 'discount_type' => 'amount', 'discount_value' => 500, 'advance_amount' => 1000, 'payment_method' => 'cash', 'idempotency_key' => 'walk-in-one']);
        $this->actingAs($owner, 'pos')->post('/pos/hotel/stays', $data)->assertRedirect();
        $stay = HotelStay::where('company_id', $company->id)->firstOrFail();
        $this->assertEquals(4500, $stay->rate_amount);
        $this->assertEquals(5000, $room->fresh()->rate_amount);
        $charge = $stay->folioEntries()->where('entry_type', 'charge')->firstOrFail();
        $this->assertEquals(9000, $charge->gross_amount);
        $this->assertEquals(500, $charge->discount_amount);
        $this->assertEquals(8500, $charge->amount);
        $summary = app(\App\Services\HotelDeskService::class)->summary($stay, 'cash');
        $this->assertEquals(1360, $summary['tax']);
        $this->assertEquals(8860, $summary['balance']);
        $this->post('/pos/hotel/stays', $data)->assertRedirect();
        $this->assertSame(1, HotelStay::where('company_id', $company->id)->count());
        $this->assertSame(1, $stay->folioEntries()->where('entry_type', 'payment')->count());
    }

    public function test_discounted_invoice_uses_line_discount_and_tax_on_net_with_extras_untouched(): void
    {
        [$company, $owner, $room] = $this->fixture(['pos_tax_rate_cash' => 16, 'pos_tax_rate_card' => 5, 'pos_tax_pricing_mode' => 'exclusive']);
        $stay = app(HotelStayService::class)->book($company->id, $owner->id, $this->booking($room, ['rate_amount' => 4500, 'discount_type' => 'percentage', 'discount_value' => 10]));
        $folio = app(\App\Services\HotelFolioService::class);
        $folio->postCharge($stay, ['category' => 'food', 'description' => 'Dinner', 'quantity' => 1, 'unit_amount' => 1000], $owner->id);
        $desk = app(\App\Services\HotelDeskService::class);
        $cash = $desk->summary($stay, 'cash');
        $card = $desk->summary($stay, 'card');
        $this->assertEquals(10556, $cash['total']);
        $this->assertEquals(9555, $card['total']);
        $desk->checkout($stay, $owner->id, ['payment_method' => 'card', 'amount' => 9555, 'idempotency_key' => 'checkout-one']);
        $this->assertSame('checked_out', $stay->fresh()->status);
        $txn = \App\Models\PosTransaction::where('company_id', $company->id)->firstOrFail();
        $this->assertEquals(9555, $txn->total_amount);
        $this->assertEquals(455, $txn->tax_amount);
        $this->assertEquals(900, $txn->items->sum('item_discount_amount'));
        $this->assertEquals(4500, $txn->items->first()->unit_price);
        $desk->checkout($stay, $owner->id, ['payment_method' => 'card', 'amount' => 9555, 'idempotency_key' => 'checkout-one']);
        $this->assertSame(1, $stay->folioEntries()->where('entry_type', 'payment')->count());
        $this->assertSame(1, \App\Models\PosTransaction::where('company_id', $company->id)->count());
        $this->assertSame('dirty', $room->fresh()->housekeeping);
    }

    public static function inclusiveModes(): array
    {
        return ['inclusive' => ['inclusive'], 'card save' => ['inclusive_card_save']];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('inclusiveModes')]
    public function test_inclusive_and_card_save_quotes_match_saved_bill(string $mode): void
    {
        // Each mode gets a fresh fixture: SQLite retains the legacy global
        // invoice-number index, unlike the native tenant-scoped migration.
        [$company, $owner, $room] = $this->fixture(['pos_tax_rate_cash' => 16, 'pos_tax_rate_card' => 5, 'pos_tax_pricing_mode' => $mode]);
        $stay = app(HotelStayService::class)->book($company->id, $owner->id, $this->booking($room, ['discount_type' => 'amount', 'discount_value' => 720]));
        $desk = app(\App\Services\HotelDeskService::class);
        $summary = $desk->summary($stay, 'card');
        $this->assertEquals($mode === 'inclusive' ? 9280 : 8400, $summary['total']);
        $desk->checkout($stay, $owner->id, ['payment_method' => 'card', 'amount' => $summary['balance'], 'idempotency_key' => $mode]);
        $txn = \App\Models\PosTransaction::where('company_id', $company->id)->firstOrFail();
        $this->assertEquals($summary['total'], $txn->total_amount);
        $this->assertEquals($summary['tax'], $txn->tax_amount);
    }

    public function test_extension_applies_fixed_discount_once_and_percent_only_to_new_nights(): void
    {
        foreach (['amount' => 500, 'percentage' => 10] as $type => $value) {
            [$company, $owner, $room] = $this->fixture();
            $stay = app(HotelStayService::class)->book($company->id, $owner->id, $this->booking($room, ['discount_type' => $type, 'discount_value' => $value]));
            app(\App\Services\HotelDeskService::class)->changeStay($stay, $owner->id, ['kind' => 'extend', 'rate_amount' => 4500, 'check_out_date' => now()->addDays(3)->toDateString()]);
            $extra = $stay->folioEntries()->where('entry_type', 'charge')->orderByDesc('id')->firstOrFail();
            $this->assertEquals(4500, $extra->gross_amount);
            $this->assertEquals($type === 'amount' ? 0 : 450, $extra->discount_amount);
            $this->assertEquals(4500, $stay->fresh()->rate_amount);
            $this->assertEquals(5000, $room->fresh()->rate_amount);
        }
    }

    public function test_room_move_reprices_only_remaining_unbilled_nights_and_keeps_past_amount(): void
    {
        [$company, $owner, $room] = $this->fixture();
        $otherRoom = app(HotelStayService::class)->createRoom($company->id, ['room_number' => '102', 'rate_amount' => 6000, 'capacity' => 3]);
        $stay = app(HotelStayService::class)->book($company->id, $owner->id, $this->booking($room, ['check_in_date' => now()->subDay()->toDateString(), 'discount_type' => 'amount', 'discount_value' => 600]));
        $desk = app(\App\Services\HotelDeskService::class);
        $data = ['kind' => 'move', 'room_id' => $otherRoom->id, 'rate_amount' => 4000];
        $this->assertEquals(-2000, $desk->changeQuote($stay, $owner->id, $data)['change']);
        $desk->changeStay($stay, $owner->id, $data);
        $lines = $stay->folioEntries()->where('entry_type', 'charge')->orderBy('id')->get();
        $this->assertCount(2, $lines);
        $this->assertEquals(4800, $lines[0]->amount);
        $this->assertEquals(7600, $lines[1]->amount);
        $this->assertSame($otherRoom->id, $stay->fresh()->room_id);
    }

    public function test_cashier_rate_reduction_cannot_bypass_existing_discount_limit(): void
    {
        [$company, $owner, $room] = $this->fixture(['cashier_discount_limit' => 10]);
        $cashier = $this->staff($company, 'pos_cashier');
        $this->expectException(\App\Exceptions\HotelStayException::class);
        app(HotelStayService::class)->book($company->id, $cashier->id, $this->booking($room, ['rate_amount' => 4000]));
    }

    public function test_cashier_can_continue_manager_agreed_pricing_but_cannot_reduce_it_again(): void
    {
        foreach ([false, true] as $walkIn) {
            [$company, $owner, $room] = $this->fixture(['cashier_discount_limit' => 10]);
            $cashier = $this->staff($company, 'pos_cashier');
            $service = app(HotelStayService::class);
            $other = $service->createRoom($company->id, ['room_number' => '102', 'rate_amount' => 6000, 'capacity' => 3]);
            $stay = $service->book($company->id, $owner->id, $this->booking($room, ['walk_in' => $walkIn, 'rate_amount' => 3000, 'discount_type' => 'percentage', 'discount_value' => 20]));
            $desk = app(\App\Services\HotelDeskService::class);
            $stay = $desk->changeStay($stay, $cashier->id, ['kind' => 'move', 'room_id' => $other->id]);
            $stay = $desk->changeStay($stay, $cashier->id, ['kind' => 'extend', 'check_out_date' => now()->addDays(3)->toDateString()]);
            $this->assertEquals(3000, $stay->rate_amount);
            $this->assertEquals(7200, $desk->summary($stay, 'cash')['total']);
            try {
                $desk->changeStay($stay, $cashier->id, ['kind' => 'extend', 'rate_amount' => 2900, 'check_out_date' => now()->addDays(4)->toDateString()]);
                $this->fail('Changing agreed pricing must still enforce the cashier limit');
            } catch (\App\Exceptions\HotelStayException $e) {
                $this->assertEquals(3000, $stay->fresh()->rate_amount);
                $this->assertEquals(3, $stay->fresh()->nights);
            }
        }
    }

    public function test_failed_checkout_rolls_back_payment_and_leaves_stay_open(): void
    {
        [$company, $owner, $room] = $this->fixture(['hotel_checkout_outstanding' => 'block']);
        $stay = app(HotelStayService::class)->book($company->id, $owner->id, $this->booking($room));
        $this->actingAs($owner, 'pos')->post('/pos/hotel/stays/'.$stay->id.'/checkout', ['amount' => 100, 'payment_method' => 'cash', 'idempotency_key' => 'short-payment'])->assertSessionHas('error');
        $this->assertSame(0, $stay->folioEntries()->where('entry_type', 'payment')->count());
        $this->assertSame('checked_in', $stay->fresh()->status);
    }

    public function test_owner_policy_can_allow_explicit_outstanding_checkout(): void
    {
        [$company, $owner, $room] = $this->fixture(['hotel_checkout_outstanding' => 'allow']);
        $stay = app(HotelStayService::class)->book($company->id, $owner->id, $this->booking($room));
        app(\App\Services\HotelDeskService::class)->checkout($stay, $owner->id, ['amount' => 100, 'payment_method' => 'cash', 'leave_balance' => true, 'idempotency_key' => 'allow-balance']);
        $this->assertSame('checked_out', $stay->fresh()->status);
        $this->assertEquals(9900, app(\App\Services\HotelDeskService::class)->summary($stay, 'cash')['balance']);
        $billPage = $this->actingAs($owner, 'pos')->get('/pos/hotel/folios')->assertOk()->getContent();
        $this->assertStringContainsString(route('pos.hotel.stays.index'), $billPage);
        $this->get('/pos/hotel/stays')->assertOk()->assertSee($stay->stay_number);
        $this->get('/pos/hotel/stays/'.$stay->id)->assertOk()->assertSee(__('pos.hotel_collect_now'))
            ->assertSee(__('hotel_simplify.payment_pending'))->assertSee(__('hotel_simplify.cash_estimate'))
            ->assertSee('href="#hotel-payment"', false);
    }

    public function test_checked_out_collection_preview_uses_the_selected_payment_method(): void
    {
        [$company, $owner, $room] = $this->fixture([
            'hotel_checkout_outstanding' => 'allow', 'pos_tax_rate_cash' => 16,
            'pos_tax_rate_card' => 5, 'pos_tax_pricing_mode' => 'exclusive',
        ]);
        $stay = app(HotelStayService::class)->book($company->id, $owner->id, $this->booking($room));
        app(\App\Services\HotelDeskService::class)->checkout($stay, $owner->id, [
            'amount' => 0, 'payment_method' => 'cash', 'leave_balance' => true, 'idempotency_key' => 'unpaid-checkout',
        ]);
        $this->actingAs($owner, 'pos')->getJson('/pos/hotel/stays/'.$stay->id.'/checkout-quote?payment_method=cash')
            ->assertOk()->assertJsonPath('balance', 11600);
        $this->getJson('/pos/hotel/stays/'.$stay->id.'/checkout-quote?payment_method=card')
            ->assertOk()->assertJsonPath('balance', 10500);
        $this->get('/pos/hotel/stays/'.$stay->id)->assertOk()->assertSee('data-hotel-collection-quote="1"', false);
    }

    public function test_booking_quote_is_read_only_and_rejects_foreign_rooms_and_housekeeping(): void
    {
        [$company, $owner, $room] = $this->fixture();
        [$foreign, $other, $foreignRoom] = $this->fixture();
        $data = $this->booking($room, ['rate_amount' => 4500, 'discount_type' => 'amount', 'discount_value' => 500, 'payment_method' => 'cash']);
        $this->actingAs($owner, 'pos')->getJson('/pos/hotel/quote?'.http_build_query($data))->assertOk()->assertJson(['total' => 8500, 'available' => true]);
        $data['room_id'] = $foreignRoom->id;
        $this->getJson('/pos/hotel/quote?'.http_build_query($data))->assertNotFound();
        $this->assertSame(0, HotelStay::count());
        $hk = $this->staff($company, 'pos_cashier', ['hotel_housekeeping']);
        $this->actingAs($hk, 'pos')->get('/pos/hotel/quote?'.http_build_query($data))->assertRedirect();
    }

    public function test_old_no_pricing_fields_path_retains_saved_rate_and_reservation_advance(): void
    {
        [$company, $owner, $room] = $this->fixture();
        $service = app(HotelStayService::class);
        $stay = $service->book($company->id, $owner->id, $this->booking($room, ['walk_in' => false, 'advance_amount' => 500, 'payment_method' => 'cash']));
        $this->assertSame(0, $stay->folioEntries()->where('entry_type', 'charge')->count());
        $room->update(['rate_amount' => 9000]);
        $service->checkIn($stay, $owner->id);
        $this->assertEquals(10000, $stay->folioEntries()->where('entry_type', 'charge')->sum('amount'));
        $this->assertEquals(9500, app(\App\Services\HotelDeskService::class)->summary($stay, 'cash')['balance']);
    }

    public function test_discount_cannot_rewrite_an_issued_bill(): void
    {
        [$company, $owner, $room] = $this->fixture();
        $stay = app(HotelStayService::class)->book($company->id, $owner->id, $this->booking($room));
        $folio = app(\App\Services\HotelFolioService::class);
        $folio->postPayment($stay, ['amount' => 10000], $owner->id);
        $txn = $folio->settleCoveredCharges($stay, $owner->id, 'cash')['transaction'];
        try {
            app(\App\Services\HotelDeskService::class)->changeDiscount($stay, $owner->id, 'amount', 100);
            $this->fail('Issued pricing must be immutable');
        } catch (\App\Exceptions\HotelStayException $e) {
            $this->assertEquals(10000, $txn->fresh()->total_amount);
            $this->assertEquals(0, $stay->fresh()->discount_value);
        }
    }

    public function test_new_simple_pages_render_with_shared_navigation_and_pricing_fields(): void
    {
        [$company, $owner, $room] = $this->fixture();
        $this->actingAs($owner, 'pos')->get('/pos/hotel/stays/create?walk_in=1&room_id='.$room->id)->assertOk()->assertSee('name="rate_amount"', false)->assertSee('name="discount_value"', false)->assertSee('data-hotel-desk-menu="1"', false);
        $stay = app(HotelStayService::class)->book($company->id, $owner->id, $this->booking($room));
        $this->get('/pos/hotel/stays/'.$stay->id)->assertOk()->assertSee('/checkout', false);
        $this->get('/pos/hotel/stays/'.$stay->id.'/checkout')->assertRedirect(route('pos.hotel.stays.show', ['id' => $stay->id, 'bill_action' => 'checkout']));
    }
    public function test_extra_discount_stays_on_its_own_charge_and_guest_picker_is_tenant_scoped(): void
    {
        [$company, $owner, $room] = $this->fixture();
        [$otherCompany, $otherOwner, $otherRoom] = $this->fixture();
        $service = app(HotelStayService::class);
        $stay = $service->book($company->id, $owner->id, $this->booking($room));
        $foreign = $service->book($otherCompany->id, $otherOwner->id, $this->booking($otherRoom, ['guest_name' => 'Foreign private guest']));
        $entry = app(\App\Services\HotelFolioService::class)->postCharge($stay, ['category' => 'food', 'description' => 'Meal', 'quantity' => 2, 'unit_amount' => 500, 'discount_type' => 'percentage', 'discount_value' => 10], $owner->id);
        $this->assertEquals(100, $entry->discount_amount);
        $this->assertEquals(900, $entry->amount);
        $summary = app(\App\Services\HotelDeskService::class)->summary($stay, 'cash');
        $this->assertEquals(1000, $summary['extras']);
        $this->assertEquals(10900, $summary['total']);
        $this->actingAs($owner, 'pos')->get('/pos/hotel/stays/create')->assertOk()->assertSee('Simple Desk Guest')->assertDontSee('Foreign private guest');
        $this->get('/pos/hotel/stays/'.$foreign->id.'/checkout')->assertRedirect('/pos/dashboard')->assertSessionHas('error');
        $this->getJson('/pos/hotel/stays/'.$foreign->id.'/checkout')->assertNotFound()->assertJson(['error' => 'Resource not found.']);
        $this->assertSame(HotelStay::STATUS_CHECKED_IN, $foreign->fresh()->status);
    }


    public function test_popup_form_and_json_checkin_retain_actual_booking_and_preview(): void
    {
        [$company, $owner, $room] = $this->fixture();
        $this->actingAs($owner, 'pos')->get('/pos/hotel/stays/create?walk_in=1&modal=1&room_id='.$room->id)
            ->assertOk()->assertSee('name="guest_name"', false)->assertDontSee('data-hotel-desk-menu', false);
        $response = $this->postJson('/pos/hotel/stays', $this->booking($room, [
            'rate_amount' => 5000, 'idempotency_key' => 'popup-checkin-one',
        ]))->assertOk();
        $stay = HotelStay::where('company_id', $company->id)->firstOrFail();
        $response->assertJsonPath('stay_url', route('pos.hotel.stays.show', $stay->id));
        $this->get($response->json('stay_url'))->assertOk()
            ->assertSee('data-auto-open="1"', false)->assertSee('data-hotel-receipt-popup', false);
        $this->postJson('/pos/hotel/stays', $this->booking($room, [
            'rate_amount' => 5000, 'idempotency_key' => 'popup-checkin-one',
        ]))->assertOk();
        $this->assertSame(1, HotelStay::where('company_id', $company->id)->count());
    }

    public function test_saved_paper_and_invoice_popup_preserve_other_tenant_routing_and_bills(): void
    {
        [$company, $owner, $room] = $this->fixture([
            'receipt_printer_size' => '58mm',
            'pos_printer_settings' => ['silent_print_enabled' => true, 'receipt_printer' => 'Saved Queue', 'kot_printer' => 'Kitchen'],
        ]);
        [$otherCompany, $otherOwner, $otherRoom] = $this->fixture(['receipt_printer_size' => '80mm']);
        $stay = app(HotelStayService::class)->book($company->id, $owner->id, $this->booking($room));
        $folio = app(\App\Services\HotelFolioService::class);
        $folio->postPayment($stay, ['amount' => 10000], $owner->id);
        $bill = $folio->settleCoveredCharges($stay, $owner->id, 'cash')['transaction'];
        $routing = $company->pos_printer_settings;
        $flags = $company->feature_flags;
        $this->actingAs($owner, 'pos')->getJson(route('pos.hotel.receipt-preview', $stay->id))
            ->assertOk()->assertJsonPath('paper', '58mm')->assertJsonPath('silent', true)
            ->assertJsonPath('documents.0.bill_id', $bill->id)
            ->assertJsonPath('documents.0.url', route('pos.hotel.bill-receipt', [$stay->id, $bill->id]));
        $this->post(route('pos.hotel.receipt-paper'), ['paper' => 'a4'])->assertRedirect(route('pos.customize'));
        $this->assertSame($routing, $company->fresh()->pos_printer_settings);
        $this->assertSame($flags, array_diff_key($company->fresh()->feature_flags, ['hotel_receipt_a4' => true]));
        $this->assertSame('58mm', $company->fresh()->receipt_printer_size);
        $this->assertSame('80mm', $otherCompany->fresh()->receipt_printer_size);
        $this->get(route('pos.hotel.bill-receipt', [$stay->id, $bill->id]))
            ->assertOk()->assertSee('size: A4', false)->assertSee($bill->invoice_number);
        $this->getJson(route('pos.hotel.receipt-preview', $stay->id))->assertJsonPath('silent', false);
        $this->post(route('pos.hotel.receipt-paper'), ['paper' => '80mm'])->assertRedirect();
        $this->assertSame('80mm', $company->fresh()->receipt_printer_size);
        $this->assertSame($routing, $company->fresh()->pos_printer_settings);
        $this->actingAs($otherOwner, 'pos')->getJson(route('pos.hotel.receipt-preview', $stay->id))->assertNotFound();
        $this->assertEquals(10000, $bill->fresh()->total_amount);
    }

    public function test_receipt_print_uses_existing_uuid_and_counter_rules_with_scoped_status(): void
    {
        [$company, $owner, $room] = $this->fixture([
            'agent_enabled' => true, 'agent_last_seen' => now(),
            'pos_printer_settings' => ['silent_print_enabled' => true, 'receipt_printer' => 'Default Receipt'],
        ]);
        $stay = app(HotelStayService::class)->book($company->id, $owner->id, $this->booking($room));
        $folio = app(\App\Services\HotelFolioService::class);
        $folio->postPayment($stay, ['amount' => 10000], $owner->id);
        $bill = $folio->settleCoveredCharges($stay, $owner->id, 'cash')['transaction'];
        $url = route('pos.hotel.bill-print', [$stay->id, $bill->id]);
        $uuid = (string) \Illuminate\Support\Str::uuid();
        $first = $this->actingAs($owner, 'pos')->postJson($url, ['print_attempt_uuid' => $uuid])->assertOk()->assertJsonPath('success', true);
        $jobId = $first->json('job_id');
        $this->postJson($url, ['print_attempt_uuid' => $uuid])->assertOk()->assertJsonPath('job_id', $jobId)->assertJsonPath('deduped', true);
        $this->assertDatabaseHas('pos_print_jobs', ['id' => $jobId, 'transaction_id' => $bill->id, 'type' => 'bill', 'target_printer' => 'Default Receipt']);
        $this->getJson(route('pos.hotel.print-status', [$stay->id, $jobId]))->assertOk()->assertJsonPath('status', 'pending');
        \App\Models\PosPrintJob::findOrFail($jobId)->update(['status' => 'done', 'result_outcome' => 'printed']);
        $this->getJson(route('pos.hotel.print-status', [$stay->id, $jobId]))->assertOk()->assertJsonPath('status', 'done');
        $device = \App\Models\PosAgentDevice::create([
            'company_id' => $company->id, 'device_uid' => 'hotel-counter-two',
            'receipt_printer' => 'Counter Two Receipt', 'last_seen_at' => now(),
        ]);
        $owner->forceFill(['pos_device_uid' => $device->device_uid])->save();
        $second = $this->postJson($url, ['print_attempt_uuid' => (string) \Illuminate\Support\Str::uuid()])->assertOk();
        $this->assertDatabaseHas('pos_print_jobs', ['id' => $second->json('job_id'), 'device_uid' => $device->device_uid, 'target_printer' => 'Counter Two Receipt']);
        $device->update(['last_seen_at' => now()->subHour()]);
        $this->postJson($url, ['print_attempt_uuid' => (string) \Illuminate\Support\Str::uuid()])
            ->assertStatus(409)->assertJsonPath('reason', 'counter_unavailable');
        [$foreign, $foreignOwner, $foreignRoom] = $this->fixture();
        $this->actingAs($foreignOwner, 'pos')->postJson($url, ['print_attempt_uuid' => $uuid])->assertNotFound();
        $this->getJson(route('pos.hotel.print-status', [$stay->id, $jobId]))->assertNotFound();
        $this->assertSame(2, \App\Models\PosPrintJob::where('company_id', $company->id)->where('type', 'bill')->count());
    }


    public function test_combined_bills_net_credit_once_and_keep_stay_money_separate_from_refund(): void
    {
        [$company, $owner, $room] = $this->fixture();
        $stay = app(HotelStayService::class)->book($company->id, $owner->id, $this->booking($room));
        $folio = app(\App\Services\HotelFolioService::class);
        $folio->postPayment($stay, ['amount' => 10000], $owner->id);
        $folio->postDeposit($stay, ['amount' => 500], $owner->id);
        $bill = $folio->settleCoveredCharges($stay, $owner->id, 'cash')['transaction'];
        $credit = \App\Models\PosTransaction::create([
            'company_id' => $company->id, 'invoice_number' => 'RET-BILL-DIRECTORY',
            'transaction_type' => 'return', 'status' => 'completed', 'invoice_mode' => 'pra',
            'subtotal' => 2000, 'discount_amount' => 0, 'tax_rate' => 0, 'tax_amount' => 0,
            'total_amount' => 2000, 'payment_method' => 'cash', 'created_by' => $owner->id,
        ]);
        \App\Models\HotelFolioEntry::create([
            'company_id' => $company->id, 'stay_id' => $stay->id, 'entry_type' => 'adjustment',
            'category' => 'other', 'description' => 'Synthetic credit', 'quantity' => 1, 'unit_amount' => -2000,
            'amount' => -2000, 'pos_transaction_id' => $credit->id, 'created_by' => $owner->id,
        ]);
        $response = $this->actingAs($owner, 'pos')->get('/pos/hotel/folios?period=all')->assertOk()
            ->assertViewIs('pos.hotel.bills')->assertSee('data-hotel-invoice="'.$bill->id.'"', false)
            ->assertSee('data-hotel-invoice="'.$credit->id.'"', false);
        $this->assertEquals(8000, $response->viewData('summary')->total_sales);
        $this->assertEquals(1, $response->viewData('summary')->return_count);
        $this->assertEquals(10000, $response->viewData('directory')['paid']); // credit note does NOT refund money.
        $this->assertEquals(0, $response->viewData('directory')['due']);
        $this->assertSame(0, $stay->folioEntries()->where('entry_type', 'refund')->count());
        $csv = $this->get('/pos/hotel/folios/csv?period=all')->assertOk()->streamedContent();
        $this->assertStringContainsString($bill->invoice_number, $csv);
        $this->assertStringContainsString('RET-BILL-DIRECTORY', $csv);
        $this->assertStringContainsString('8000.00', $csv);
        $this->get('/pos/hotel/folios/pdf?period=all')->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $filtered = $this->get('/pos/hotel/folios?period=all&bill_type=returns')->assertOk();
        $this->assertEquals(2000, $filtered->viewData('summary')->total_sales);
        $this->assertSame([$credit->id], $filtered->viewData('transactions')->pluck('id')->all());
    }

    public function test_combined_filters_exports_and_back_links_preserve_generic_reports_and_foreign_tenant(): void
    {
        [$company, $owner, $room] = $this->fixture();
        [$otherCompany, $otherOwner, $otherRoom] = $this->fixture();
        $service = app(HotelStayService::class);
        $folio = app(\App\Services\HotelFolioService::class);
        $stay = $service->book($company->id, $owner->id, $this->booking($room));
        $folio->postPayment($stay, ['amount' => 10000], $owner->id);
        $bill = $folio->settleCoveredCharges($stay, $owner->id, 'cash')['transaction'];
        // SQLite's legacy fixture index is globally unique; give the first synthetic bill an explicit name.
        $bill->update(['invoice_number' => 'OWN-TENANT-DIRECTORY']);
        $foreign = $service->book($otherCompany->id, $otherOwner->id, $this->booking($otherRoom, ['guest_name' => 'Private other tenant']));
        $folio->postPayment($foreign, ['amount' => 10000], $otherOwner->id);
        $foreignBill = $folio->settleCoveredCharges($foreign, $otherOwner->id, 'cash')['transaction'];
        $this->actingAs($owner, 'pos')->get('/pos/tax-reports?period=all&tab=local')->assertOk()->assertViewIs('pos.hotel.bills')->assertViewHas('stream', 'local');
        $this->get('/pos/hotel/folios?period=all&room=101&payment_state=paid')->assertOk()
            ->assertSee('data-hotel-report-back="1"', false)->assertDontSee('Private other tenant')
            ->assertSee('data-hotel-invoice="'.$bill->id.'"', false)->assertDontSee('data-hotel-invoice="'.$foreignBill->id.'"', false);
        $this->get('/pos/hotel/folios?period=all&invoice=NO-MATCH')->assertOk()->assertViewHas('transactions', fn ($ts) => $ts->isEmpty());
        $csv = $this->get('/pos/hotel/folios/csv?period=all&invoice=NO-MATCH')->assertOk()->streamedContent();
        $this->assertStringNotContainsString($bill->invoice_number, $csv);
        $this->get('/pos/reports')->assertOk()->assertSee('data-hotel-report-back="1"', false);
        $retail = $this->company('retail');
        $retailOwner = $this->owner($retail);
        $this->actingAs($retailOwner, 'pos')->get('/pos/tax-reports')->assertOk()->assertViewIs('pos.tax-reports')
            ->assertDontSee('data-hotel-report-back="1"', false);
        $this->get('/pos/reports')->assertOk()->assertDontSee('data-hotel-report-back="1"', false);
    }

    public function test_draft_receipt_has_edit_cancel_actions_but_issued_fiscal_bill_does_not(): void
    {
        [$company, $owner, $room] = $this->fixture();
        $stay = app(HotelStayService::class)->book($company->id, $owner->id, $this->booking($room));
        $this->actingAs($owner, 'pos');
        $preview = $this->getJson('/pos/hotel/stays/'.$stay->id.'/bill-preview?flow=collect&payment_method=cash&amount=0')->assertOk();
        $this->assertSame($company->name, $preview->json('company_name'));
        $this->assertNotNull($preview->json('edit_url'));
        $this->assertNotNull($preview->json('delete_url'));
        $this->get($preview->json('edit_url'))->assertOk()->assertViewIs('pos.hotel.draft-editor')
            ->assertSee('name="rate_amount"', false)->assertSee('name="discount_value"', false)
            ->assertDontSee('data-hotel-bill-preview', false);
        $this->assertSame(0, \App\Models\PosTransaction::where('company_id', $company->id)->count());
        $folio = app(\App\Services\HotelFolioService::class);
        $folio->postPayment($stay, ['amount' => 10000], $owner->id);
        $bill = $folio->settleCoveredCharges($stay, $owner->id, 'cash')['transaction'];
        $bill->update(['pra_status' => 'submitted', 'pra_invoice_number' => 'SYNTHETIC-ONLY-FISCAL']);
        $new = $this->getJson('/pos/hotel/stays/'.$stay->id.'/bill-preview?flow=checkout&payment_method=cash&amount=0')->assertOk();
        $new->assertJsonPath('edit_url', null)->assertJsonPath('delete_url', null);
        $this->getJson('/pos/hotel/stays/'.$stay->id.'?modal_edit=1')->assertStatus(409);
        $this->get('/pos/hotel/stays/'.$stay->id.'/correction')->assertOk()->assertDontSee('name="confirmed"', false);
    }

    public function test_combined_cashier_scope_hides_other_invoice_and_partial_stay_money_in_exports(): void
    {
        [$company, $owner, $room] = $this->fixture(['pos_cashier_own_sales_only' => true]);
        $cashier = $this->staff($company, 'pos_cashier');
        $stay = app(HotelStayService::class)->book($company->id, $owner->id, $this->booking($room));
        $folio = app(\App\Services\HotelFolioService::class);
        $folio->postPayment($stay, ['amount' => 10000], $owner->id);
        $bill = $folio->settleCoveredCharges($stay, $owner->id, 'cash')['transaction'];
        $bill->update(['created_by' => $cashier->id, 'invoice_mode' => 'pra', 'pra_status' => 'submitted', 'pra_invoice_number' => 'SYNTHETIC-OWN']);
        $private = \App\Models\PosTransaction::create([
            'company_id' => $company->id, 'invoice_number' => 'PRIVATE-OTHER-CASHIER',
            'status' => 'completed', 'invoice_mode' => 'pra', 'pra_status' => 'submitted',
            'pra_invoice_number' => 'SYNTHETIC-OTHER', 'subtotal' => 300, 'total_amount' => 300,
            'payment_method' => 'cash', 'created_by' => $owner->id,
        ]);
        \App\Models\HotelFolioEntry::create([
            'company_id' => $company->id, 'stay_id' => $stay->id, 'entry_type' => 'charge',
            'category' => 'other', 'description' => 'Other cashier charge', 'quantity' => 1,
            'unit_amount' => 300, 'amount' => 300, 'pos_transaction_id' => $private->id,
            'created_by' => $owner->id,
        ]);
        $response = $this->actingAs($cashier, 'pos')->get('/pos/hotel/folios?period=all&stream=all')->assertOk()
            ->assertViewHas('stream', 'pra')->assertSee($bill->invoice_number)
            ->assertDontSee('PRIVATE-OTHER-CASHIER')->assertDontSee('data-tax-credit-note', false);
        $this->assertSame([$bill->id], $response->viewData('transactions')->pluck('id')->all());
        $this->assertNull($response->viewData('directory')['rows'][$bill->id]['money']);
        $this->assertTrue($response->viewData('directory')['restricted']);
        $csv = $this->get('/pos/hotel/folios/csv?period=all&stream=all')->assertOk()->streamedContent();
        $this->assertStringContainsString($bill->invoice_number, $csv);
        $this->assertStringNotContainsString('PRIVATE-OTHER-CASHIER', $csv);
        $this->get('/pos/hotel/folios/pdf?period=all')->assertOk();
        $bill->update(['hotel_money_from_folio' => true]);
        $this->get(route('pos.hotel.bill-receipt', [$stay->id, $bill->id]))->assertOk()->assertDontSee('data-hotel-receipt-money', false);
        $this->getJson(route('pos.hotel.bill-status', [$stay->id, $bill->id]))->assertOk()->assertJsonPath('bill_id', $bill->id);
        $this->getJson(route('pos.hotel.bill-status', [$stay->id, $private->id]))->assertForbidden()->assertDontSee('SYNTHETIC-OTHER');
        $this->actingAs($owner, 'pos')->get('/pos/hotel/folios?period=all')->assertOk()->assertSee('PRIVATE-OTHER-CASHIER');
        $this->get(route('pos.hotel.bill-receipt', [$stay->id, $bill->id]))->assertOk()->assertSee('data-hotel-receipt-money', false);
    }

    public function test_native_hotel_combined_tax_report_keeps_standalone_outlet_sales_and_returns(): void
    {
        [$company, $owner, $room] = $this->fixture();
        $stay = app(HotelStayService::class)->book($company->id, $owner->id, $this->booking($room));
        $folio = app(\App\Services\HotelFolioService::class);
        $folio->postPayment($stay, ['amount' => 10000], $owner->id);
        $bill = $folio->settleCoveredCharges($stay, $owner->id, 'cash')['transaction'];
        $bill->update(['pra_status' => 'submitted', 'pra_invoice_number' => 'SYNTHETIC-ROOM']);
        $outlet = \App\Models\PosTransaction::create([
            'company_id' => $company->id, 'invoice_number' => 'DIRECT-OUTLET-SALE',
            'transaction_type' => 'sale', 'status' => 'completed', 'invoice_mode' => 'pra',
            'pra_status' => 'submitted', 'pra_invoice_number' => 'SYNTHETIC-OUTLET',
            'customer_name' => 'Walk-in outlet guest', 'subtotal' => 840, 'discount_amount' => 0,
            'tax_rate' => 16, 'tax_amount' => 160, 'total_amount' => 1000,
            'payment_method' => 'cash', 'created_by' => $owner->id,
        ]);
        $return = \App\Models\PosTransaction::create([
            'company_id' => $company->id, 'invoice_number' => 'DIRECT-OUTLET-CREDIT',
            'transaction_type' => 'return', 'status' => 'completed', 'invoice_mode' => 'pra',
            'pra_status' => 'submitted', 'pra_invoice_number' => 'SYNTHETIC-OUTLET-CREDIT',
            'subtotal' => 168, 'discount_amount' => 0, 'tax_rate' => 16, 'tax_amount' => 32,
            'total_amount' => 200, 'payment_method' => 'cash', 'created_by' => $owner->id,
        ]);
        \App\Models\PosTransaction::create([
            'company_id' => $company->id, 'invoice_number' => 'LOCAL-OUTLET-UNREPORTED',
            'transaction_type' => 'sale', 'status' => 'completed', 'invoice_mode' => 'local',
            'subtotal' => 500, 'discount_amount' => 0, 'tax_amount' => 0, 'total_amount' => 500,
            'payment_method' => 'cash', 'created_by' => $owner->id,
        ]);
        $response = $this->actingAs($owner, 'pos')->get('/pos/tax-reports?period=all&tab=pra')->assertOk()
            ->assertSee('DIRECT-OUTLET-SALE')->assertSee('DIRECT-OUTLET-CREDIT')
            ->assertSee(route('pos.receipt', $outlet->id), false);
        $default = $this->get('/pos/tax-reports?period=all')->assertOk()->assertViewHas('stream', 'pra')
            ->assertDontSee('LOCAL-OUTLET-UNREPORTED');
        preg_match('/href="([^"]*\/pos\/hotel\/folios\/csv[^"]*)"/', $default->getContent(), $link);
        $this->assertNotEmpty($link);
        $download = $this->get(html_entity_decode($link[1], ENT_QUOTES, 'UTF-8'))->assertOk()->streamedContent();
        $this->assertStringContainsString('10800.00', $download);
        $this->assertStringNotContainsString('LOCAL-OUTLET-UNREPORTED', $download);
        $this->assertEquals(10800, $response->viewData('summary')->total_sales);
        $this->assertEquals(128, $response->viewData('summary')->total_tax);
        $this->assertEquals(10000, $response->viewData('directory')['paid']);
        $csv = $this->get('/pos/hotel/folios/csv?period=all&stream=pra')->assertOk()->streamedContent();
        $this->assertStringContainsString('DIRECT-OUTLET-SALE', $csv);
        $this->assertStringContainsString('DIRECT-OUTLET-CREDIT', $csv);
        $this->assertStringContainsString('10800.00', $csv);
        $this->get('/pos/hotel/folios?period=all&room=101')->assertOk()
            ->assertSee($bill->invoice_number)->assertDontSee('DIRECT-OUTLET-SALE')->assertDontSee('DIRECT-OUTLET-CREDIT');

        $a = Branch::create(['company_id' => $company->id, 'name' => 'Branch A', 'code' => 'A', 'is_active' => true, 'is_head_office' => true]);
        $b = Branch::create(['company_id' => $company->id, 'name' => 'Branch B', 'code' => 'B', 'is_active' => true]);
        // A legacy NULL invoice branch must not reveal a linked stay from Branch B.
        $stay->update(['branch_id' => $b->id]);
        $bill->update(['branch_id' => null]);
        $outlet->update(['branch_id' => $a->id]);
        $return->update(['branch_id' => $a->id]);
        app()->forgetInstance(\App\Services\BranchContextService::class);
        $scoped = $this->withSession(['active_branch_id' => $a->id])->get('/pos/hotel/folios?period=all&stream=pra')->assertOk()
            ->assertDontSee('data-hotel-invoice="'.$bill->id.'"', false)->assertSee('DIRECT-OUTLET-SALE');
        $this->assertEquals(800, $scoped->viewData('summary')->total_sales);
        $this->assertEquals(128, $scoped->viewData('summary')->total_tax);
    }

    public function test_payment_on_an_already_reported_bill_keeps_its_receipt_and_paid_checkout_takes_no_more_money(): void
    {
        [$company, $owner, $room] = $this->fixture();
        $this->actingAs($owner, 'pos');
        $stay = app(HotelStayService::class)->book($company->id, $owner->id,
            $this->booking($room, ['hotel_money_from_folio' => true, 'rate_amount' => 5000]));
        $folio = app(\App\Services\HotelFolioService::class);
        $folio->postPayment($stay, ['amount' => 2000], $owner->id);
        $bill = $folio->settleCoveredCharges($stay, $owner->id, 'cash')['transaction'];
        $bill->update(['invoice_mode' => 'pra', 'pra_status' => 'submitted', 'pra_invoice_number' => 'SYNTHETIC-ACCEPTED-ORIGINAL']);
        $base = '/pos/hotel/stays/'.$stay->id;
        $data = ['flow' => 'collect', 'payment_method' => 'cash', 'amount' => 8000, 'leave_balance' => false];
        $quote = $this->getJson($base.'/bill-preview?'.http_build_query($data))->assertOk()->assertJsonPath('will_issue', false);
        $payload = $data + ['preview_token' => $quote->json('preview_token'), 'idempotency_key' => (string) \Illuminate\Support\Str::uuid()];
        $this->postJson($base.'/bill-confirm', $payload)->assertOk()->assertJsonPath('bill_id', $bill->id)
            ->assertJsonPath('receipt_url', route('pos.hotel.bill-receipt', [$stay->id, $bill->id]));
        $this->postJson($base.'/bill-confirm', $payload)->assertOk()->assertJsonPath('bill_id', $bill->id);
        $this->getJson($base.'/bill-recovery?'.http_build_query($data + ['idempotency_key' => $payload['idempotency_key']]))
            ->assertOk()->assertJsonPath('state', 'confirmed')->assertJsonPath('result.bill_id', $bill->id)
            ->assertJsonPath('result.fiscal_number', 'SYNTHETIC-ACCEPTED-ORIGINAL');
        $payments = $stay->folioEntries()->where('entry_type', 'payment')->count();
        $this->assertEquals(10000, $stay->folioEntries()->where('entry_type', 'payment')->sum('amount'));
        $checkout = ['flow' => 'checkout', 'payment_method' => 'cash', 'amount' => 0, 'leave_balance' => false];
        $quote = $this->getJson($base.'/bill-preview?'.http_build_query($checkout))->assertOk()
            ->assertJsonPath('will_issue', false)->assertJsonPath('collect_now', 0);
        $payload = $checkout + ['preview_token' => $quote->json('preview_token'), 'idempotency_key' => (string) \Illuminate\Support\Str::uuid()];
        $this->postJson($base.'/bill-confirm', $payload)->assertOk()->assertJsonPath('stay_status', 'checked_out')->assertJsonPath('bill_id', $bill->id);
        $this->getJson($base.'/bill-recovery?'.http_build_query($checkout + ['idempotency_key' => $payload['idempotency_key']]))
            ->assertOk()->assertJsonPath('state', 'confirmed')->assertJsonPath('result.stay_status', 'checked_out')
            ->assertJsonPath('result.bill_id', $bill->id);
        $this->assertSame($payments, $stay->folioEntries()->where('entry_type', 'payment')->count());
        $this->assertSame(1, \App\Models\PosTransaction::where('company_id', $company->id)->count());
        $this->assertSame('SYNTHETIC-ACCEPTED-ORIGINAL', $bill->fresh()->pra_invoice_number);
    }

    public function test_read_only_recovery_of_nonzero_partial_payment_keeps_one_payment_and_scopes_the_operation(): void
    {
        \Illuminate\Support\Facades\Http::fake();
        [$company, $owner, $room] = $this->fixture();
        $this->actingAs($owner, 'pos');
        $stay = app(HotelStayService::class)->book($company->id, $owner->id, $this->booking($room));
        $base = '/pos/hotel/stays/'.$stay->id;
        $data = ['flow' => 'collect', 'payment_method' => 'cash', 'amount' => 2500, 'leave_balance' => false];
        $quote = $this->getJson($base.'/bill-preview?'.http_build_query($data))->assertOk()->assertJsonPath('will_issue', false);
        $uuid = (string) \Illuminate\Support\Str::uuid();
        $payload = $data + ['preview_token' => $quote->json('preview_token'), 'idempotency_key' => $uuid];
        $recovery = $base.'/bill-recovery?'.http_build_query($data + ['idempotency_key' => $uuid]);
        $this->getJson($recovery)->assertOk()->assertJsonPath('state', 'not_found');
        // Commit a real nonzero payment; a browser may lose this HTTP response.
        $this->postJson($base.'/bill-confirm', $payload)->assertOk()->assertJsonPath('status', 'no_bill');
        $before = [$stay->folioEntries()->count(), \App\Models\PosTransaction::count(),
            \Illuminate\Support\Facades\DB::table('hotel_bill_confirmations')->count()];
        $this->getJson($recovery)->assertOk()->assertJsonPath('state', 'confirmed')->assertJsonPath('result.status', 'no_bill');
        $this->assertSame($before, [$stay->folioEntries()->count(), \App\Models\PosTransaction::count(),
            \Illuminate\Support\Facades\DB::table('hotel_bill_confirmations')->count()]);
        $this->assertEquals(2500, $stay->folioEntries()->where('entry_type', 'payment')->sum('amount'));
        $this->assertSame(1, $stay->folioEntries()->where('entry_type', 'payment')->count());
        $this->getJson($base.'/bill-recovery?'.http_build_query(array_replace($data, ['amount' => 2501, 'idempotency_key' => $uuid])))
            ->assertStatus(409);
        // An expired preview token cannot prevent replay of a committed operation.
        $this->postJson($base.'/bill-confirm', array_replace($payload, ['preview_token' => 'expired-token']))
            ->assertOk()->assertJsonPath('status', 'no_bill');
        $this->assertEquals(2500, $stay->folioEntries()->where('entry_type', 'payment')->sum('amount'));
        $this->actingAs($this->owner($company), 'pos')->getJson($recovery)->assertOk()->assertJsonPath('state', 'not_found');
        $otherCompany = $this->company();
        $this->actingAs($this->owner($otherCompany), 'pos')->getJson($recovery)->assertNotFound();
        \Illuminate\Support\Facades\Http::assertNothingSent();
    }

    public function test_modal_draft_cancellation_keeps_its_result_in_the_editor_and_displays_validation(): void
    {
        [$company, $owner, $room] = $this->fixture();
        $this->actingAs($owner, 'pos');
        $stay = app(HotelStayService::class)->book($company->id, $owner->id, $this->booking($room));
        $url = '/pos/hotel/stays/'.$stay->id.'/correction?modal=1';
        $preview = $this->get($url)->assertOk()->assertSee('name="modal" value="1"', false);
        $data = ['modal' => 1, 'reason' => 'Synthetic incorrect draft', 'confirmed' => 1, 'payment_method' => 'cash',
            'fingerprint' => $preview->viewData('plan')['fingerprint']];
        $this->post('/pos/hotel/stays/'.$stay->id.'/correction', $data)->assertRedirect($url)->assertSessionHas('success');
        $this->get($url)->assertOk()->assertSee(__('hotel_correction.done'))->assertDontSee('name="fingerprint"', false);
        $this->assertSame('cancelled', $stay->fresh()->status);
    }

    public function test_draft_same_room_rate_edit_changes_only_unissued_nights_and_preserves_room_master_and_assignments(): void
    {
        [$company, $owner, $room] = $this->fixture();
        $this->actingAs($owner, 'pos');
        $stay = app(HotelStayService::class)->book($company->id, $owner->id, $this->booking($room));
        $assignments = $stay->assignments()->count();
        $this->get('/pos/hotel/stays/'.$stay->id.'?modal_edit=1')->assertOk()
            ->assertSee('value="'.$room->id.'" selected', false);
        $data = ['room_id' => $room->id, 'rate_amount' => 4500];
        $this->post('/pos/hotel/stays/'.$stay->id.'/move', $data)->assertRedirect()->assertSessionHas('success');
        $this->assertEquals(4500, $stay->fresh()->rate_amount);
        $this->assertEquals(9000, $stay->folioEntries()->where('entry_type', 'charge')->sum('amount'));
        $this->assertEquals(5000, $room->fresh()->rate_amount);
        $this->assertSame($assignments, $stay->assignments()->count());
        $this->post('/pos/hotel/stays/'.$stay->id.'/move', $data)->assertRedirect();
        $this->assertSame($assignments, $stay->assignments()->count());
        $folio = app(\App\Services\HotelFolioService::class);
        $folio->postPayment($stay, ['amount' => 9000], $owner->id);
        $bill = $folio->settleCoveredCharges($stay, $owner->id, 'cash')['transaction'];
        $this->post('/pos/hotel/stays/'.$stay->id.'/move', ['room_id' => $room->id, 'rate_amount' => 4000])
            ->assertRedirect()->assertSessionHas('error');
        $this->assertEquals(4500, $stay->fresh()->rate_amount);
        $this->assertEquals(9000, $bill->fresh()->total_amount);
    }


    public function test_checkin_picker_and_api_hide_occupied_and_dirty_rooms_until_checkout_and_cleaning(): void
    {
        [$company, $owner, $room] = $this->fixture();
        $service = app(HotelStayService::class);
        $free = $service->createRoom($company->id, ['room_number' => '102', 'rate_amount' => 5000, 'capacity' => 3]);
        $dirty = $service->createRoom($company->id, ['room_number' => '103', 'housekeeping' => 'dirty']);
        $stay = $service->book($company->id, $owner->id, $this->booking($room, ['rate_amount' => 5000]));
        $this->actingAs($owner, 'pos');
        $query = http_build_query(['check_in_date' => now()->toDateString(), 'check_out_date' => now()->addDay()->toDateString(), 'walk_in' => 1]);
        $this->get('/pos/hotel/stays/create?walk_in=1&modal=1&room_id='.$room->id)->assertOk()
            ->assertViewHas('rooms', fn ($rooms) => $rooms->contains('id', $free->id) && !$rooms->contains('id', $room->id) && !$rooms->contains('id', $dirty->id))
            ->assertViewHas('selectedRoomId', null);
        $ids = array_column($this->getJson('/pos/hotel/available-rooms?'.$query)->assertOk()->json('rooms'), 'id');
        $this->assertContains((string) $free->id, $ids);
        $this->assertNotContains((string) $room->id, $ids);
        $this->assertNotContains((string) $dirty->id, $ids);
        app(\App\Services\HotelDeskService::class)->checkout($stay, $owner->id, ['payment_method' => 'cash', 'amount' => 10000, 'idempotency_key' => 'picker-checkout']);
        $this->assertSame('dirty', $room->fresh()->housekeeping);
        $this->assertNotContains((string) $room->id, array_column($this->getJson('/pos/hotel/available-rooms?'.$query)->assertOk()->json('rooms'), 'id'));
        $service->setHousekeeping($room->fresh(), 'clean');
        $this->assertContains((string) $room->id, array_column($this->getJson('/pos/hotel/available-rooms?'.$query)->assertOk()->json('rooms'), 'id'));
    }

    public function test_room_picker_excludes_inactive_and_out_of_service_rooms_in_both_booking_modes(): void
    {
        [$company, $owner, $room] = $this->fixture();
        $service = app(HotelStayService::class);
        $inactive = $service->createRoom($company->id, ['room_number' => 'INACTIVE', 'is_active' => false]);
        $out = $service->createRoom($company->id, ['room_number' => 'OUT', 'service_state' => HotelRoom::SERVICE_OUT]);
        $dirty = $service->createRoom($company->id, ['room_number' => 'DIRTY', 'housekeeping' => HotelRoom::HK_DIRTY]);
        $this->actingAs($owner, 'pos');

        foreach ([1, 0] as $walkIn) {
            $query = http_build_query(['check_in_date' => now()->toDateString(),
                'check_out_date' => now()->addDay()->toDateString(), 'walk_in' => $walkIn]);
            $ids = array_column($this->getJson('/pos/hotel/available-rooms?'.$query)->assertOk()->json('rooms'), 'id');
            $this->assertContains((string) $room->id, $ids);
            $this->assertNotContains((string) $inactive->id, $ids);
            $this->assertNotContains((string) $out->id, $ids);
            if ($walkIn) $this->assertNotContains((string) $dirty->id, $ids);
            else $this->assertContains((string) $dirty->id, $ids); // A future reservation can precede cleaning.

            foreach ([$inactive, $out] as $blocked) {
                $this->get('/pos/hotel/stays/create?walk_in='.$walkIn.'&room_id='.$blocked->id)->assertOk()
                    ->assertViewHas('selectedRoomId', fn ($id) => $id === null)
                    ->assertViewHas('rooms', fn ($rooms) => $rooms->contains('id', $room->id)
                        && !$rooms->contains('id', $inactive->id) && !$rooms->contains('id', $out->id));
            }
        }
    }

    public function test_room_picker_respects_reservation_dates_assignments_and_tenant_branch_scope(): void
    {
        [$company, $owner, $room] = $this->fixture();
        $service = app(HotelStayService::class);
        $stay = $service->book($company->id, $owner->id, $this->booking($room, ['walk_in' => false,
            'check_in_date' => now()->addDays(7)->toDateString(), 'check_out_date' => now()->addDays(9)->toDateString()]));
        $assignmentRoom = $service->createRoom($company->id, ['room_number' => '102']);
        \App\Models\HotelStayAssignment::create(['company_id' => $company->id, 'stay_id' => $stay->id, 'room_id' => $assignmentRoom->id,
            'from_date' => now()->addDays(7)->toDateString(), 'to_date' => now()->addDays(9)->toDateString(), 'rate_amount' => 5000, 'rate_unit' => 'NGT']);
        $foreign = $this->company('guest_house');
        $foreignRoom = $service->createRoom($foreign->id, ['room_number' => 'FOREIGN']);
        $this->actingAs($owner, 'pos');
        $today = ['check_in_date' => now()->toDateString(), 'check_out_date' => now()->addDay()->toDateString(), 'walk_in' => 1];
        $ids = array_column($this->getJson('/pos/hotel/available-rooms?'.http_build_query($today))->assertOk()->json('rooms'), 'id');
        $this->assertContains((string) $room->id, $ids); $this->assertNotContains((string) $foreignRoom->id, $ids);
        $future = ['check_in_date' => now()->addDays(7)->toDateString(), 'check_out_date' => now()->addDays(8)->toDateString(), 'walk_in' => 0];
        $ids = array_column($this->getJson('/pos/hotel/available-rooms?'.http_build_query($future))->assertOk()->json('rooms'), 'id');
        $this->assertNotContains((string) $room->id, $ids); $this->assertNotContains((string) $assignmentRoom->id, $ids);
        $adjacent = ['check_in_date' => now()->addDays(9)->toDateString(), 'check_out_date' => now()->addDays(10)->toDateString(), 'walk_in' => 0];
        $this->assertContains((string) $room->id, array_column($this->getJson('/pos/hotel/available-rooms?'.http_build_query($adjacent))->assertOk()->json('rooms'), 'id'));
        $a = Branch::create(['company_id' => $company->id, 'name' => 'A', 'code' => 'A', 'is_active' => true, 'is_head_office' => true]);
        $b = Branch::create(['company_id' => $company->id, 'name' => 'B', 'code' => 'B', 'is_active' => true]);
        $room->update(['branch_id' => $a->id]); $assignmentRoom->update(['branch_id' => $b->id]);
        app()->forgetInstance(\App\Services\BranchContextService::class);
        $ids = array_column($this->withSession(['active_branch_id' => $a->id])->getJson('/pos/hotel/available-rooms?'.http_build_query($today))->assertOk()->json('rooms'), 'id');
        $this->assertContains((string) $room->id, $ids); $this->assertNotContains((string) $assignmentRoom->id, $ids);
        $this->getJson('/pos/hotel/available-rooms?check_in_date=invalid&check_out_date=invalid')->assertUnprocessable();
    }

    public function test_overdue_checked_in_room_cannot_accept_a_walkin_or_reserved_checkin_even_after_scheduled_departure(): void
    {
        [$company, $owner, $room] = $this->fixture();
        $service = app(HotelStayService::class);
        $occupied = $service->book($company->id, $owner->id, $this->booking($room, ['rate_amount' => 5000,
            'check_in_date' => now()->subDays(2)->toDateString(), 'check_out_date' => now()->subDay()->toDateString()]));
        $this->actingAs($owner, 'pos');
        $data = $this->booking($room, ['rate_amount' => 5000, 'discount_type' => 'amount', 'discount_value' => 0, 'payment_method' => 'cash']);
        $this->getJson('/pos/hotel/quote?'.http_build_query($data))->assertOk()->assertJsonPath('available', false);
        $this->postJson('/pos/hotel/stays', $data)->assertConflict();
        $this->assertSame(1, HotelStay::where('company_id', $company->id)->count());
        // A non-overlapping reservation is valid, but actual occupancy still blocks its check-in.
        $reserved = $service->book($company->id, $owner->id, array_merge($data, ['walk_in' => false]));
        $this->from('/pos/hotel/stays/'.$reserved->id)->post('/pos/hotel/stays/'.$reserved->id.'/check-in')->assertRedirect()->assertSessionHas('error');
        $this->assertSame('reserved', $reserved->fresh()->status); $this->assertSame('checked_in', $occupied->fresh()->status);
        $this->assertSame(0, $reserved->folioEntries()->count());
    }
}
