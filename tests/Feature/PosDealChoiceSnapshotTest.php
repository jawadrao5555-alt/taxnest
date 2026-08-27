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
}