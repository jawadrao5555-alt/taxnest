<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\Company;
use App\Models\RestaurantOrder;
use App\Services\KotPrintService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Task 909 — VOID SLIP MULTI-STATION ROUTING.
 *
 * When a company has ≥2 active kitchen stations and a waiter cancels
 * an order whose KOT-printed items span those stations,
 * KotPrintService::enqueueVoid must create ONE kot_void print job per
 * station — each carrying ONLY that station's removed dishes — so the
 * void slip reaches every counter that received the original KOT.
 *
 * Pattern mirrors PosStationSplitKotDeltaSnapshotTest: sqlite :memory:
 * minimal schema + direct service call. The test also pins the
 * zero-station fallback (single job on the company printer) and the
 * counter-copy honour (full list, dine-in orders).
 */
class KotVoidStationRoutingTest extends TestCase
{
    private int $grillStationId;
    private int $tandoorStationId;

    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropAllTables();

        Schema::create('companies', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('company_status')->default('approved');
            $table->string('agent_api_key')->nullable();
            $table->boolean('agent_enabled')->default(false);
            $table->timestamp('agent_last_seen')->nullable();
            $table->text('pos_printer_settings')->nullable();
            $table->softDeletes();
            $table->timestamps();
        });

        Schema::create('pos_stations', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->string('name');
            $table->text('categories')->nullable();
            $table->string('printer_name')->nullable();
            $table->boolean('is_active')->default(true);
            $table->integer('sort')->default(0);
            $table->timestamps();
        });

        // mapItems does a bulk category lookup against pos_products.
        Schema::create('pos_products', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->string('name');
            $table->string('category')->nullable();
            $table->timestamps();
        });

        Schema::create('restaurant_orders', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->string('order_number');
            $table->string('order_type')->default('dine_in');
            $table->string('status')->default('held');
            $table->timestamps();
        });

        Schema::create('restaurant_order_items', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('order_id');
            $table->string('item_type')->default('manual');
            $table->unsignedBigInteger('item_id')->nullable();
            $table->string('item_name');
            $table->decimal('quantity', 8, 2)->default(1);
            $table->decimal('unit_price', 12, 2)->default(0);
            $table->decimal('subtotal', 12, 2)->default(0);
            $table->timestamp('kot_printed_at')->nullable();
            $table->integer('kot_batch_no')->nullable();
            $table->timestamps();
        });

        Schema::create('pos_print_jobs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->string('type');
            $table->string('target_printer')->nullable();
            $table->unsignedBigInteger('transaction_id')->nullable();
            $table->unsignedBigInteger('restaurant_order_id')->nullable();
            $table->text('render_query')->nullable();
            $table->text('printed_item_ids')->nullable();
            $table->string('status')->default('pending');
            $table->string('claim_token')->nullable();
            $table->integer('attempts')->default(0);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->text('error')->nullable();
            $table->timestamps();
        });
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    /**
     * Make an agent-online company. $extra can override printer settings.
     *
     * NOTE: pos_printer_settings is cast as `array` on the Company model —
     * pass a plain PHP array (NOT json_encode) or Eloquent double-encodes it
     * and printerSettings() sees a string, making silent_print_enabled false.
     */
    private function makeCompany(array $printerSettings = []): Company
    {
        $defaults = [
            'silent_print_enabled' => true,
            'kot_printer'          => 'KitchenPrinter',
            'counter_kot_enabled'  => false,
            'counter_kot_printer'  => null,
        ];
        $company = Company::forceCreate([
            'name'                 => 'Station Void Co',
            'agent_enabled'        => true,
            'agent_last_seen'      => now(),
            'pos_printer_settings' => array_merge($defaults, $printerSettings),
        ]);
        app()->instance('currentCompanyId', $company->id);
        return $company;
    }

    /** Create Grill + Tandoor stations, return their ids. */
    private function makeStations(int $companyId): void
    {
        $this->grillStationId = (int) DB::table('pos_stations')->insertGetId([
            'company_id'   => $companyId,
            'name'         => 'Grill',
            'categories'   => json_encode(['BBQ']),
            'printer_name' => 'GrillPrinter',
            'is_active'    => true,
            'sort'         => 1,
            'created_at'   => now(), 'updated_at' => now(),
        ]);
        $this->tandoorStationId = (int) DB::table('pos_stations')->insertGetId([
            'company_id'   => $companyId,
            'name'         => 'Tandoor',
            'categories'   => json_encode(['Bread']),
            'printer_name' => 'TandoorPrinter',
            'is_active'    => true,
            'sort'         => 2,
            'created_at'   => now(), 'updated_at' => now(),
        ]);
        // Products: one per category so mapItems can route by product id.
        DB::table('pos_products')->insert([
            ['id' => 101, 'company_id' => $companyId, 'name' => 'Seekh Kabab', 'category' => 'BBQ',   'created_at' => now(), 'updated_at' => now()],
            ['id' => 102, 'company_id' => $companyId, 'name' => 'Garlic Naan', 'category' => 'Bread', 'created_at' => now(), 'updated_at' => now()],
        ]);
    }

    private function makeOrder(int $companyId, string $orderType = 'dine_in'): RestaurantOrder
    {
        return RestaurantOrder::forceCreate([
            'company_id'   => $companyId,
            'order_number' => 'ORD-909',
            'order_type'   => $orderType,
            'status'       => 'held',
        ]);
    }

    /** Decode the render_query (JSON payload) of a kot_void job. */
    private function decodeVoidPayload(object $job): array
    {
        $decoded = json_decode($job->render_query, true);
        $this->assertIsArray($decoded, "render_query must be a JSON array; got: {$job->render_query}");
        return $decoded;
    }

    /** Build a void-item array shaped as enqueueVoid expects. */
    private function voidItem(string $type, int $id, string $name, float $qty): array
    {
        return ['item_type' => $type, 'item_id' => $id, 'item_name' => $name, 'notes' => '', 'qty' => $qty];
    }

    // ── tests ─────────────────────────────────────────────────────────────────

    /**
     * Core regression: two product items from different station categories →
     * two kot_void jobs, each carrying only the dish that belongs to it.
     */
    public function test_enqueue_void_fans_out_one_job_per_station(): void
    {
        $c = $this->makeCompany();
        $this->makeStations($c->id);
        $order = $this->makeOrder($c->id);

        $voidItems = [
            $this->voidItem('product', 101, 'Seekh Kabab', 2.0), // → Grill
            $this->voidItem('product', 102, 'Garlic Naan',  1.0), // → Tandoor
        ];

        $result = KotPrintService::enqueueVoid($c, $order, $voidItems, userId: null);

        $this->assertTrue($result['printed'], 'enqueueVoid must succeed');
        $this->assertCount(2, $result['job_ids'], 'one void job per station (two stations involved)');

        $jobs = DB::table('pos_print_jobs')
            ->where('company_id', $c->id)
            ->where('type', 'kot_void')
            ->orderBy('id')
            ->get();

        $this->assertCount(2, $jobs, 'exactly two kot_void rows created');

        $byPrinter = $jobs->keyBy('target_printer')->all();
        $this->assertArrayHasKey('GrillPrinter',   $byPrinter, 'Grill station must get its own void job');
        $this->assertArrayHasKey('TandoorPrinter',  $byPrinter, 'Tandoor station must get its own void job');

        // Grill job: only Seekh Kabab (qty 2).
        $grillItems = $this->decodeVoidPayload($byPrinter['GrillPrinter']);
        $this->assertCount(1, $grillItems, 'Grill void slip must contain exactly one item');
        $this->assertSame('Seekh Kabab', $grillItems[0]['item_name']);
        $this->assertEquals(2.0, (float) $grillItems[0]['qty']);

        // Tandoor job: only Garlic Naan (qty 1).
        $tandoorItems = $this->decodeVoidPayload($byPrinter['TandoorPrinter']);
        $this->assertCount(1, $tandoorItems, 'Tandoor void slip must contain exactly one item');
        $this->assertSame('Garlic Naan', $tandoorItems[0]['item_name']);
        $this->assertEquals(1.0, (float) $tandoorItems[0]['qty']);
    }

    /**
     * A waiter double-tapping cancel must reuse each station's in-flight void
     * job instead of creating a second physical slip for the kitchen.
     */
    public function test_rapid_duplicate_void_enqueue_creates_only_one_job_per_station(): void
    {
        $c = $this->makeCompany();
        $this->makeStations($c->id);
        $order = $this->makeOrder($c->id);

        $voidItems = [
            $this->voidItem('product', 101, 'Seekh Kabab', 2.0),
            $this->voidItem('product', 102, 'Garlic Naan', 1.0),
        ];

        // SQLite ignores lockForUpdate. This is its deterministic twin of the
        // MySQL race: the order lock serializes simultaneous taps, so the
        // second caller observes the first caller's committed station jobs.
        $first = KotPrintService::enqueueVoid($c, $order, $voidItems, userId: null);
        $second = KotPrintService::enqueueVoid($c, $order, $voidItems, userId: null);

        $this->assertTrue($first['printed']);
        $this->assertTrue($second['printed']);
        $this->assertSame($first['job_ids'], $second['job_ids']);
        $this->assertCount(2, $second['job_ids'], 'the second enqueue reuses one job per station');
        $this->assertSame(2, DB::table('pos_print_jobs')
            ->where('company_id', $c->id)
            ->where('type', 'kot_void')
            ->count());
    }

    /**
     * A second, different cancellation for the same order and printer must
     * remain a new kitchen instruction even while the first is pending.
     */
    public function test_distinct_void_payloads_for_same_station_create_separate_jobs(): void
    {
        $c = $this->makeCompany();
        $this->makeStations($c->id);
        $order = $this->makeOrder($c->id);

        DB::table('pos_products')->insert([
            'id' => 103, 'company_id' => $c->id, 'name' => 'Chicken Tikka', 'category' => 'BBQ',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        KotPrintService::enqueueVoid($c, $order, [
            $this->voidItem('product', 101, 'Seekh Kabab', 1.0),
        ], userId: null);
        KotPrintService::enqueueVoid($c, $order, [
            $this->voidItem('product', 103, 'Chicken Tikka', 1.0),
        ], userId: null);

        $jobs = DB::table('pos_print_jobs')
            ->where('company_id', $c->id)
            ->where('type', 'kot_void')
            ->where('target_printer', 'GrillPrinter')
            ->orderBy('id')
            ->get();

        $this->assertCount(2, $jobs);
        $this->assertSame('Seekh Kabab', $this->decodeVoidPayload($jobs[0])[0]['item_name']);
        $this->assertSame('Chicken Tikka', $this->decodeVoidPayload($jobs[1])[0]['item_name']);
    }

    /**
     * When one station has MULTIPLE removed dishes and the other has one,
     * the split must still assign each item to its correct station.
     */
    public function test_enqueue_void_multiple_items_same_station_and_one_other(): void
    {
        $c = $this->makeCompany();
        $this->makeStations($c->id);
        $order = $this->makeOrder($c->id);

        // Add a second BBQ product.
        DB::table('pos_products')->insert([
            'id' => 103, 'company_id' => $c->id, 'name' => 'Chicken Tikka', 'category' => 'BBQ',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $voidItems = [
            $this->voidItem('product', 101, 'Seekh Kabab',   2.0), // → Grill
            $this->voidItem('product', 103, 'Chicken Tikka', 1.0), // → Grill
            $this->voidItem('product', 102, 'Garlic Naan',   3.0), // → Tandoor
        ];

        $result = KotPrintService::enqueueVoid($c, $order, $voidItems, userId: null);

        $this->assertTrue($result['printed']);
        $this->assertCount(2, $result['job_ids']);

        $byPrinter = DB::table('pos_print_jobs')
            ->where('type', 'kot_void')
            ->get()->keyBy('target_printer')->all();

        $grillItems = $this->decodeVoidPayload($byPrinter['GrillPrinter']);
        $this->assertCount(2, $grillItems, 'Grill must hold both BBQ items');
        $grillNames = array_column($grillItems, 'item_name');
        $this->assertContains('Seekh Kabab',   $grillNames);
        $this->assertContains('Chicken Tikka', $grillNames);

        $tandoorItems = $this->decodeVoidPayload($byPrinter['TandoorPrinter']);
        $this->assertCount(1, $tandoorItems, 'Tandoor must hold only the Bread item');
        $this->assertSame('Garlic Naan', $tandoorItems[0]['item_name']);
        $this->assertEquals(3.0, (float) $tandoorItems[0]['qty']);
    }

    /**
     * An item with an unknown/unmapped category (or a manual item without
     * an item_id) falls into the DEFAULT station (id 0), which routes to
     * the company KOT printer, not any named-station printer.
     */
    public function test_unmatched_item_routes_to_company_kot_printer(): void
    {
        $c = $this->makeCompany();
        $this->makeStations($c->id);
        $order = $this->makeOrder($c->id);

        $voidItems = [
            $this->voidItem('product', 101, 'Seekh Kabab', 1.0),  // → Grill
            $this->voidItem('manual',  0,   'Special Dish', 1.0), // → DEFAULT (KitchenPrinter)
        ];

        $result = KotPrintService::enqueueVoid($c, $order, $voidItems, userId: null);

        $this->assertTrue($result['printed']);
        $this->assertCount(2, $result['job_ids'], 'two buckets: Grill + default');

        $byPrinter = DB::table('pos_print_jobs')
            ->where('type', 'kot_void')
            ->get()->keyBy('target_printer')->all();

        $this->assertArrayHasKey('GrillPrinter',   $byPrinter);
        $this->assertArrayHasKey('KitchenPrinter',  $byPrinter, 'default bucket → company KOT printer');

        $defaultItems = $this->decodeVoidPayload($byPrinter['KitchenPrinter']);
        $this->assertSame('Special Dish', $defaultItems[0]['item_name']);
    }

    /**
     * Zero active stations → single void job on the company KOT printer
     * carrying the full void list (legacy behaviour preserved).
     */
    public function test_enqueue_void_zero_stations_creates_single_job_on_company_printer(): void
    {
        $c = $this->makeCompany();
        // No stations created → feature dormant.
        $order = $this->makeOrder($c->id);

        $voidItems = [
            $this->voidItem('product', 101, 'Biryani', 2.0),
            $this->voidItem('product', 102, 'Raita',   1.0),
        ];

        $result = KotPrintService::enqueueVoid($c, $order, $voidItems, userId: null);

        $this->assertTrue($result['printed']);
        $this->assertCount(1, $result['job_ids'], 'no stations → one combined void job');

        $job = DB::table('pos_print_jobs')->where('type', 'kot_void')->first();
        $this->assertSame('KitchenPrinter', $job->target_printer);

        $items = $this->decodeVoidPayload($job);
        $this->assertCount(2, $items, 'single job carries the full void list');
    }

    /**
     * Counter copy for dine-in orders: when counter_kot_enabled is true,
     * enqueueVoid creates an extra kot_void job on the counter printer
     * carrying the FULL void list regardless of station split.
     */
    public function test_enqueue_void_counter_copy_receives_full_void_list_for_dine_in(): void
    {
        $c = $this->makeCompany([
            'counter_kot_enabled' => true,
            'counter_kot_printer' => 'CounterPrinter',
        ]);
        $this->makeStations($c->id);
        $order = $this->makeOrder($c->id, 'dine_in');

        $voidItems = [
            $this->voidItem('product', 101, 'Seekh Kabab', 2.0),
            $this->voidItem('product', 102, 'Garlic Naan', 1.0),
        ];

        $result = KotPrintService::enqueueVoid($c, $order, $voidItems, userId: null);

        $this->assertTrue($result['printed']);

        $jobs = DB::table('pos_print_jobs')->where('type', 'kot_void')->get();
        // Grill + Tandoor station jobs + 1 counter copy = 3 total.
        $this->assertCount(3, $jobs, 'two station jobs + one counter copy');

        $byPrinter = $jobs->keyBy('target_printer')->all();
        $this->assertArrayHasKey('CounterPrinter', $byPrinter, 'counter copy must be created');

        $counterItems = $this->decodeVoidPayload($byPrinter['CounterPrinter']);
        $counterNames = array_column($counterItems, 'item_name');
        $this->assertContains('Seekh Kabab', $counterNames, 'counter copy must list every voided dish');
        $this->assertContains('Garlic Naan', $counterNames, 'counter copy must list every voided dish');
        $this->assertCount(2, $counterItems);
    }

    /**
     * Counter copy is NOT produced for takeaway / delivery orders even when
     * counter_kot_enabled is true. (Mirrors the same policy for normal KOTs.)
     */
    public function test_enqueue_void_counter_copy_skipped_for_takeaway(): void
    {
        $c = $this->makeCompany([
            'counter_kot_enabled' => true,
            'counter_kot_printer' => 'CounterPrinter',
        ]);
        $this->makeStations($c->id);
        $order = $this->makeOrder($c->id, 'takeaway'); // NOT dine_in

        $voidItems = [
            $this->voidItem('product', 101, 'Seekh Kabab', 1.0),
            $this->voidItem('product', 102, 'Garlic Naan', 1.0),
        ];

        $result = KotPrintService::enqueueVoid($c, $order, $voidItems, userId: null);

        $this->assertTrue($result['printed']);

        $jobs = DB::table('pos_print_jobs')->where('type', 'kot_void')->get();
        $this->assertCount(2, $jobs, 'takeaway: station jobs only, no counter copy');

        $printers = $jobs->pluck('target_printer')->toArray();
        $this->assertNotContains('CounterPrinter', $printers, 'counter copy must not fire for takeaway');
    }

    /**
     * An empty void list (nothing printed yet, cancel before KOT) must return
     * printed=true with zero jobs — no unnecessary print jobs created.
     */
    public function test_enqueue_void_empty_list_returns_success_with_no_jobs(): void
    {
        $c = $this->makeCompany();
        $this->makeStations($c->id);
        $order = $this->makeOrder($c->id);

        $result = KotPrintService::enqueueVoid($c, $order, [], userId: null);

        $this->assertTrue($result['printed']);
        $this->assertCount(0, $result['job_ids']);
        $this->assertSame(0, DB::table('pos_print_jobs')->where('type', 'kot_void')->count());
    }

    /**
     * Agent offline → enqueueVoid returns printed=false with reason 'agent_offline'
     * and creates NO print jobs (the waiter app falls back to iframe URL instead).
     */
    public function test_enqueue_void_agent_offline_returns_not_printed(): void
    {
        $c = $this->makeCompany();
        // Override agent_last_seen to simulate offline.
        $c->agent_last_seen = now()->subMinutes(10);
        $c->save();

        $this->makeStations($c->id);
        $order = $this->makeOrder($c->id);

        $voidItems = [
            $this->voidItem('product', 101, 'Seekh Kabab', 1.0),
        ];

        $result = KotPrintService::enqueueVoid($c, $order, $voidItems, userId: null);

        $this->assertFalse($result['printed']);
        $this->assertSame('agent_offline', $result['reason']);
        $this->assertSame(0, DB::table('pos_print_jobs')->where('type', 'kot_void')->count());
    }
}
