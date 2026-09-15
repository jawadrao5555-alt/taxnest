<?php

namespace Tests\Feature;

use App\Exceptions\HotelStayException;
use App\Models\Branch;
use App\Models\Company;
use App\Models\HotelStay;
use App\Models\PosProduct;
use App\Models\PosService;
use App\Models\User;
use App\Services\HotelCheckoutPolicy;
use App\Services\HotelFolioCatalog;
use App\Services\HotelFolioService;
use App\Services\HotelStayService;
use App\Services\PosFeatureService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Hotel / Guest House final delivery pass — policy, branch catalogs, isolation.
 * Does not replace HotelGuestHouseV1Test.
 */
class HotelGuestHouseFinalDeliveryTest extends TestCase
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
            'name' => 'Hotel Delivery ' . $category,
            'ntn' => (string) random_int(100000000, 999999999),
            'email' => uniqid('hotel-del-', true) . '@test.pk',
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
            'email' => uniqid('hotel-del-owner-', true) . '@test.pk',
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
            'name' => 'Hotel Staff ' . $posRole,
            'email' => uniqid('hotel-del-staff-', true) . '@test.pk',
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

    private function room(HotelStayService $stays, Company $company, string $number = '101', float $rate = 4000, ?int $branchId = null): \App\Models\HotelRoom
    {
        return $stays->createRoom((int) $company->id, [
            'room_number' => $number,
            'room_type' => 'Deluxe',
            'capacity' => 2,
            'rate_amount' => $rate,
            'rate_unit' => 'NGT',
            'branch_id' => $branchId,
        ]);
    }

    private function walkIn(HotelStayService $stays, Company $company, User $user, $room, string $guest = 'Guest'): HotelStay
    {
        return $stays->book((int) $company->id, (int) $user->id, [
            'room_id' => $room->id,
            'check_in_date' => now()->toDateString(),
            'check_out_date' => now()->addDays(2)->toDateString(),
            'guest_name' => $guest,
            'walk_in' => true,
        ]);
    }

    private function stock(Company $company, PosProduct $product, Branch $branch, float $qty): void
    {
        // sqlite keeps the original inventory_stocks.product_id → products FK
        // (the drop migration is MySQL-only). Mirror the POS row into products
        // so the stay-catalog fixture can stamp the same id live uses.
        if (Schema::hasTable('products') && !DB::table('products')->where('id', $product->id)->exists()) {
            $row = [
                'id' => $product->id,
                'company_id' => $company->id,
                'name' => $product->name,
                'default_price' => $product->price,
                'is_active' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ];
            if (Schema::hasColumn('products', 'hs_code')) {
                $row['hs_code'] = '0000.0000.00';
            }
            DB::table('products')->insert($row);
        }
        DB::table('inventory_stocks')->insert([
            'company_id' => $company->id,
            'product_id' => $product->id,
            'branch_id' => $branch->id,
            'quantity' => $qty,
            'min_stock_level' => 0,
            'avg_purchase_price' => 0,
            'last_purchase_price' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_missing_checkout_setting_allows_outstanding_checkout(): void
    {
        $company = $this->company();
        $this->assertNull($company->hotel_checkout_outstanding);
        $this->assertTrue(HotelCheckoutPolicy::allowsOutstandingCheckout($company));
        $this->assertSame(HotelCheckoutPolicy::ALLOW, HotelCheckoutPolicy::forCompany($company));

        $stays = app(HotelStayService::class);
        $user = $this->owner($company);
        $stay = $this->walkIn($stays, $company, $user, $this->room($stays, $company));
        $this->assertGreaterThan(0, app(HotelFolioService::class)->totals($stay)['outstanding']);
        $out = $stays->checkOut($stay, (int) $user->id);
        $this->assertSame(HotelStay::STATUS_CHECKED_OUT, $out->status);
    }

    public function test_block_policy_refuses_outstanding_checkout_and_allow_still_works(): void
    {
        $blocked = $this->company('hotel', ['hotel_checkout_outstanding' => 'block']);
        $allowed = $this->company('hotel', ['hotel_checkout_outstanding' => 'allow']);
        $stays = app(HotelStayService::class);
        $folio = app(HotelFolioService::class);

        $userB = $this->owner($blocked);
        $stayB = $this->walkIn($stays, $blocked, $userB, $this->room($stays, $blocked), 'Blocked Guest');
        $this->assertGreaterThan(0, $folio->totals($stayB)['outstanding']);
        try {
            $stays->checkOut($stayB, (int) $userB->id);
            $this->fail('blocked checkout should throw');
        } catch (HotelStayException $e) {
            $this->assertSame(__('pos.hotel_checkout_due_blocked', [
                'amount' => number_format((float) $folio->totals($stayB)['outstanding'], 2),
            ]), $e->getMessage());
        }
        $this->assertSame(HotelStay::STATUS_CHECKED_IN, $stayB->fresh()->status);

        $folio->postPayment($stayB, [
            'amount' => 8000,
            'payment_method' => 'cash',
            'idempotency_key' => 'block-pay',
        ], (int) $userB->id);
        $cleared = $stays->checkOut($stayB->fresh(), (int) $userB->id);
        $this->assertSame(HotelStay::STATUS_CHECKED_OUT, $cleared->status);

        $userA = $this->owner($allowed);
        $stayA = $this->walkIn($stays, $allowed, $userA, $this->room($stays, $allowed), 'Allowed Guest');
        $outA = $stays->checkOut($stayA, (int) $userA->id);
        $this->assertSame(HotelStay::STATUS_CHECKED_OUT, $outA->status);
    }

    public function test_feature_flag_rewrite_does_not_reset_checkout_policy(): void
    {
        $company = $this->company('hotel', ['hotel_checkout_outstanding' => 'block']);
        $flags = is_array($company->feature_flags) ? $company->feature_flags : PosFeatureService::defaultsForCategory('hotel');
        $company->update(PosFeatureService::featureUpdates($flags));
        $company->refresh();
        $this->assertSame('block', $company->hotel_checkout_outstanding);
        $this->assertTrue((bool) ($company->feature_flags['rooms'] ?? false));
    }

    public function test_checkout_policy_save_is_manager_only_and_preserves_peer_tenant(): void
    {
        $a = $this->company('hotel', ['hotel_checkout_outstanding' => 'allow']);
        $b = $this->company('hotel', ['hotel_checkout_outstanding' => 'block']);
        $ownerA = $this->owner($a);
        $hk = $this->staff($a, 'pos_cashier', ['dashboard', 'hotel_housekeeping']);

        $this->actingAs($hk, 'pos')
            ->post('/pos/hotel/checkout-policy', ['hotel_checkout_outstanding' => 'block'])
            ->assertRedirect();
        $this->assertSame('allow', $a->fresh()->hotel_checkout_outstanding);

        $this->actingAs($ownerA, 'pos')
            ->post('/pos/hotel/checkout-policy', ['hotel_checkout_outstanding' => 'block'])
            ->assertRedirect();
        $this->assertSame('block', $a->fresh()->hotel_checkout_outstanding);
        $this->assertSame('block', $b->fresh()->hotel_checkout_outstanding);
    }

    public function test_rooms_off_and_permissions_still_fail_closed(): void
    {
        $off = $this->company('hotel', [
            'feature_flags' => array_merge(PosFeatureService::defaultsForCategory('hotel'), ['rooms' => false]),
        ]);
        $on = $this->company('hotel');
        $this->actingAs($this->owner($off), 'pos')->get('/pos/hotel')->assertRedirect('/pos/dashboard');
        $denied = $this->staff($on, 'pos_cashier', ['dashboard', 'orders']);
        $this->actingAs($denied, 'pos')->get('/pos/hotel')->assertRedirect();
        $this->actingAs($denied, 'pos')->get('/pos/hotel/rooms')->assertRedirect();
        $this->actingAs($this->owner($on), 'pos')->get('/pos/hotel')->assertOk();
    }

    public function test_branch_catalog_hides_other_branch_stock_and_other_company_items(): void
    {
        $company = $this->company();
        $other = $this->company();
        $branchA = Branch::create(['company_id' => $company->id, 'name' => 'A', 'code' => 'HA', 'is_active' => true]);
        $branchB = Branch::create(['company_id' => $company->id, 'name' => 'B', 'code' => 'HB', 'is_active' => true]);

        $here = PosProduct::create(['company_id' => $company->id, 'name' => 'Minibar-A', 'price' => 250, 'is_active' => true]);
        $there = PosProduct::create(['company_id' => $company->id, 'name' => 'Minibar-B', 'price' => 300, 'is_active' => true]);
        $shared = PosProduct::create(['company_id' => $company->id, 'name' => 'Shared Extra', 'price' => 100, 'is_active' => true]);
        $foreign = PosProduct::create(['company_id' => $other->id, 'name' => 'Foreign Extra', 'price' => 9, 'is_active' => true]);
        $this->stock($company, $here, $branchA, 5);
        $this->stock($company, $there, $branchB, 5);

        $svcHere = PosService::create(['company_id' => $company->id, 'name' => 'Laundry Press', 'price' => 400, 'is_active' => true]);
        PosService::create(['company_id' => $other->id, 'name' => 'Foreign Spa', 'price' => 900, 'is_active' => true]);

        $names = HotelFolioCatalog::products((int) $company->id, (int) $branchA->id)->pluck('name');
        $this->assertTrue($names->contains('Minibar-A'));
        $this->assertTrue($names->contains('Shared Extra'));
        $this->assertFalse($names->contains('Minibar-B'));
        $this->assertFalse($names->contains('Foreign Extra'));
        $this->assertTrue(HotelFolioCatalog::serviceAllowed((int) $company->id, (int) $branchA->id, (int) $svcHere->id));
        $this->assertFalse(HotelFolioCatalog::productAllowed((int) $company->id, (int) $branchA->id, (int) $there->id));
        $this->assertFalse(HotelFolioCatalog::productAllowed((int) $company->id, (int) $branchA->id, (int) $foreign->id));
    }

    public function test_stay_form_and_post_refuse_other_branch_product(): void
    {
        $company = $this->company();
        $stays = app(HotelStayService::class);
        $folio = app(HotelFolioService::class);
        $user = $this->owner($company);
        $branchA = Branch::create(['company_id' => $company->id, 'name' => 'Tower A', 'code' => 'TA', 'is_active' => true]);
        $branchB = Branch::create(['company_id' => $company->id, 'name' => 'Tower B', 'code' => 'TB', 'is_active' => true]);
        $room = $this->room($stays, $company, '501', 4000, (int) $branchA->id);
        $stay = $stays->book((int) $company->id, (int) $user->id, [
            'room_id' => $room->id,
            'check_in_date' => '2026-09-20',
            'check_out_date' => '2026-09-22',
            'guest_name' => 'Catalog Guest',
        ]);
        $local = PosProduct::create(['company_id' => $company->id, 'name' => 'LOCAL-MINIBAR', 'price' => 120, 'is_active' => true]);
        $remote = PosProduct::create(['company_id' => $company->id, 'name' => 'REMOTE-SPA-KIT', 'price' => 880, 'is_active' => true]);
        $this->stock($company, $local, $branchA, 3);
        $this->stock($company, $remote, $branchB, 3);
        $service = PosService::create(['company_id' => $company->id, 'name' => 'PRESS-SERVICE', 'price' => 150, 'is_active' => true]);

        $html = $this->actingAs($user, 'pos')
            ->withSession(['active_branch_id' => $branchA->id])
            ->get('/pos/hotel/stays/' . $stay->id)
            ->assertOk()
            ->assertSee('LOCAL-MINIBAR', false)
            ->assertDontSee('REMOTE-SPA-KIT', false)
            ->assertSee('PRESS-SERVICE', false)
            ->assertSee(__('pos.hotel_cat_food'), false)
            ->assertSee(\App\Support\PosPaymentLabels::label('cash'), false)
            ->assertSee(\App\Support\PosPaymentLabels::label('qr_payment'), false)
            ->assertSee('overflow-x-auto', false)
            ->getContent();
        $this->assertStringNotContainsString('>Debit card<', $html);
        $this->assertStringNotContainsString('>QR</option>', $html);

        $this->expectException(HotelStayException::class);
        $folio->postCharge($stay, [
            'description' => '',
            'category' => 'extra',
            'quantity' => 1,
            'unit_amount' => 880,
            'product_id' => $remote->id,
        ], (int) $user->id);
    }

    public function test_service_charge_posts_on_own_stay_and_invoice_history_survives_checkout(): void
    {
        $company = $this->company();
        $stays = app(HotelStayService::class);
        $folio = app(HotelFolioService::class);
        $user = $this->owner($company);
        $stay = $this->walkIn($stays, $company, $user, $this->room($stays, $company, '601', 4000), 'Invoice Guest');
        $service = PosService::create(['company_id' => $company->id, 'name' => 'Room Breakfast', 'price' => 500, 'is_active' => true]);
        $folio->postCharge($stay, [
            'category' => 'food',
            'quantity' => 1,
            'unit_amount' => 0,
            'service_id' => $service->id,
            'idempotency_key' => 'svc-1',
        ], (int) $user->id);
        $folio->postPayment($stay, [
            'amount' => 8500,
            'payment_method' => 'cash',
            'idempotency_key' => 'svc-pay',
        ], (int) $user->id);
        $settled = $folio->settleCoveredCharges($stay->fresh(), (int) $user->id, 'cash', 'svc-inv');
        $this->assertNotNull($settled['transaction']);
        $invoiceId = $settled['transaction']->id;
        $this->assertNotNull(\App\Models\HotelFolioEntry::where('stay_id', $stay->id)->where('pos_transaction_id', $invoiceId)->first());

        $stays->checkOut($stay->fresh(), (int) $user->id);
        $this->actingAs($user, 'pos')
            ->get('/pos/hotel/stays/' . $stay->id)
            ->assertOk()
            ->assertSee(__('pos.hotel_fiscal_bill'), false)
            ->assertSee((string) $settled['transaction']->invoice_number, false);
        $this->assertSame($invoiceId, \App\Models\HotelFolioEntry::where('stay_id', $stay->id)->where('entry_type', 'charge')->whereNotNull('pos_transaction_id')->value('pos_transaction_id'));
    }

    public function test_occupancy_counts_all_in_house_dues_for_dashboard_strip(): void
    {
        $company = $this->company('hotel', [
            'pos_tax_rate_cash' => 0,
            'pos_tax_rate_card' => 0,
        ]);
        $stays = app(HotelStayService::class);
        $folio = app(HotelFolioService::class);
        $user = $this->owner($company);
        $dueStay = $this->walkIn($stays, $company, $user, $this->room($stays, $company, '701', 5000), 'Due Guest');
        $paidStay = $this->walkIn($stays, $company, $user, $this->room($stays, $company, '702', 5000), 'Paid Guest');
        $folio->postPayment($paidStay, [
            'amount' => 10000,
            'payment_method' => 'cash',
            'idempotency_key' => 'occ-pay-cleared',
        ], (int) $user->id);

        $occupancy = $stays->occupancy((int) $company->id, null);
        $this->assertSame(2, $occupancy['in_house']);
        $this->assertSame(1, $occupancy['pending_due_count']);
        $this->assertGreaterThan(0.009, $occupancy['pending_due_amount']);
        $viewer = $stays->occupancyForViewer($user, $company, null);
        $this->assertSame(1, $viewer['pending_due_count'] ?? 0);

        $dueLabel = preg_quote(__('pos.hotel_stat_due'), '/');
        $html = $this->actingAs($user, 'pos')
            ->get('/pos/hotel')
            ->assertOk()
            ->assertSee(__('pos.hotel_stat_due'), false)
            ->getContent();
        $this->assertMatchesRegularExpression(
            '/'.$dueLabel.'[\s\S]{0,400}?\b1\b/u',
            $html
        );

        $folio->postPayment($dueStay, [
            'amount' => 10000,
            'payment_method' => 'cash',
            'idempotency_key' => 'occ-pay-due',
        ], (int) $user->id);
        $cleared = $stays->occupancy((int) $company->id, null);
        $this->assertSame(0, $cleared['pending_due_count']);
        $this->assertEquals(0.0, $cleared['pending_due_amount']);
    }

    public function test_board_pending_list_truncation_does_not_shrink_occupancy_due_count(): void
    {
        $company = $this->company('hotel', [
            'pos_tax_rate_cash' => 0,
            'pos_tax_rate_card' => 0,
        ]);
        $stays = app(HotelStayService::class);
        $user = $this->owner($company);
        for ($i = 1; $i <= 21; $i++) {
            $this->walkIn(
                $stays,
                $company,
                $user,
                $this->room($stays, $company, sprintf('8%02d', $i), 1000),
                'Due Guest '.$i
            );
        }
        $board = $stays->board((int) $company->id, null);
        $this->assertSame(21, $board['occupancy']['in_house']);
        $this->assertSame(21, $board['occupancy']['pending_due_count']);
        $this->assertCount(20, $board['pending']);
        $this->assertGreaterThan(0.009, $board['occupancy']['pending_due_amount']);
    }

    public function test_tenant_cannot_open_foreign_stay_or_policy_page_side_effect(): void
    {
        $a = $this->company();
        $b = $this->company();
        $stays = app(HotelStayService::class);
        $ownerA = $this->owner($a);
        $stay = $this->walkIn($stays, $a, $ownerA, $this->room($stays, $a), 'Iso Guest');
        $this->actingAs($this->owner($b), 'pos')
            ->get('/pos/hotel/stays/' . $stay->id)
            ->assertRedirect('/pos/dashboard');
        $this->actingAs($this->owner($b), 'pos')
            ->post('/pos/hotel/stays/' . $stay->id . '/check-out')
            ->assertRedirect('/pos/dashboard');
        $this->assertSame(HotelStay::STATUS_CHECKED_IN, $stay->fresh()->status);
    }
}
