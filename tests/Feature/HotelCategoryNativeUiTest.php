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

    public function test_reception_room_actions_preselect_only_a_room_from_its_own_company(): void
    {
        $company = $this->company();
        $other = $this->company();
        $owner = $this->owner($company);
        $stays = app(HotelStayService::class);
        $vacant = $stays->createRoom((int) $company->id, [
            'room_number' => '101', 'room_type' => 'Standard', 'capacity' => 2, 'rate_amount' => 4000,
        ]);
        $occupied = $stays->createRoom((int) $company->id, [
            'room_number' => '102', 'room_type' => 'Standard', 'capacity' => 2, 'rate_amount' => 5000,
        ]);
        $foreign = $stays->createRoom((int) $other->id, [
            'room_number' => '201', 'room_type' => 'Standard', 'capacity' => 2, 'rate_amount' => 6000,
        ]);
        $stay = $stays->book((int) $company->id, (int) $owner->id, [
            'room_id' => $occupied->id,
            'check_in_date' => now()->toDateString(),
            'check_out_date' => now()->addDays(2)->toDateString(),
            'guest_name' => 'Reception Guest',
            'walk_in' => true,
        ]);

        $dashboard = $this->actingAs($owner, 'pos')->get('/pos/hotel')->assertOk()->getContent();
        $this->assertStringContainsString('data-hotel-room-check-in="'.$vacant->id.'"', $dashboard);
        $this->assertStringContainsString('room_id='.$vacant->id, $dashboard);
        $this->assertStringContainsString('data-hotel-room-stay="'.$occupied->id.'"', $dashboard);
        $this->assertStringContainsString('data-hotel-room-group="vacant"', $dashboard);
        $this->assertStringContainsString('data-hotel-room-group="occupied"', $dashboard);
        $this->assertStringContainsString($stay->check_out_date->format('d M Y'), $dashboard);
        $this->assertStringContainsString('/pos/hotel/stays/'.$stay->id, $dashboard);
        $this->assertStringNotContainsString('data-hotel-room-check-in="'.$occupied->id.'"', $dashboard);
        $this->assertStringNotContainsString('data-hotel-room-check-in="'.$foreign->id.'"', $dashboard);

        $this->actingAs($owner, 'pos')->get('/pos/hotel/stays/create?walk_in=1&room_id='.$vacant->id)
            ->assertOk()->assertSee('value="'.$vacant->id.'" selected', false);
        $this->actingAs($owner, 'pos')->get('/pos/hotel/stays/create?walk_in=1&room_id='.$foreign->id)
            ->assertOk()->assertDontSee('value="'.$foreign->id.'"', false);
    }

    public function test_manage_as_hotel_header_keeps_exit_in_a_responsive_scoped_layout(): void
    {
        $company = $this->company();
        $owner = $this->owner($company);
        $admin = AdminUser::create([
            'name' => 'Synthetic Admin',
            'email' => 'synthetic-admin-'.uniqid().'@test.pk',
            'password' => Hash::make('Secret@12345'),
            'role' => 'super_admin',
        ]);

        $html = $this->withSession([
            'impersonation' => [
                'admin_id' => $admin->id,
                'company_id' => $company->id,
                'company_name' => 'Synthetic Manage As Hotel',
                'guard' => 'pos',
                'mode' => 'full',
                'readonly' => false,
            ],
        ])->actingAs($admin, 'admin')->actingAs($owner, 'pos')->get('/pos/hotel')->assertOk()->getContent();

        $this->assertStringContainsString('tn-impersonated-header', $html);
        $this->assertStringContainsString('tn-impersonation-header-row', $html);
        $this->assertStringContainsString('tn-impersonation-header-nav', $html);
        $this->assertStringContainsString('tn-impersonation-chip', $html);
        $this->assertStringContainsString('Exit impersonation', $html);
    }

    public function test_restaurant_mode_hotel_keeps_shell_and_separates_outlet(): void
    {
        $flags = PosFeatureService::defaultsForCategory('hotel');
        $flags['kot'] = true;
        $flags['kitchen'] = true;
        $flags['tables'] = true;
        $savedMode = true;
        $hotelKitchen = $this->company('hotel', [
            'feature_flags' => $flags,
            'restaurant_mode' => $savedMode,
            'hotel_checkout_outstanding' => 'allow',
        ]);
        $before = $hotelKitchen->fresh()->only([
            'restaurant_mode', 'business_category', 'hotel_checkout_outstanding', 'feature_flags',
        ]);
        $owner = $this->owner($hotelKitchen);

        // Stronger than PR #80: New Sale stays hidden; outlet is the kitchen door.
        $this->assertTrue(HotelShell::hideGenericSale($hotelKitchen));
        $this->assertTrue(HotelShell::restaurantOutletOn($hotelKitchen));
        $this->assertSame('/pos/hotel', HotelShell::postLoginPath($owner));
        $this->actingAs($owner, 'pos')->get('/pos/dashboard')->assertRedirect('/pos/hotel');

        $desk = $this->actingAs($owner, 'pos')->get('/pos/hotel')->assertOk()->getContent();
        $this->assertStringContainsString('data-hotel-native-nav="1"', $desk);
        $this->assertStringContainsString('data-hotel-restaurant-outlet="1"', $desk);
        $this->assertStringContainsString(__('pos.nav_hotel_restaurant_outlet'), $desk);
        $this->assertStringNotContainsString('data-nav-new-sale="static"', $desk);
        $this->assertStringContainsString('data-hotel-primary-actions="1"', $desk);

        $this->actingAs($owner, 'pos')->get('/pos/hotel/restaurant')
            ->assertRedirect('/pos/invoice/create');
        $sale = $this->actingAs($owner, 'pos')
            ->withSession([HotelShell::OUTLET_SESSION_KEY => true])
            ->get('/pos/invoice/create')
            ->assertOk()
            ->getContent();
        $this->assertStringContainsString('data-hotel-back-front-desk="1"', $sale);
        $this->assertStringContainsString(__('pos.hotel_back_front_desk'), $sale);
        $this->actingAs($owner, 'pos')
            ->withSession([HotelShell::OUTLET_SESSION_KEY => true])
            ->get('/pos/hotel/restaurant/exit')
            ->assertRedirect('/pos/hotel');

        // Saved settings untouched by chrome.
        $after = $hotelKitchen->fresh()->only([
            'restaurant_mode', 'business_category', 'hotel_checkout_outstanding', 'feature_flags',
        ]);
        $this->assertSame($before, $after);
        $this->assertTrue((bool) $after['restaurant_mode']);

        $retail = $this->company('retail');
        $this->assertFalse(HotelShell::hideGenericSale($retail));
        $this->assertSame('/pos/invoice/create', HotelShell::postLoginPath($this->owner($retail)));
    }

    public function test_restaurant_outlet_off_denies_sale_and_hides_entry(): void
    {
        $company = $this->company('hotel', ['restaurant_mode' => false]);
        $owner = $this->owner($company);
        $this->assertFalse(HotelShell::restaurantOutletOn($company));
        $html = $this->actingAs($owner, 'pos')->get('/pos/hotel')->assertOk()->getContent();
        $this->assertStringNotContainsString('data-hotel-restaurant-outlet="1"', $html);
        $this->actingAs($owner, 'pos')->get('/pos/hotel/restaurant')
            ->assertRedirect('/pos/hotel');
        $this->actingAs($owner, 'pos')->get('/pos/invoice/create')
            ->assertRedirect('/pos/hotel');
        $this->actingAs($owner, 'pos')->get('/pos/v2/invoice/create')
            ->assertRedirect('/pos/hotel');
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

    public function test_housekeeping_denied_from_restaurant_outlet(): void
    {
        $flags = PosFeatureService::defaultsForCategory('hotel');
        $flags['kitchen'] = true;
        $flags['kot'] = true;
        $company = $this->company('hotel', [
            'feature_flags' => $flags,
            'restaurant_mode' => true,
        ]);
        $hk = $this->staff($company, 'pos_cashier', ['dashboard', 'hotel_housekeeping']);
        $this->actingAs($hk, 'pos')->get('/pos/hotel/restaurant')->assertForbidden();
        $this->actingAs($hk, 'pos')->get('/pos/invoice/create')->assertRedirect('/pos/hotel/housekeeping');
    }

    public function test_user_denied_both_modules_cannot_open_outlet_or_hotel(): void
    {
        $flags = PosFeatureService::defaultsForCategory('hotel');
        $flags['kitchen'] = true;
        $flags['kot'] = true;
        $company = $this->company('hotel', [
            'feature_flags' => $flags,
            'restaurant_mode' => true,
        ]);
        $denied = $this->staff($company, 'pos_cashier', ['dashboard']);
        $this->assertFalse(HotelShell::canOpenRestaurantOutlet($denied, $company));
        $this->assertSame('/pos/dashboard', HotelShell::postLoginPath($denied));
        $this->actingAs($denied, 'pos')->get('/pos/hotel')->assertRedirect();
        $this->actingAs($denied, 'pos')->get('/pos/hotel/restaurant')->assertForbidden();
        $this->actingAs($denied, 'pos')->get('/pos/invoice/create')->assertRedirect('/pos/dashboard');
    }

    public function test_restaurant_cashier_lands_on_outlet_not_front_desk(): void
    {
        $flags = PosFeatureService::defaultsForCategory('hotel');
        $flags['kitchen'] = true;
        $flags['kot'] = true;
        $company = $this->company('hotel', [
            'feature_flags' => $flags,
            'restaurant_mode' => true,
        ]);
        $cashier = $this->staff($company, 'pos_cashier', ['dashboard', 'orders']);
        $this->assertSame('/pos/hotel/restaurant', HotelShell::postLoginPath($cashier));
        $this->assertTrue(HotelShell::canOpenRestaurantOutlet($cashier, $company));
        $this->actingAs($cashier, 'pos')->get('/pos/hotel/restaurant')
            ->assertRedirect('/pos/invoice/create');
    }

    public function test_manage_as_company_path_uses_hotel_shell(): void
    {
        $company = $this->company();
        $owner = $this->owner($company);
        $this->assertSame('/pos/hotel', HotelShell::postLoginPath($owner));

        $flags = PosFeatureService::defaultsForCategory('hotel');
        $flags['kitchen'] = true;
        $kitchen = $this->company('hotel', [
            'feature_flags' => $flags,
            'restaurant_mode' => true,
        ]);
        $this->assertSame('/pos/hotel', HotelShell::postLoginPath($this->owner($kitchen)));
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

    public function test_stay_show_uses_readable_status_timeline_and_stay_extra_labels(): void
    {
        $company = $this->company();
        $stays = app(HotelStayService::class);
        $user = $this->owner($company);
        $room = $stays->createRoom((int) $company->id, [
            'room_number' => '401', 'rate_amount' => 2500, 'capacity' => 2,
        ]);
        $product = \App\Models\PosProduct::create([
            'company_id' => $company->id,
            'name' => 'Minibar Cola',
            'price' => 150,
            'uom' => 'NOS',
            'is_active' => true,
            'show_on_sale' => true,
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
        $this->assertStringContainsString('data-hotel-stay-extras="1"', $html);
        $this->assertStringContainsString(__('pos.hotel_from_product'), $html);
        $this->assertStringContainsString('Minibar Cola', $html);
        $this->assertSame('Minibar Cola', $product->fresh()->name);
    }

    public function test_canonical_profile_recognizes_legacy_hotel_without_rewriting_saved_settings(): void
    {
        $legacy = $this->company('hotel', [
            'business_category' => null,
            'pos_type' => 'hotel',
            'restaurant_mode' => true,
            'hotel_checkout_outstanding' => 'block',
        ]);
        $owner = $this->owner($legacy);
        $before = $legacy->fresh()->only([
            'business_category', 'pos_type', 'feature_flags', 'restaurant_mode',
            'hotel_checkout_outstanding',
        ]);

        $this->assertSame('hotel', PosFeatureService::profileCategory($legacy));
        $this->assertTrue(HotelShell::isNativeCategory($legacy));
        $this->assertSame('/pos/hotel', HotelShell::postLoginPath($owner));
        $this->actingAs($owner, 'pos')->get('/pos/dashboard')->assertRedirect('/pos/hotel');

        $after = $legacy->fresh()->only([
            'business_category', 'pos_type', 'feature_flags', 'restaurant_mode',
            'hotel_checkout_outstanding',
        ]);
        $this->assertSame($before, $after);
    }

    public function test_unknown_category_never_becomes_hotel_and_explicit_rooms_off_stays_closed(): void
    {
        $rooms = PosFeatureService::defaultsForCategory('hotel');

        $unknown = $this->company('hotel', [
            'business_category' => 'guest_house_unknown',
            'pos_type' => 'unknown',
            'feature_flags' => $rooms,
        ]);
        $this->assertSame('general', PosFeatureService::profileCategory($unknown));
        $this->assertFalse(HotelShell::isNativeCategory($unknown));
        $this->assertSame('/pos/invoice/create', HotelShell::postLoginPath($this->owner($unknown)));

        $legacyOff = $this->company('hotel', [
            'business_category' => null,
            'pos_type' => 'hotel',
            'feature_flags' => array_merge($rooms, ['rooms' => false]),
        ]);
        $this->assertSame('hotel', PosFeatureService::profileCategory($legacyOff));
        $this->assertFalse(HotelShell::isNativeCategory($legacyOff));
        $this->assertSame('/pos/invoice/create', HotelShell::postLoginPath($this->owner($legacyOff)));

        $knownRetailWins = $this->company('hotel', [
            'business_category' => 'retail',
            'pos_type' => 'hotel',
            'feature_flags' => $rooms,
        ]);
        $this->assertSame('retail', PosFeatureService::profileCategory($knownRetailWins));
        $this->assertFalse(HotelShell::isNativeCategory($knownRetailWins));
    }
}
