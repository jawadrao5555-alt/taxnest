<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\User;
use App\Services\HotelStayService;
use App\Services\PosFeatureService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class HotelSettingsCategoryTest extends TestCase
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

    public function test_rooms_only_property_has_six_groups_and_preserves_saved_settings(): void
    {
        [$company, $owner] = $this->fixture(['pos_theme' => 'blue', 'pos_quick_type_enabled' => true]);
        $before = $company->getAttributes();
        $response = $this->actingAs($owner, 'pos')->get('/pos/customize');
        $response->assertOk()->assertViewIs('pos.hotel.settings')
            ->assertSee('data-hotel-settings="1"', false)
            ->assertDontSee('data-hotel-settings-outlet="1"', false)
            ->assertDontSee('/pos/settings/kds-auto-print', false)
            ->assertDontSee('/pos/settings/waiter-permission', false)
            ->assertDontSee('/pos/settings/local-billing/clear-archived', false);
        $this->assertSame(6, substr_count($response->getContent(), 'data-hotel-settings-card='));
        $this->assertEquals($before, $company->fresh()->getAttributes());
    }

    public function test_enabled_outlet_controls_are_separate_and_preserved(): void
    {
        $flags = PosFeatureService::defaultsForCategory('hotel');
        $flags['kitchen'] = $flags['kot'] = $flags['tables'] = true;
        [$company, $owner] = $this->fixture(['feature_flags' => $flags, 'restaurant_mode' => true]);
        $this->actingAs($owner, 'pos')->get('/pos/customize')->assertOk()
            ->assertSee('data-hotel-settings-outlet="1"', false)
            ->assertSee('/pos/settings/kds-auto-print', false);
        $this->postJson('/pos/settings/kds-auto-print', ['enabled' => true])->assertOk()->assertJson(['success' => true]);
        $this->assertTrue((bool) $company->fresh()->pos_kds_auto_print);
        $this->assertEquals($flags, $company->fresh()->feature_flags);
    }

    public function test_disabled_rooms_flag_keeps_category_settings_and_role_gates(): void
    {
        $flags = PosFeatureService::defaultsForCategory('hotel');
        $flags['rooms'] = false;
        $company = $this->company('hotel', ['feature_flags' => $flags]);
        $owner = $this->owner($company);
        $this->actingAs($owner, 'pos')->get('/pos/customize')->assertOk()->assertViewIs('pos.hotel.settings');
        $cashier = $this->staff($company, 'pos_cashier', ['hotel', 'settings']);
        $this->actingAs($cashier, 'pos')->postJson('/pos/settings/theme', ['theme' => 'rose'])->assertForbidden();
        $this->assertFalse((bool) $company->fresh()->feature_flags['rooms']);
    }

    public function test_room_only_property_rejects_direct_outlet_setting_writes(): void
    {
        [$company, $owner] = $this->fixture();
        $this->actingAs($owner, 'pos')->postJson('/pos/settings/kds-auto-print', ['enabled' => true])->assertForbidden();
        $this->postJson('/pos/settings/waiter-permission', ['permission' => 'takeaway', 'enabled' => true])->assertForbidden();
        $this->postJson('/pos/settings/quick-type', ['enabled' => true])->assertForbidden();
        $this->assertFalse((bool) $company->fresh()->pos_kds_auto_print);
    }

    public function test_outlet_without_kot_does_not_expose_or_write_kds_printing(): void
    {
        $flags = PosFeatureService::defaultsForCategory('hotel');
        $flags['kitchen'] = true;
        $flags['kot'] = false;
        [$company, $owner] = $this->fixture(['feature_flags' => $flags, 'restaurant_mode' => true]);
        $this->actingAs($owner, 'pos')->get('/pos/customize')->assertOk()
            ->assertSee('data-hotel-settings-outlet="1"', false)
            ->assertDontSee('/pos/settings/kds-auto-print', false);
        $this->postJson('/pos/settings/kds-auto-print', ['enabled' => true])->assertForbidden();
        $this->assertFalse((bool) $company->fresh()->pos_kds_auto_print);
    }

    public function test_entitled_caller_controls_remain_available_when_switch_is_off(): void
    {
        [$company, $owner] = $this->fixture(['caller_id_enabled' => false]);
        PosFeatureService::grantExtra($company, 'caller_id_enabled', 'admin', 'Test optional caller module');
        PosFeatureService::flushGateCaches();
        $profile = \App\Services\HotelSettingsProfile::for($company->fresh(), $owner);
        $this->assertTrue($profile['caller']);
        $this->actingAs($owner, 'pos')->get('/pos/customize')->assertOk()
            ->assertSee('/pos/settings/caller-id', false);
        $this->postJson('/pos/settings/caller-id', ['enabled' => true])->assertOk()->assertJson(['ok' => true, 'enabled' => true]);
        $this->assertTrue((bool) $company->fresh()->caller_id_enabled);
    }

    public function test_reception_and_housekeeping_cannot_write_property_settings(): void
    {
        [$company] = $this->fixture(['pos_theme' => 'blue']);
        foreach ([['hotel', 'settings'], ['hotel_housekeeping', 'settings']] as $custom) {
            $staff = $this->staff($company, 'pos_cashier', $custom);
            $this->actingAs($staff, 'pos')->get('/pos/customize')->assertForbidden();
            $this->postJson('/pos/settings/theme', ['theme' => 'rose'])->assertForbidden();
            $this->postJson('/pos/settings/tax-pricing-mode', ['mode' => 'inclusive'])->assertForbidden();
        }
        $this->assertSame('blue', $company->fresh()->pos_theme);
    }

    public function test_theme_changes_only_the_authenticated_company(): void
    {
        [$company, $owner] = $this->fixture(['pos_theme' => 'blue']);
        $other = $this->company('hotel', ['pos_theme' => 'emerald']);
        $this->actingAs($owner, 'pos')->postJson('/pos/settings/theme', ['theme' => 'rose', 'company_id' => $other->id])
            ->assertOk()->assertJson(['success' => true]);
        $this->assertSame('rose', $company->fresh()->pos_theme);
        $this->assertSame('emerald', $other->fresh()->pos_theme);
    }

    public function test_manager_cannot_clear_local_history_or_reset_numbering(): void
    {
        [$company] = $this->fixture();
        $manager = $this->staff($company, 'pos_manager');
        $this->actingAs($manager, 'pos')->get('/pos/customize')->assertOk()
            ->assertDontSee('data-hotel-account-link="branches"', false);
        $this->postJson('/pos/settings/local-billing/clear-archived')->assertForbidden();
        $this->postJson('/pos/settings/local-billing/reset-numbering')->assertForbidden();
    }

    public function test_expired_outlet_and_whatsapp_entitlements_do_not_reinterpret_saved_flags(): void
    {
        $flags = PosFeatureService::defaultsForCategory('hotel');
        $flags['kitchen'] = $flags['kot'] = $flags['tables'] = true;
        [$company, $owner] = $this->fixture(['feature_flags' => $flags, 'restaurant_mode' => true,
            'is_internal_account' => false, 'pos_whatsapp_bill_enabled' => true]);
        // No active package, add-on or override: fail closed even if legacy flags remain ON.
        PosFeatureService::flushGateCaches();
        $profile = \App\Services\HotelSettingsProfile::for($company, $owner);
        $this->assertFalse($profile['outlet']);
        $this->assertFalse($profile['whatsapp']);
        $this->assertEquals($flags, $company->fresh()->feature_flags);
        $this->assertTrue((bool) $company->fresh()->restaurant_mode);
        $this->assertTrue((bool) $company->fresh()->pos_whatsapp_bill_enabled);
    }

    public function test_existing_restaurant_keeps_generic_customize_and_kitchen_setting(): void
    {
        $company = $this->company('restaurant');
        $owner = $this->owner($company);
        $this->actingAs($owner, 'pos')->get('/pos/customize')->assertOk()->assertViewIs('pos.customize')
            ->assertDontSee('data-hotel-settings="1"', false);
        $this->postJson('/pos/settings/kds-auto-print', ['enabled' => true])->assertOk();
        $this->assertTrue((bool) $company->fresh()->pos_kds_auto_print);
    }
}
