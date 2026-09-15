<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Company;
use App\Models\HotelStay;
use App\Models\User;
use App\Services\HotelShell;
use App\Services\HotelStayService;
use App\Services\PosFeatureService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class HotelCategoryNativeUiTest extends TestCase
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

    public function test_hotel_login_lands_on_front_desk_and_hides_new_sale(): void
    {
        $company = $this->company();
        $owner = $this->owner($company);
        $this->assertTrue(HotelShell::hideGenericSale($company));
        $this->assertSame('/pos/hotel', HotelShell::postLoginPath($owner));

        $html = $this->actingAs($owner, 'pos')->get('/pos/hotel')->assertOk()->getContent();
        $this->assertStringContainsString('data-hotel-native-nav="1"', $html);
        $this->assertStringContainsString('data-hotel-primary-actions="1"', $html);
        $this->assertStringContainsString('data-hotel-room-board="1"', $html);
        $this->assertStringContainsString(__('pos.hotel_action_booking'), $html);
        $this->assertStringContainsString(__('pos.hotel_stat_collections'), $html);
        $this->assertStringNotContainsString('data-nav-new-sale="static"', $html);
        $this->assertStringNotContainsString('>checked_in<', $html);
    }

    public function test_kitchen_hotel_keeps_new_sale_and_retail_is_untouched(): void
    {
        $flags = PosFeatureService::defaultsForCategory('hotel');
        $flags['kot'] = true;
        $flags['kitchen'] = true;
        $hotelKitchen = $this->company('hotel', [
            'feature_flags' => $flags,
            'restaurant_mode' => true,
        ]);
        $this->assertFalse(HotelShell::hideGenericSale($hotelKitchen));
        $retail = $this->company('retail');
        $this->assertFalse(HotelShell::hideGenericSale($retail));
        $this->assertSame('/pos/invoice/create', HotelShell::postLoginPath($this->owner($retail)));
    }

    public function test_rooms_off_does_not_rewrite_saved_flags_or_open_hotel(): void
    {
        $off = $this->company('hotel', [
            'feature_flags' => array_merge(PosFeatureService::defaultsForCategory('hotel'), ['rooms' => false]),
            'hotel_checkout_outstanding' => 'block',
        ]);
        $this->assertSame('block', $off->fresh()->hotel_checkout_outstanding);
        $this->assertFalse(HotelShell::isNativeCategory($off));
        $this->actingAs($this->owner($off), 'pos')->get('/pos/hotel')->assertRedirect('/pos/dashboard');
    }

    public function test_roles_and_second_tenant_isolation(): void
    {
        $a = $this->company();
        $b = $this->company();
        $ownerA = $this->owner($a);
        $hk = $this->staff($a, 'pos_cashier', ['dashboard', 'hotel_housekeeping']);
        $desk = $this->staff($a, 'pos_cashier', ['dashboard', 'hotel']);
        $denied = $this->staff($a, 'pos_cashier', ['dashboard', 'orders']);
        $stays = app(HotelStayService::class);
        $room = $stays->createRoom((int) $a->id, [
            'room_number' => '101', 'room_type' => 'Deluxe', 'capacity' => 2,
            'rate_amount' => 4000, 'rate_unit' => 'NGT',
        ]);
        $stay = $stays->book((int) $a->id, (int) $ownerA->id, [
            'room_id' => $room->id,
            'check_in_date' => now()->toDateString(),
            'check_out_date' => now()->addDays(2)->toDateString(),
            'guest_name' => 'Native Guest',
            'walk_in' => true,
        ]);

        $this->actingAs($desk, 'pos')->get('/pos/hotel')->assertOk();
        $this->actingAs($desk, 'pos')->get('/pos/hotel/folios')->assertOk()->assertSee('Native Guest', false);
        $this->actingAs($hk, 'pos')->get('/pos/hotel')->assertRedirect();
        $this->actingAs($hk, 'pos')->get('/pos/hotel/housekeeping')->assertOk()->assertSee('data-hotel-room-board', false);
        $this->actingAs($hk, 'pos')->get('/pos/hotel/reservations')->assertRedirect();
        $this->actingAs($denied, 'pos')->get('/pos/hotel')->assertRedirect();
        $this->actingAs($denied, 'pos')->get('/pos/hotel/folios')->assertRedirect();
        $this->actingAs($this->owner($b), 'pos')->get('/pos/hotel/stays/'.$stay->id)->assertRedirect('/pos/dashboard');
        $this->assertSame(HotelStay::STATUS_CHECKED_IN, $stay->fresh()->status);
        $this->assertSame('/pos/hotel/housekeeping', HotelShell::postLoginPath($hk));
    }

    public function test_second_branch_and_occupancy_due_still_count(): void
    {
        $company = $this->company();
        $stays = app(HotelStayService::class);
        $user = $this->owner($company);
        $branchA = Branch::create(['company_id' => $company->id, 'name' => 'A', 'code' => 'HA', 'is_active' => true]);
        $branchB = Branch::create(['company_id' => $company->id, 'name' => 'B', 'code' => 'HB', 'is_active' => true]);
        $roomA = $stays->createRoom((int) $company->id, [
            'room_number' => '201', 'rate_amount' => 3000, 'capacity' => 2, 'branch_id' => $branchA->id,
        ]);
        $roomB = $stays->createRoom((int) $company->id, [
            'room_number' => '301', 'rate_amount' => 3000, 'capacity' => 2, 'branch_id' => $branchB->id,
        ]);
        $stayA = $stays->book((int) $company->id, (int) $user->id, [
            'room_id' => $roomA->id,
            'check_in_date' => now()->toDateString(),
            'check_out_date' => now()->addDay()->toDateString(),
            'guest_name' => 'Branch A Guest',
            'walk_in' => true,
        ]);
        $stays->book((int) $company->id, (int) $user->id, [
            'room_id' => $roomB->id,
            'check_in_date' => now()->toDateString(),
            'check_out_date' => now()->addDay()->toDateString(),
            'guest_name' => 'Branch B Guest',
            'walk_in' => true,
        ]);

        $this->actingAs($user, 'pos')->withSession(['active_branch_id' => $branchA->id])
            ->get('/pos/hotel/guests')
            ->assertOk()
            ->assertSee('Branch A Guest', false)
            ->assertDontSee('Branch B Guest', false);

        $occ = $stays->occupancy((int) $company->id, (int) $branchA->id);
        $this->assertSame(1, $occ['pending_due_count']);
        $money = $stays->todayMoney((int) $company->id, (int) $branchA->id);
        $this->assertGreaterThan(0, $money['charges']);
        $this->assertSame(HotelStay::STATUS_CHECKED_IN, $stayA->fresh()->status);
    }

    public function test_stay_show_uses_readable_status_and_timeline(): void
    {
        $company = $this->company();
        $stays = app(HotelStayService::class);
        $user = $this->owner($company);
        $room = $stays->createRoom((int) $company->id, [
            'room_number' => '401', 'rate_amount' => 2500, 'capacity' => 2,
        ]);
        $stay = $stays->book((int) $company->id, (int) $user->id, [
            'room_id' => $room->id,
            'check_in_date' => now()->toDateString(),
            'check_out_date' => now()->addDay()->toDateString(),
            'guest_name' => 'Timeline Guest',
            'walk_in' => true,
        ]);
        $html = $this->actingAs($user, 'pos')->get('/pos/hotel/stays/'.$stay->id)->assertOk()->getContent();
        $this->assertStringContainsString(\App\Services\HotelShell::statusLabel(HotelStay::STATUS_CHECKED_IN), $html);
        $this->assertStringNotContainsString('· checked_in', $html);
        $this->assertStringContainsString(__('pos.hotel_timeline'), $html);
        $this->assertStringContainsString('id="hotel-charge"', $html);
        $this->assertStringContainsString('overflow-x-auto', $html);
    }
}
