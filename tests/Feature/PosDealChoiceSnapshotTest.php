<?php

namespace Tests\Feature;

use App\Http\Controllers\PosController;
use App\Models\PosDeal;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Choice selections are resolved into the ordinary frozen POS deal snapshot.
 * The billing and inventory code then sees no special path: selected products
 * are consumed/restored exactly like fixed deal components.
 */
class PosDealChoiceSnapshotTest extends TestCase
{
    private int $companyId = 31;
    private int $otherCompanyId = 32;
    private int $fixedId;
    private int $pizzaId;
    private int $drinkId;
    private int $foreignId;
    private int $dealId;
    private int $pizzaGroupId;
    private int $drinkGroupId;

    protected function setUp(): void
    {
        parent::setUp();
        Schema::dropAllTables();

        Schema::create('companies', function (Blueprint $table) {
            $table->id();
            $table->boolean('inventory_enabled')->default(false);
            $table->softDeletes();
        });
        Schema::create('pos_products', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->string('name');
            $table->decimal('price', 10, 2)->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
        Schema::create('pos_deals', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->string('name');
            $table->decimal('price', 10, 2);
            $table->boolean('is_active')->default(true);
            $table->json('active_days')->nullable();
            $table->date('starts_on')->nullable();
            $table->date('ends_on')->nullable();
            $table->string('deal_type')->default('regular');
            $table->time('special_start_time')->nullable();
            $table->time('special_end_time')->nullable();
            $table->unsignedInteger('total_deal_units_limit')->nullable();
            $table->unsignedInteger('daily_deal_units_limit')->nullable();
            $table->timestamps();
        });
        Schema::create('pos_deal_items', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('deal_id');
            $table->unsignedBigInteger('pos_product_id');
            $table->unsignedInteger('quantity')->default(1);
            $table->timestamps();
        });
        Schema::create('pos_deal_choice_groups', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('deal_id');
            $table->string('label');
            $table->unsignedInteger('quantity')->default(1);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });
        Schema::create('pos_deal_choice_options', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('group_id');
            $table->unsignedBigInteger('pos_product_id');
            $table->timestamps();
        });

        DB::table('companies')->insert([
            ['id' => $this->companyId, 'inventory_enabled' => false],
            ['id' => $this->otherCompanyId, 'inventory_enabled' => false],
        ]);
        $this->fixedId = $this->product($this->companyId, 'Garlic Bread');
        $this->pizzaId = $this->product($this->companyId, 'Tikka Pizza');
        $this->drinkId = $this->product($this->companyId, 'Cola');
        $this->foreignId = $this->product($this->otherCompanyId, 'Foreign Product');
        $this->dealId = (int) DB::table('pos_deals')->insertGetId([
            'company_id' => $this->companyId, 'name' => 'Family Combo', 'price' => 999,
            'is_active' => true, 'deal_type' => 'regular', 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('pos_deal_items')->insert([
            'deal_id' => $this->dealId, 'pos_product_id' => $this->fixedId, 'quantity' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->pizzaGroupId = $this->group('Pizza Flavor', 1, 0, [$this->pizzaId]);
        $this->drinkGroupId = $this->group('Drink', 2, 1, [$this->drinkId]);
    }

    private function product(int $companyId, string $name): int
    {
        return (int) DB::table('pos_products')->insertGetId([
            'company_id' => $companyId, 'name' => $name, 'price' => 100,
            'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function group(string $label, int $quantity, int $order, array $productIds): int
    {
        $id = (int) DB::table('pos_deal_choice_groups')->insertGetId([
            'deal_id' => $this->dealId, 'label' => $label, 'quantity' => $quantity,
            'sort_order' => $order, 'created_at' => now(), 'updated_at' => now(),
        ]);
        foreach ($productIds as $productId) {
            DB::table('pos_deal_choice_options')->insert([
                'group_id' => $id, 'pos_product_id' => $productId,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        return $id;
    }

    private function resolve(array $choices): array
    {
        $deal = PosDeal::with(['items', 'choiceGroups.options'])->findOrFail($this->dealId);
        $method = new \ReflectionMethod(PosController::class, 'resolveDealChoiceSnapshot');
        $method->setAccessible(true);
        return $method->invoke(new PosController(), $deal, $choices, $this->companyId);
    }

    public function test_selected_products_are_frozen_and_expand_for_stock(): void
    {
        [$snapshot, $choices] = $this->resolve([
            ['group_id' => $this->pizzaGroupId, 'product_id' => $this->pizzaId],
            ['group_id' => $this->drinkGroupId, 'product_id' => $this->drinkId],
        ]);

        $this->assertSame([
            ['group_id' => $this->pizzaGroupId, 'product_id' => $this->pizzaId],
            ['group_id' => $this->drinkGroupId, 'product_id' => $this->drinkId],
        ], $choices);
        $this->assertSame($this->pizzaId, $snapshot[0]['product_id']);
        $this->assertSame(2, $snapshot[1]['qty']);

        $method = new \ReflectionMethod(PosController::class, 'expandDealComponentsForStock');
        $method->setAccessible(true);
        $expanded = $method->invoke(new PosController(), [[
            'type' => 'deal', 'item_id' => $this->dealId, 'quantity' => 3,
            'deal_snapshot' => $snapshot,
        ]]);
        $this->assertSame($this->pizzaId, $expanded[1]['item_id']);
        $this->assertSame(3.0, $expanded[1]['quantity']);
        $this->assertSame($this->drinkId, $expanded[2]['item_id']);
        $this->assertSame(6.0, $expanded[2]['quantity']);
    }

    public function test_missing_or_cross_company_choice_is_rejected(): void
    {
        $this->expectException(ValidationException::class);
        $this->resolve([
            ['group_id' => $this->pizzaGroupId, 'product_id' => $this->pizzaId],
            ['group_id' => $this->drinkGroupId, 'product_id' => $this->foreignId],
        ]);
    }

    public function test_every_configured_choice_group_is_required(): void
    {
        $this->expectException(ValidationException::class);
        $this->resolve([
            ['group_id' => $this->pizzaGroupId, 'product_id' => $this->pizzaId],
        ]);
    }

    public function test_special_deal_with_only_choice_groups_can_reserve_for_billing(): void
    {
        DB::table('pos_deal_items')->where('deal_id', $this->dealId)->delete();
        DB::table('pos_deals')->where('id', $this->dealId)->update([
            'deal_type' => 'special',
            'starts_on' => now()->toDateString(),
            'ends_on' => now()->toDateString(),
            'special_start_time' => '00:00',
            'special_end_time' => '23:59',
        ]);
        $choices = [
            ['group_id' => $this->pizzaGroupId, 'product_id' => $this->pizzaId],
            ['group_id' => $this->drinkGroupId, 'product_id' => $this->drinkId],
        ];
        [$snapshot] = $this->resolve($choices);

        $method = new \ReflectionMethod(PosController::class, 'reserveDealUnitsForInvoice');
        $method->setAccessible(true);
        $method->invoke(new PosController(), [[
            'type' => 'deal',
            'item_id' => $this->dealId,
            'name' => 'Family Combo',
            'price' => 999,
            'quantity' => 1,
            'deal_snapshot' => $snapshot,
            'deal_choices' => $choices,
        ]], $this->companyId);

        $this->assertCount(2, $snapshot);
        $this->assertSame($this->companyId, $snapshot[0]['tax_facts']['company_id']);
        $this->assertArrayHasKey('recipe_snapshot', $snapshot[0]);
    }

    public function test_required_choice_group_with_no_active_option_is_rejected(): void
    {
        DB::table('pos_products')->where('id', $this->pizzaId)->update(['is_active' => false]);

        $this->expectException(ValidationException::class);
        $this->resolve([
            ['group_id' => $this->pizzaGroupId, 'product_id' => $this->pizzaId],
            ['group_id' => $this->drinkGroupId, 'product_id' => $this->drinkId],
        ]);
    }

    public function test_deleted_authoritative_product_keeps_history_without_recreating_catalogue_row(): void
    {
        $deletedId = $this->pizzaId;
        DB::table('pos_products')->where('id', $deletedId)->delete();
        $before = DB::table('pos_products')->count();
        $method = new \ReflectionMethod(PosController::class, 'resolveItemExemptions');
        $method->setAccessible(true);

        $resolved = $method->invoke(new PosController(), [[
            'type' => 'product', 'item_id' => $deletedId, 'name' => 'Historical Pizza',
            'quantity' => 1, 'unit_price' => 100,
            'tax_snapshot' => ['exempt' => false, 'third_schedule' => false],
        ]], $this->companyId, null, true);

        $this->assertSame('product', $resolved[0]['type']);
        $this->assertSame('Historical Pizza', $resolved[0]['name']);
        $this->assertNull($resolved[0]['item_id']);
        $this->assertSame($before, DB::table('pos_products')->count());
    }

    public function test_authoritative_product_with_existing_foreign_id_is_rejected(): void
    {
        $method = new \ReflectionMethod(PosController::class, 'resolveItemExemptions');
        $method->setAccessible(true);
        $this->expectException(ValidationException::class);
        $method->invoke(new PosController(), [[
            'type' => 'product', 'item_id' => $this->foreignId, 'name' => 'Foreign Product',
            'quantity' => 1, 'unit_price' => 100,
            'tax_snapshot' => ['exempt' => false, 'third_schedule' => false],
        ]], $this->companyId, null, true);
    }

    public function test_rich_posted_deal_snapshot_with_tampered_tax_facts_is_rejected(): void
    {
        [$snapshot] = $this->resolve([
            ['group_id' => $this->pizzaGroupId, 'product_id' => $this->pizzaId],
            ['group_id' => $this->drinkGroupId, 'product_id' => $this->drinkId],
        ]);
        $snapshot[0]['tax_facts']['is_tax_exempt'] = !$snapshot[0]['tax_facts']['is_tax_exempt'];
        $method = new \ReflectionMethod(PosController::class, 'resolveItemExemptions');
        $method->setAccessible(true);
        $this->expectException(ValidationException::class);
        $method->invoke(new PosController(), [[
            'type' => 'deal', 'item_id' => $this->dealId, 'name' => 'Family Combo',
            'quantity' => 1, 'unit_price' => 999, 'deal_snapshot' => $snapshot,
            'deal_choices' => [
                ['group_id' => $this->pizzaGroupId, 'product_id' => $this->pizzaId],
                ['group_id' => $this->drinkGroupId, 'product_id' => $this->drinkId],
            ],
        ]], $this->companyId);
    }
}