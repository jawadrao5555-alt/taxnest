<?php

namespace Tests\Feature;

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
        $this->assertStringContainsString(__('hotel_simplify.payment_pending'), $billPage);
        $this->assertStringContainsString('/pos/hotel/stays/'.$stay->id.'#hotel-payment', $billPage);
        $this->assertStringContainsString(__('hotel_simplify.cash_estimate'), $billPage);
        $this->get('/pos/hotel/stays/'.$stay->id)->assertOk()->assertSee(__('pos.hotel_collect_now'));
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

}
