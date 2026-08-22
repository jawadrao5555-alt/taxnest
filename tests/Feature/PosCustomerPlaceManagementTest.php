<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\PosCustomer;
use App\Models\PosCustomerPlace;
use App\Models\PosDeliveryCompletion;
use App\Models\PosRider;
use App\Models\PosTransaction;
use App\Models\PricingPlan;
use App\Models\Subscription;
use App\Models\User;
use App\Services\PosFeatureService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class PosCustomerPlaceManagementTest extends TestCase
{
    use RefreshDatabase;

    private function company(string $name, bool $internal = true): Company
    {
        $company = Company::create([
            'name' => $name,
            'ntn' => (string) random_int(1000000, 9999999) . random_int(10, 99),
            'email' => strtolower(str_replace(' ', '', $name)) . uniqid() . '@test.pk',
            'status' => 'approved',
            'company_status' => 'active',
            'product_type' => 'pos',
            'pos_integration_mode' => 'pra',
            'is_internal_account' => $internal,
            'feature_flags' => ['kot' => true, 'kitchen' => true],
        ]);
        PosFeatureService::flushGateCaches();
        return $company;
    }

    private function user(Company $company, string $posRole = 'pos_admin'): User
    {
        return User::create([
            'name' => 'Place Manager',
            'email' => uniqid('places-', true) . '@test.pk',
            'password' => Hash::make('Secret@12345'),
            'company_id' => $company->id,
            'role' => $posRole === 'pos_admin' ? 'company_admin' : 'staff',
            'pos_role' => $posRole,
            'is_active' => true,
        ]);
    }

    private function customer(Company $company, string $phone): PosCustomer
    {
        return PosCustomer::create([
            'company_id' => $company->id,
            'name' => 'Customer ' . $phone,
            'phone' => $phone,
        ]);
    }

    private function place(Company $company, PosCustomer $customer, array $attrs = []): PosCustomerPlace
    {
        return PosCustomerPlace::create(array_merge([
            'company_id' => $company->id,
            'customer_id' => $customer->id,
            'customer_phone' => $customer->phone,
            'place_type' => 'home',
            'label' => 'Home gate',
            'lat' => 31.5204,
            'lng' => 74.3587,
            'is_verified' => true,
            'verified_at' => now(),
            'last_used_at' => now()->subDay(),
            'usage_count' => 2,
            'created_from' => 'rider',
        ], $attrs));
    }

    public function test_private_map_data_is_company_scoped_and_contains_no_customer_identity(): void
    {
        $shop = $this->company('Own Shop');
        $other = $this->company('Other Shop');
        $owner = $this->user($shop);
        $ownCustomer = $this->customer($shop, '03001111111');
        $otherCustomer = $this->customer($other, '03002222222');
        $ownPlace = $this->place($shop, $ownCustomer);
        $otherPlace = $this->place($other, $otherCustomer, ['lat' => 33.0, 'lng' => 73.0]);
        $ownRider = PosRider::create(['company_id' => $shop->id, 'name' => 'Own Rider', 'is_active' => true]);
        $otherRider = PosRider::create(['company_id' => $other->id, 'name' => 'Other Rider', 'is_active' => true]);

        $ownCapturedAt = now()->subHour();
        PosDeliveryCompletion::create([
            'company_id' => $shop->id,
            'transaction_id' => 101,
            'rider_id' => $ownRider->id,
            'customer_place_id' => $ownPlace->id,
            'place_type' => 'home',
            'completed_lat' => 31.52041,
            'completed_lng' => 74.35871,
            'captured_at' => $ownCapturedAt,
            'proximity_verified' => true,
            'evidence_source' => 'gps',
        ]);
        $otherCapturedAt = now()->subHour();
        PosDeliveryCompletion::create([
            'company_id' => $other->id,
            'transaction_id' => 202,
            'rider_id' => $otherRider->id,
            'customer_place_id' => $otherPlace->id,
            'place_type' => 'business',
            'completed_lat' => 33.0,
            'completed_lng' => 73.0,
            'captured_at' => $otherCapturedAt,
            'proximity_verified' => true,
            'evidence_source' => 'gps',
        ]);
        DB::table('pos_rider_locations')->insert([
            [
                'company_id' => $shop->id,
                'rider_id' => $ownRider->id,
                'lat' => 31.5198,
                'lng' => 74.3581,
                'recorded_at' => $ownCapturedAt->copy()->subMinutes(2),
                'created_at' => $ownCapturedAt->copy()->subMinutes(2),
            ],
            [
                'company_id' => $shop->id,
                'rider_id' => $ownRider->id,
                'lat' => 31.5203,
                'lng' => 74.3586,
                'recorded_at' => $ownCapturedAt->copy()->subMinute(),
                'created_at' => $ownCapturedAt->copy()->subMinute(),
            ],
            [
                'company_id' => $other->id,
                'rider_id' => $otherRider->id,
                'lat' => 33.0005,
                'lng' => 73.0005,
                'recorded_at' => $otherCapturedAt->copy()->subMinutes(2),
                'created_at' => $otherCapturedAt->copy()->subMinutes(2),
            ],
            [
                'company_id' => $other->id,
                'rider_id' => $otherRider->id,
                'lat' => 33.0002,
                'lng' => 73.0002,
                'recorded_at' => $otherCapturedAt->copy()->subMinute(),
                'created_at' => $otherCapturedAt->copy()->subMinute(),
            ],
        ]);

        $response = $this->actingAs($owner, 'pos')
            ->getJson(route('pos.riders.tracking.places.data'));

        $response->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonCount(1, 'places')
            ->assertJsonCount(1, 'arrivals')
            ->assertJsonCount(1, 'approaches')
            ->assertJsonPath('places.0.id', $ownPlace->id)
            ->assertJsonPath('arrivals.0.rider', 'Own Rider')
            ->assertJsonPath('approaches.0.place_id', $ownPlace->id);
        $body = $response->getContent();
        $this->assertStringNotContainsString('03001111111', $body);
        $this->assertStringNotContainsString('03002222222', $body);
        $this->assertStringNotContainsString('Other Rider', $body);
        $this->assertStringNotContainsString('33.0005', $body);
        $this->assertStringNotContainsString((string) $otherPlace->id . ',"type":"business"', $body);
    }

    public function test_owner_can_correct_merge_and_soft_remove_same_customer_places(): void
    {
        $shop = $this->company('Merge Shop');
        $owner = $this->user($shop);
        $customer = $this->customer($shop, '03003333333');
        $source = $this->place($shop, $customer, ['label' => 'Old pin', 'usage_count' => 3]);
        $target = $this->place($shop, $customer, [
            'label' => 'Correct gate',
            'place_type' => 'business',
            'lat' => 31.521,
            'lng' => 74.359,
            'usage_count' => 5,
        ]);
        $rider = PosRider::create(['company_id' => $shop->id, 'name' => 'Rider', 'is_active' => true]);
        $bill = PosTransaction::create([
            'company_id' => $shop->id,
            'invoice_number' => 'PLACE-MERGE-1',
            'customer_id' => $customer->id,
            'customer_name' => $customer->name,
            'customer_phone' => $customer->phone,
            'status' => 'completed',
            'total_amount' => 500,
            'payment_method' => 'cash',
            'order_type' => 'delivery',
            'customer_place_id' => $source->id,
        ]);
        $completion = PosDeliveryCompletion::create([
            'company_id' => $shop->id,
            'transaction_id' => $bill->id,
            'rider_id' => $rider->id,
            'customer_place_id' => $source->id,
            'place_type' => 'home',
            'completed_lat' => $source->lat,
            'completed_lng' => $source->lng,
            'captured_at' => now(),
            'evidence_source' => 'gps',
        ]);

        $this->actingAs($owner, 'pos')
            ->patch(route('pos.riders.tracking.places.update', $target->id), [
                'place_type' => 'business',
                'label' => 'Loading bay',
                'address' => 'Blue shutter',
                'lat' => 31.5211,
                'lng' => 74.3591,
            ])->assertRedirect();
        $this->assertSame('Loading bay', $target->fresh()->label);

        $this->actingAs($owner, 'pos')
            ->post(route('pos.riders.tracking.places.merge', $source->id), [
                'target_id' => $target->id,
            ])->assertRedirect();

        $source = PosCustomerPlace::withTrashed()->findOrFail($source->id);
        $this->assertNotNull($source->deleted_at);
        $this->assertSame((int) $target->id, (int) $source->merged_into_id);
        $this->assertSame(8, (int) $target->fresh()->usage_count);
        $this->assertSame((int) $target->id, (int) $bill->fresh()->customer_place_id);
        $this->assertSame((int) $target->id, (int) $completion->fresh()->customer_place_id);

        $this->actingAs($owner, 'pos')
            ->delete(route('pos.riders.tracking.places.destroy', $target->id))
            ->assertRedirect();
        $this->assertSoftDeleted('pos_customer_places', ['id' => $target->id]);
        $this->assertDatabaseHas('pos_delivery_completions', [
            'id' => $completion->id,
            'customer_place_id' => $target->id,
        ]);
    }

    public function test_cross_customer_merge_cross_tenant_edit_cashier_and_locked_plan_are_denied(): void
    {
        $shop = $this->company('Secure Shop');
        $other = $this->company('Foreign Shop');
        $owner = $this->user($shop);
        $a = $this->place($shop, $this->customer($shop, '03004444444'));
        $b = $this->place($shop, $this->customer($shop, '03005555555'));
        $foreign = $this->place($other, $this->customer($other, '03006666666'));

        $this->actingAs($owner, 'pos')
            ->postJson(route('pos.riders.tracking.places.merge', $a->id), ['target_id' => $b->id])
            ->assertStatus(422);
        $this->assertNull($a->fresh()->deleted_at);

        $this->actingAs($owner, 'pos')
            ->patchJson(route('pos.riders.tracking.places.update', $foreign->id), [
                'place_type' => 'other', 'label' => 'Hijack',
                'lat' => 31.5, 'lng' => 74.3,
            ])->assertNotFound();
        $this->assertNotSame('Hijack', $foreign->fresh()->label);

        $cashier = $this->user($shop, 'pos_cashier');
        DB::table('users')->where('id', $cashier->id)->update([
            'pos_custom_access' => json_encode(['riders']),
        ]);
        $this->actingAs($cashier->fresh(), 'pos')
            ->getJson(route('pos.riders.tracking.places.data'))
            ->assertForbidden()
            ->assertJsonPath('error', 'admin_required');

        $locked = $this->company('Locked Shop', false);
        $restaurantOnly = PricingPlan::create([
            'name' => 'Restaurant without tracking',
            'product_type' => 'pos',
            'is_trial' => false,
            'invoice_limit' => 100,
            'price' => 1000,
            'price_quarterly' => 300,
        ]);
        PricingPlan::whereKey($restaurantOnly->id)->update([
            'restaurant_enabled' => true,
            'riders_enabled' => true,
            'rider_tracking_enabled' => false,
        ]);
        Subscription::create([
            'company_id' => $locked->id,
            'pricing_plan_id' => $restaurantOnly->id,
            'billing_cycle' => 'annual',
            'final_price' => 1000,
            'start_date' => now()->toDateString(),
            'end_date' => now()->addYear()->toDateString(),
            'active' => true,
            'override_type' => 'none',
        ]);
        PosFeatureService::flushGateCaches();
        $lockedOwner = $this->user($locked);
        $this->actingAs($lockedOwner, 'pos')
            ->getJson(route('pos.riders.tracking.places.data'))
            ->assertForbidden()
            ->assertJsonPath('error', 'plan_locked');
    }
}