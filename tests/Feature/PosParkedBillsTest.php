<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\PosHeldSale;
use App\Models\User;
use App\Services\PosFeatureService;
use App\Services\PosParkedBills;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class PosParkedBillsTest extends TestCase
{
    use RefreshDatabase;

    private function company(string $name = 'Plain Retail Shop'): Company
    {
        $company = Company::create([
            'name' => $name,
            'ntn' => (string) random_int(100000000, 999999999),
            'email' => uniqid('parked-', true) . '@test.pk',
            'status' => 'approved',
            'company_status' => 'active',
            'product_type' => 'pos',
            'pos_integration_mode' => 'pra',
            'is_internal_account' => true,
            'pos_setup_completed' => true,
            'restaurant_mode' => false,
            'feature_flags' => [],
        ]);

        PosFeatureService::flushGateCaches();

        return $company;
    }

    /** Kitchen shop: keeps the held-ORDER flow, must be refused this lane. */
    private function restaurantCompany(): Company
    {
        $company = $this->company('Kitchen Shop');
        $company->forceFill(['feature_flags' => ['kitchen' => true]])->save();
        PosFeatureService::flushGateCaches();

        return $company;
    }

    private function user(Company $company): User
    {
        return User::create([
            'name' => 'Retail Cashier',
            'email' => uniqid('parked-user-', true) . '@test.pk',
            'password' => Hash::make('Secret@12345'),
            'company_id' => $company->id,
            'role' => 'staff',
            'pos_role' => 'pos_cashier',
            'is_active' => true,
        ]);
    }

    private function cart(string $item = 'Tea'): array
    {
        return [
            'items' => [
                ['item_type' => 'manual', 'item_name' => $item, 'quantity' => 2, 'unit_price' => 125],
            ],
            'discount_type' => 'amount',
            'discount_value' => 10,
        ];
    }

    private function held(Company $company, array $attributes = []): PosHeldSale
    {
        return PosHeldSale::create(array_merge([
            'company_id' => $company->id,
            'hold_name' => 'Waiting customer',
            'total_amount' => 250,
            'item_count' => 1,
            'cart_data' => $this->cart(),
        ], $attributes));
    }

    public function test_store_parks_a_cart_and_replaying_the_same_hold_uuid_is_idempotent(): void
    {
        $company = $this->company();
        $payload = [
            'hold_name' => 'Blue shirt',
            'hold_uuid' => 'retail-hold-uuid-1',
            'total_amount' => 240,
            'cart_data' => $this->cart(),
        ];

        $first = $this->actingAs($this->user($company), 'pos')
            ->postJson(route('pos.held-sales.store'), $payload)
            ->assertOk()
            ->assertJson([
                'success' => true,
                'held' => ['name' => 'Blue shirt', 'total' => 240, 'items' => 1],
            ]);

        $id = $first->json('held.id');

        $this->postJson(route('pos.held-sales.store'), $payload)
            ->assertOk()
            ->assertJson([
                'success' => true,
                'duplicate' => true,
                'held' => ['id' => $id],
            ]);

        $this->assertSame(1, PosHeldSale::where('company_id', $company->id)->count());
        $this->assertDatabaseHas('pos_held_sales', [
            'id' => $id,
            'company_id' => $company->id,
            'hold_uuid' => 'retail-hold-uuid-1',
        ]);
    }

    public function test_index_lists_only_parked_bills_owned_by_the_callers_company(): void
    {
        $company = $this->company('First Retail Shop');
        $foreignCompany = $this->company('Second Retail Shop');
        $own = $this->held($company, ['hold_name' => 'Own bill']);
        $foreign = $this->held($foreignCompany, ['hold_name' => 'Foreign bill']);

        $response = $this->actingAs($this->user($company), 'pos')
            ->getJson(route('pos.held-sales.index'))
            ->assertOk()
            ->assertJson(['success' => true])
            ->assertJsonCount(1, 'held');

        $this->assertSame($own->id, $response->json('held.0.id'));
        $this->assertNotSame($foreign->id, $response->json('held.0.id'));
    }

    public function test_recall_returns_the_cart_deletes_it_and_cannot_succeed_twice(): void
    {
        $company = $this->company();
        $cart = $this->cart('Biscuits');
        $held = $this->held($company, ['hold_name' => 'Counter two', 'cart_data' => $cart]);
        $this->actingAs($this->user($company), 'pos');

        $this->postJson(route('pos.held-sales.recall', $held->id))
            ->assertOk()
            ->assertJson([
                'success' => true,
                'hold_name' => 'Counter two',
                'cart' => $cart,
            ]);

        $this->assertDatabaseMissing('pos_held_sales', ['id' => $held->id]);

        $second = $this->postJson(route('pos.held-sales.recall', $held->id));
        $this->assertContains($second->status(), [404, 409]);
        $second->assertJson(['success' => false]);
    }

    public function test_destroy_deletes_an_owned_row_but_cannot_delete_a_foreign_company_row(): void
    {
        $company = $this->company('Owner Shop');
        $foreignCompany = $this->company('Foreign Shop');
        $own = $this->held($company);
        $foreign = $this->held($foreignCompany);
        $this->actingAs($this->user($company), 'pos');

        $this->deleteJson(route('pos.held-sales.destroy', $own->id))
            ->assertOk()
            ->assertJson(['success' => true]);
        $this->assertDatabaseMissing('pos_held_sales', ['id' => $own->id]);

        $this->deleteJson(route('pos.held-sales.destroy', $foreign->id));
        $this->assertDatabaseHas('pos_held_sales', [
            'id' => $foreign->id,
            'company_id' => $foreignCompany->id,
        ]);
    }

    public function test_store_refuses_to_park_more_than_the_maximum_forty_bills(): void
    {
        $company = $this->company();
        for ($i = 1; $i <= 40; $i++) {
            $this->held($company, ['hold_name' => "Held {$i}", 'hold_uuid' => "cap-{$i}"]);
        }

        $this->actingAs($this->user($company), 'pos')
            ->postJson(route('pos.held-sales.store'), [
                'hold_uuid' => 'over-cap',
                'cart_data' => $this->cart(),
            ])
            ->assertStatus(422)
            ->assertJson(['success' => false]);

        $this->assertSame(40, PosHeldSale::where('company_id', $company->id)->count());
        $this->assertDatabaseMissing('pos_held_sales', ['hold_uuid' => 'over-cap']);
    }

    public function test_restaurant_hold_endpoint_redirects_plain_retail_to_the_retail_hold_flow(): void
    {
        $company = $this->company();

        $this->actingAs($this->user($company), 'pos')
            ->postJson(route('pos.restaurant.orders.hold'), [
                'items' => [[
                    'item_type' => 'manual',
                    'item_name' => 'Bottle',
                    'unit_price' => 100,
                    'quantity' => 1,
                ]],
                'order_type' => 'takeaway',
            ])
            ->assertStatus(422)
            ->assertJson([
                'success' => false,
                'use_retail_hold' => true,
            ]);
    }

    public function test_purge_before_day_respects_the_cutoff_and_count_is_company_scoped(): void
    {
        $company = $this->company('Closing Shop');
        $foreignCompany = $this->company('Other Closing Shop');
        $old = $this->held($company, ['hold_name' => 'Old']);
        $onDay = $this->held($company, ['hold_name' => 'On day']);
        $afterDay = $this->held($company, ['hold_name' => 'After day']);
        $foreign = $this->held($foreignCompany, ['hold_name' => 'Foreign old']);

        DB::table('pos_held_sales')->where('id', $old->id)->update(['created_at' => '2026-09-18 23:59:59']);
        DB::table('pos_held_sales')->where('id', $onDay->id)->update(['created_at' => '2026-09-19 00:00:00']);
        DB::table('pos_held_sales')->where('id', $afterDay->id)->update(['created_at' => '2026-09-20 08:00:00']);
        DB::table('pos_held_sales')->where('id', $foreign->id)->update(['created_at' => '2026-09-18 10:00:00']);

        $this->assertSame(3, PosParkedBills::count(PosParkedBills::PRA_TABLE, $company->id));
        $this->assertSame(1, PosParkedBills::count(PosParkedBills::PRA_TABLE, $foreignCompany->id));
        $this->assertSame(
            1,
            PosParkedBills::purgeBeforeDay(PosParkedBills::PRA_TABLE, $company->id, '2026-09-19')
        );

        $this->assertDatabaseMissing('pos_held_sales', ['id' => $old->id]);
        $this->assertDatabaseHas('pos_held_sales', ['id' => $onDay->id]);
        $this->assertDatabaseHas('pos_held_sales', ['id' => $afterDay->id]);
        $this->assertDatabaseHas('pos_held_sales', ['id' => $foreign->id]);
        $this->assertSame(2, PosParkedBills::count(PosParkedBills::PRA_TABLE, $company->id));
    }

    public function test_a_restaurant_shop_is_refused_every_parked_bill_endpoint(): void
    {
        $company = $this->restaurantCompany();
        $this->actingAs($this->user($company), 'pos');

        $this->postJson(route('pos.held-sales.store'), [
            'cart_data' => $this->cart(),
            'total_amount' => 240,
        ])->assertForbidden();

        $this->getJson(route('pos.held-sales.index'))->assertForbidden();
        $this->assertSame(0, PosHeldSale::where('company_id', $company->id)->count());
    }

    public function test_a_restaurant_shop_cannot_recall_or_discard_a_parked_bill(): void
    {
        // Row planted directly: the shop could only own one from an earlier
        // retail configuration, and it still must not reach this lane.
        $company = $this->restaurantCompany();
        $held = $this->held($company);
        $this->actingAs($this->user($company), 'pos');

        $this->postJson(route('pos.held-sales.recall', $held->id))->assertForbidden();
        $this->deleteJson(route('pos.held-sales.destroy', $held->id))->assertForbidden();

        $this->assertDatabaseHas('pos_held_sales', ['id' => $held->id]);
    }
}
