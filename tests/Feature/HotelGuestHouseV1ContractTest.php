<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\User;
use App\Services\HotelAccessService;
use App\Services\PosAccessService;
use App\Services\PosCategoryProfiles;
use App\Services\PosFeatureService;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Hotel / Guest House V1 contracts that do not need a SQL driver.
 */
class HotelGuestHouseV1ContractTest extends TestCase
{
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

    public function test_new_hotel_preset_is_accommodation_first(): void
    {
        $defaults = PosFeatureService::defaultsForCategory('hotel');
        $this->assertTrue((bool) ($defaults['rooms'] ?? false));
        $this->assertFalse((bool) ($defaults['kitchen'] ?? false));
        $this->assertFalse((bool) ($defaults['tables'] ?? false));
        $this->assertFalse((bool) ($defaults['kot'] ?? false));
        $this->assertFalse(PosFeatureService::restaurantModeFrom($defaults));
        $this->assertSame('accommodation', PosCategoryProfiles::family('hotel'));
        $this->assertSame('NGT', PosCategoryProfiles::profile('hotel', 'pra')['unit']);
        $this->assertContains('rooms', PosCategoryProfiles::profile('hotel', 'pra')['checklist']);
        $groups = PosFeatureService::categoryGroups('pra');
        $this->assertContains('hotel', $groups['stay'] ?? []);
        $this->assertNotContains('hotel', $groups['food'] ?? []);
    }

    public function test_existing_hotel_keeps_stored_kitchen_and_does_not_silently_gain_rooms(): void
    {
        $company = new Company();
        $company->id = 910001;
        $company->name = 'Legacy Hotel';
        $company->product_type = 'pos';
        $company->business_category = 'hotel';
        $company->feature_flags = PosFeatureService::defaultsForCategory('restaurant');
        $company->restaurant_mode = true;
        $company->is_internal_account = true;
        $resolved = PosFeatureService::forCompany($company);
        $this->assertTrue($resolved->kitchen);
        $this->assertTrue($resolved->tables);
        $this->assertFalse($resolved->rooms);
        $this->assertTrue((bool) $company->restaurant_mode);
    }

    public function test_hotel_routes_carry_the_rooms_feature_gate(): void
    {
        $names = [
            'pos.hotel.dashboard', 'pos.hotel.rooms', 'pos.hotel.stays.index',
            'pos.hotel.stays.store', 'pos.hotel.folio.settle', 'pos.hotel.checkout-policy',
            'pos.hotel.rooms.store', 'pos.hotel.rooms.housekeeping',
        ];
        foreach ($names as $name) {
            $route = Route::getRoutes()->getByName($name);
            $this->assertNotNull($route, $name);
            $mw = $route->gatherMiddleware();
            $this->assertTrue(
                collect($mw)->contains(fn ($m) => is_string($m) && str_contains($m, 'feature:rooms')),
                "{$name} must sit behind feature:rooms"
            );
        }
    }

    public function test_feature_for_path_maps_hotel_and_housekeeping_urls(): void
    {
        $this->assertSame('hotel', PosAccessService::featureForPath('pos/hotel'));
        $this->assertSame('hotel', PosAccessService::featureForPath('pos/hotel/stays/9/folio/settle'));
        $this->assertSame('hotel_housekeeping', PosAccessService::featureForPath('/pos/hotel/rooms'));
        $this->assertSame('hotel_housekeeping', PosAccessService::featureForPath('pos/hotel/housekeeping'));
        $this->assertSame('hotel_housekeeping', PosAccessService::featureForPath('pos/hotel/rooms/3/housekeeping'));
        $this->assertContains('hotel', PosAccessService::FEATURES);
        $this->assertContains('hotel_housekeeping', PosAccessService::FEATURES);
    }

    public function test_hotel_access_roles_for_manager_cashier_and_housekeeping(): void
    {
        $owner = new User();
        $owner->role = 'company_admin';
        $owner->pos_role = 'pos_admin';
        $this->assertTrue(HotelAccessService::canFrontDesk($owner));
        $this->assertTrue(HotelAccessService::canManageRooms($owner));
        $this->assertTrue(HotelAccessService::canHousekeeping($owner));

        $manager = new User();
        $manager->role = 'staff';
        $manager->pos_role = 'pos_manager';
        $this->assertTrue(HotelAccessService::isManager($manager));
        $this->assertTrue(HotelAccessService::canFrontDesk($manager));
        $this->assertTrue(HotelAccessService::canManageRooms($manager));

        // Receptionist = POS cashier with front-desk access (no hospital roles).
        $receptionist = new User();
        $receptionist->role = 'staff';
        $receptionist->pos_role = 'pos_cashier';
        $receptionist->pos_custom_access = null;
        $this->assertTrue(HotelAccessService::canFrontDesk($receptionist));
        $this->assertFalse(HotelAccessService::canManageRooms($receptionist));
        $this->assertTrue(HotelAccessService::canHousekeeping($receptionist));

        $hkOnly = new User();
        $hkOnly->role = 'staff';
        $hkOnly->pos_role = 'pos_cashier';
        $hkOnly->pos_custom_access = json_encode(['dashboard', 'hotel_housekeeping']);
        $this->assertFalse(HotelAccessService::canFrontDesk($hkOnly));
        $this->assertTrue(HotelAccessService::canHousekeeping($hkOnly));
        $this->assertFalse(HotelAccessService::canManageRooms($hkOnly));

        $denied = new User();
        $denied->role = 'staff';
        $denied->pos_role = 'pos_cashier';
        $denied->pos_custom_access = json_encode(['dashboard', 'orders']);
        $this->assertFalse(HotelAccessService::canFrontDesk($denied));
        $this->assertFalse(HotelAccessService::canHousekeeping($denied));
        $this->assertFalse(HotelAccessService::canSeeOccupancy($denied));
        $this->assertTrue(HotelAccessService::canSeeOccupancy($hkOnly));
        $this->assertTrue(HotelAccessService::canSeeOccupancy($owner));

        // Hotel must not lean on Healthcare IPD roles.
        $ipd = new User();
        $ipd->role = 'staff';
        $ipd->pos_role = null;
        $ipd->health_role = 'health_receptionist';
        $this->assertFalse(HotelAccessService::canFrontDesk($ipd));
        $this->assertFalse(HotelAccessService::canHousekeeping($ipd));
    }

    public function test_hotel_grant_implies_housekeeping_in_custom_access(): void
    {
        $user = new User();
        $user->role = 'staff';
        $user->pos_role = 'pos_cashier';
        $user->pos_custom_access = json_encode(['dashboard', 'hotel']);
        $this->assertTrue(PosAccessService::customAllows($user, 'hotel'));
        $this->assertTrue(PosAccessService::customAllows($user, 'hotel_housekeeping'));
    }

    public function test_advance_credit_and_other_branch_keys_exist_in_all_languages(): void
    {
        $en = require base_path('lang/en/pos.php');
        $rur = require base_path('lang/rur/pos.php');
        $ur = require base_path('lang/ur/pos.php');
        foreach (['hotel_folio_advance_credit', 'hotel_room_other_branch', 'hotel_checkout_due_blocked', 'hotel_cat_food', 'hotel_from_service', 'hotel_status_checked_in', 'nav_hotel_front_desk'] as $key) {
            $this->assertArrayHasKey($key, $en);
            $this->assertArrayHasKey($key, $rur);
            $this->assertArrayHasKey($key, $ur);
            $this->assertSame(array_search($key, array_keys($en), true), array_search($key, array_keys($rur), true));
            $this->assertSame(array_search($key, array_keys($en), true), array_search($key, array_keys($ur), true));
        }
        $this->assertSame('Advance Credit', $en['hotel_folio_advance_credit']);
        $this->assertSame('Advance Credit', $rur['hotel_folio_advance_credit']);
        $this->assertMatchesRegularExpression('/[\x{0600}-\x{06FF}]/u', $ur['hotel_folio_advance_credit']);
        $this->assertMatchesRegularExpression('/[\x{0600}-\x{06FF}]/u', $ur['hotel_room_other_branch']);
    }

    public function test_overlap_queries_take_locking_reads(): void
    {
        $src = file_get_contents(app_path('Services/HotelStayService.php'));
        $this->assertNotFalse($src);
        $this->assertMatchesRegularExpression(
            '/function assertRoomFree[\s\S]*lockForUpdate\(\)->pluck\([\'"]id[\'"]\)[\s\S]*lockForUpdate\(\)->pluck\([\'"]id[\'"]\)/',
            $src
        );
    }
}
