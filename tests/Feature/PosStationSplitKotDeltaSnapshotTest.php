<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Http\Controllers\PosController;
use App\Models\Company;
use App\Models\User;
use App\Services\KotPrintService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * STATION-SPLIT KOT DELTA SNAPSHOT — Task #572 (extends Task #567's counter-copy
 * regression lock to the MULTI-COUNTER / PosStations scenario).
 *
 * When a company has active PosStations, ONE kitchen-send fans out into
 * MULTIPLE delta jobs (render_query station=ID&delta=1) plus the optional
 * counter copy. The agent prints them sequentially and stamps
 * kot_printed_at only at RESULT time — so the first station's success would
 * empty every later station's delta (whereNull finds nothing → 204 → that
 * counter never gets its slip) if jobs re-derived the delta at render time.
 *
 * The fix (already in the code, locked by NO test until now for the station
 * branch): the unprinted item-id snapshot (printed_item_ids) is baked into
 * EVERY job of the send at ENQUEUE time — station jobs AND counter copy —
 * and render consumes the baked set, THEN applies the station filter via
 * PosStation::prepareTicket. Locked here for BOTH enqueue paths:
 *   • cashier path  — PosController::apiCreatePrintJob station-split branch
 *   • waiter path   — KotPrintService::enqueueForOrder station-split branch
 *
 * Pattern mirrors PosCounterKotDeltaSnapshotTest: sqlite :memory: minimal
 * schema; enqueue via direct controller call; agent render/result via real
 * HTTP with the bearer agent key.
 *
 * NEVER "fix" a failure here by re-deriving the delta set at render time —
 * that is exactly the race this file exists to prevent.
 */
class PosStationSplitKotDeltaSnapshotTest extends TestCase
{
    private string $agentKey = 'test-agent-key-stationkot';

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
            $table->boolean('pos_kot_full_mode')->default(false);
            $table->string('order_match_style')->default('off');
            $table->string('default_language')->nullable();
            $table->boolean('kot_compact')->default(false);
            $table->boolean('kot_show_customer')->default(true);
            $table->boolean('kot_show_orderby')->default(true);
            $table->boolean('kot_show_barcode')->default(false);
            $table->boolean('kot_show_footer')->default(true);
            $table->boolean('kot_show_kitchen_notes')->default(true);
            $table->boolean('kot_align_center')->default(false);
            $table->unsignedInteger('kot_left_margin_mm')->default(0);
            $table->softDeletes();
            $table->timestamps();
        });

        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id')->nullable();
            $table->string('name')->nullable();
            $table->string('pos_role')->nullable();
            $table->string('language')->nullable();
            $table->text('pos_custom_access')->nullable();
            $table->timestamps();
        });

        Schema::create('pos_print_jobs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->string('type');
            $table->string('target_printer')->nullable();
            $table->unsignedBigInteger('transaction_id')->nullable();
            $table->unsignedBigInteger('restaurant_order_id')->nullable();
            $table->string('render_query')->nullable();
            $table->text('printed_item_ids')->nullable();
            $table->string('status')->default('pending');
            $table->string('claim_token')->nullable();
            $table->unsignedInteger('attempts')->default(0);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->text('error')->nullable();
            $table->timestamps();
        });

        Schema::create('restaurant_orders', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->string('order_number');
            $table->unsignedBigInteger('table_id')->nullable();
            $table->string('order_type')->default('dine_in');
            $table->string('status')->default('active');
            $table->string('customer_name')->nullable();
            $table->text('kitchen_notes')->nullable();
            $table->boolean('priority')->default(false);
            $table->timestamp('kot_sent_at')->nullable();
            $table->unsignedInteger('kot_print_count')->default(0);
            $table->unsignedInteger('token_no')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
        });

        Schema::create('restaurant_order_items', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('order_id');
            $table->string('item_type')->default('manual');
            $table->unsignedBigInteger('item_id')->nullable();
            $table->string('item_name');
            $table->decimal('quantity', 8, 2)->default(1);
            $table->decimal('unit_price', 10, 2)->default(0);
            $table->decimal('subtotal', 10, 2)->default(0);
            $table->text('special_notes')->nullable();
            $table->timestamp('kot_printed_at')->nullable();
            $table->unsignedInteger('kot_batch_no')->nullable();
            $table->timestamps();
        });

        Schema::create('pos_stations', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->string('name');
            $table->text('categories')->nullable();
            $table->string('printer_name')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort')->default(0);
            $table->timestamps();
        });

        // mapItems does ONE bulk category lookup against pos_products.
        Schema::create('pos_products', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->string('name');
            $table->string('category')->nullable();
            $table->timestamps();
        });

        DB::table('companies')->insert([
            'id' => 1,
            'name' => 'Station KOT Co',
            'agent_api_key' => $this->agentKey,
            'agent_enabled' => true,
            'agent_last_seen' => now(),
            'pos_printer_settings' => json_encode([
                'silent_print_enabled' => true,
                'kot_printer' => 'KitchenPrinter',
                'counter_kot_printer' => 'CounterPrinter',
                'counter_kot_enabled' => true,
            ]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Two ACTIVE stations, each with its own printer + claimed category.
        $this->grillStationId = (int) DB::table('pos_stations')->insertGetId([
            'company_id' => 1, 'name' => 'Grill', 'categories' => json_encode(['BBQ']),
            'printer_name' => 'GrillPrinter', 'is_active' => true, 'sort' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->tandoorStationId = (int) DB::table('pos_stations')->insertGetId([
            'company_id' => 1, 'name' => 'Tandoor', 'categories' => json_encode(['Bread']),
            'printer_name' => 'TandoorPrinter', 'is_active' => true, 'sort' => 2,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        // Products: one per station category.
        DB::table('pos_products')->insert([
            ['id' => 101, 'company_id' => 1, 'name' => 'Seekh Kabab', 'category' => 'BBQ', 'created_at' => now(), 'updated_at' => now()],
            ['id' => 102, 'company_id' => 1, 'name' => 'Garlic Naan', 'category' => 'Bread', 'created_at' => now(), 'updated_at' => now()],
        ]);

        app()->instance('currentCompanyId', 1);
        Cache::flush();
    }

    // ── Helpers ──────────────────────────────────────────────────────────

    private function makePosUser(): User
    {
        $user = User::forceCreate(['company_id' => 1, 'name' => 'Admin', 'pos_role' => 'pos_admin']);
        Auth::guard('pos')->setUser($user);
        return $user;
    }

    /**
     * Order with PRODUCT items (station routing needs item_type=product).
     * $items = [item_name => product_id]; $printed = names already stamped.
     */
    private function makeOrder(array $items, array $printed = []): int
    {
        $orderId = DB::table('restaurant_orders')->insertGetId([
            'company_id' => 1,
            'order_number' => 'ORD-260813-STN1',
            'order_type' => 'dine_in',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        foreach ($items as $name => $productId) {
            DB::table('restaurant_order_items')->insert([
                'order_id' => $orderId,
                'item_type' => 'product',
                'item_id' => $productId,
                'item_name' => $name,
                'quantity' => 1,
                'unit_price' => 100,
                'subtotal' => 100,
                'kot_printed_at' => in_array($name, $printed, true) ? now()->subMinutes(10) : null,
                'kot_batch_no' => in_array($name, $printed, true) ? 1 : null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
        return $orderId;
    }

    /** Cashier enqueue — direct controller call, mirrors the sale-screen POST. */
    private function enqueue(int $orderId, bool $delta = true)
    {
        $request = Request::create('/pos/api/print-jobs', 'POST', [
            'type' => 'kot',
            'restaurant_order_id' => $orderId,
            'delta' => $delta ? 1 : 0,
        ]);
        $request->setLaravelSession(app('session.store'));
        return app(PosController::class)->apiCreatePrintJob($request);
    }

    private function agentGetContent(int $jobId): \Illuminate\Testing\TestResponse
    {
        return $this->get('/api/agent/print-jobs/' . $jobId . '/content', [
            'Authorization' => 'Bearer ' . $this->agentKey,
        ]);
    }

    private function agentReportSuccess(int $jobId): void
    {
        $this->postJson('/api/agent/print-jobs/' . $jobId . '/result', ['success' => true], [
            'Authorization' => 'Bearer ' . $this->agentKey,
        ])->assertOk();
    }

    /** All jobs for an order keyed by target_printer, asserting expected printers. */
    private function jobsByPrinter(int $orderId, array $expectedPrinters): array
    {
        $jobs = DB::table('pos_print_jobs')->where('restaurant_order_id', $orderId)->orderBy('id')->get();
        $out = [];
        foreach ($expectedPrinters as $printer) {
            $job = $jobs->firstWhere('target_printer', $printer);
            $this->assertNotNull($job, "job for {$printer} enqueued");
            $out[$printer] = $job;
        }
        $this->assertCount(count($expectedPrinters), $jobs, 'no extra jobs enqueued');
        return $out;
    }

    private function bakedIds(object $jobRow): array
    {
        return array_map('intval', (array) json_decode($jobRow->printed_item_ids ?? '[]', true));
    }

    /** Markup after </head> — <title>/CSS legitimately carry no item names. */
    private function ticketBody(string $html): string
    {
        $pos = strpos($html, '</head>');
        return $pos === false ? $html : substr($html, $pos);
    }

    // ── 1. Cashier path: station fan-out bakes the snapshot into EVERY job,
    //      and the second station still prints after the first's success ────

    public function test_station_jobs_and_counter_copy_all_bake_snapshot_and_survive_the_race(): void
    {
        $this->makePosUser();
        $orderId = $this->makeOrder(['Seekh Kabab' => 101, 'Garlic Naan' => 102]);

        $res = $this->enqueue($orderId);
        $data = $res->getData(true);
        $this->assertTrue($data['success'] ?? false);
        $this->assertCount(2, $data['job_ids'], 'one job per station with items');

        $jobs = $this->jobsByPrinter($orderId, ['GrillPrinter', 'TandoorPrinter', 'CounterPrinter']);
        $grill = $jobs['GrillPrinter'];
        $tandoor = $jobs['TandoorPrinter'];
        $counter = $jobs['CounterPrinter'];

        // Station jobs are pinned via render_query; counter copy is unfiltered.
        $this->assertSame('station=' . $this->grillStationId . '&delta=1', $grill->render_query);
        $this->assertSame('station=' . $this->tandoorStationId . '&delta=1', $tandoor->render_query);
        $this->assertSame('delta=1', $counter->render_query);

        // EVERY job of the send bakes the SAME full unprinted snapshot.
        $itemIds = DB::table('restaurant_order_items')->where('order_id', $orderId)->pluck('id')->map(fn ($i) => (int) $i)->all();
        $this->assertEqualsCanonicalizing($itemIds, $this->bakedIds($grill), 'grill job bakes the snapshot');
        $this->assertEqualsCanonicalizing($itemIds, $this->bakedIds($tandoor), 'tandoor job bakes the SAME snapshot');
        $this->assertEqualsCanonicalizing($itemIds, $this->bakedIds($counter), 'counter copy bakes the SAME snapshot');

        // Job A (Grill): renders ONLY its own station's item, then succeeds
        // (stamps kot_printed_at on the grill row).
        $a = $this->agentGetContent($grill->id);
        $a->assertOk();
        $this->assertStringContainsString('Seekh Kabab', $a->getContent());
        $this->assertStringNotContainsString('Garlic Naan', $this->ticketBody($a->getContent()), 'station filter excludes other stations\' items');
        $this->agentReportSuccess($grill->id);
        $this->assertNotNull(DB::table('restaurant_order_items')->where('item_name', 'Seekh Kabab')->value('kot_printed_at'), 'grill success stamps its row');

        // Job B (Tandoor): rendered AFTER A's stamping — the exact race.
        // MUST return content (200), never 204, with ONLY its own item.
        $b = $this->agentGetContent($tandoor->id);
        $this->assertSame(200, $b->getStatusCode(), 'second station must render content, not 204');
        $this->assertStringContainsString('Garlic Naan', $b->getContent());
        $this->assertStringNotContainsString('Seekh Kabab', $this->ticketBody($b->getContent()), 'station filter still applies on the baked set');
        $this->agentReportSuccess($tandoor->id);

        // Counter copy renders LAST (after BOTH stations stamped) — still the
        // full slip with both items.
        $c = $this->agentGetContent($counter->id);
        $this->assertSame(200, $c->getStatusCode(), 'counter copy must render after both stations printed');
        $this->assertStringContainsString('Seekh Kabab', $c->getContent());
        $this->assertStringContainsString('Garlic Naan', $c->getContent());
    }

    public function test_later_job_success_does_not_restamp_earlier_rows(): void
    {
        $this->makePosUser();
        $orderId = $this->makeOrder(['Seekh Kabab' => 101, 'Garlic Naan' => 102]);
        $this->enqueue($orderId);
        $jobs = $this->jobsByPrinter($orderId, ['GrillPrinter', 'TandoorPrinter', 'CounterPrinter']);

        $this->agentGetContent($jobs['GrillPrinter']->id)->assertOk();
        $this->agentReportSuccess($jobs['GrillPrinter']->id);
        $stampAfterA = DB::table('restaurant_order_items')->where('item_name', 'Seekh Kabab')->first();

        $this->agentGetContent($jobs['TandoorPrinter']->id)->assertOk();
        $this->agentReportSuccess($jobs['TandoorPrinter']->id);
        $this->agentGetContent($jobs['CounterPrinter']->id)->assertOk();
        $this->agentReportSuccess($jobs['CounterPrinter']->id);

        // The grill row keeps its ORIGINAL stamp + batch (whereNull-guarded).
        $final = DB::table('restaurant_order_items')->where('item_name', 'Seekh Kabab')->first();
        $this->assertSame($stampAfterA->kot_printed_at, $final->kot_printed_at, 'no restamp by later jobs');
        $this->assertSame($stampAfterA->kot_batch_no, $final->kot_batch_no, 'no re-batch by later jobs');
    }

    // ── 2. Edit path: only the station with NEW rows gets a job; its baked
    //      delta survives the counter copy printing first ───────────────────

    public function test_edit_path_only_station_with_new_rows_fires_and_survives_reverse_order(): void
    {
        $this->makePosUser();
        // Grill item already printed; the recall adds one Tandoor item.
        $orderId = $this->makeOrder(['Seekh Kabab' => 101, 'Garlic Naan' => 102], printed: ['Seekh Kabab']);
        $newId = (int) DB::table('restaurant_order_items')->where('item_name', 'Garlic Naan')->value('id');

        $res = $this->enqueue($orderId);
        $this->assertTrue($res->getData(true)['success']);

        // NO grill job (its bucket has no unprinted rows) — only Tandoor + counter.
        $jobs = $this->jobsByPrinter($orderId, ['TandoorPrinter', 'CounterPrinter']);
        $this->assertSame([$newId], $this->bakedIds($jobs['TandoorPrinter']), 'station delta bakes only the new row');
        $this->assertSame([$newId], $this->bakedIds($jobs['CounterPrinter']), 'counter delta bakes only the new row');

        // Reverse order this time: counter prints FIRST (stamps the new row)…
        $c = $this->agentGetContent($jobs['CounterPrinter']->id);
        $c->assertOk();
        $this->assertStringContainsString('Garlic Naan', $c->getContent());
        $this->assertStringNotContainsString('Seekh Kabab', $this->ticketBody($c->getContent()), 'delta excludes already-printed rows');
        $this->agentReportSuccess($jobs['CounterPrinter']->id);

        // …and the STATION job rendered after must still print its slip.
        $b = $this->agentGetContent($jobs['TandoorPrinter']->id);
        $this->assertSame(200, $b->getStatusCode(), 'station slip must not vanish after the counter copy printed');
        $this->assertStringContainsString('Garlic Naan', $b->getContent());

        // Batch numbers: original row keeps 1, new row got 2 from the first success.
        $this->assertSame(1, (int) DB::table('restaurant_order_items')->where('item_name', 'Seekh Kabab')->value('kot_batch_no'));
        $this->assertSame(2, (int) DB::table('restaurant_order_items')->where('id', $newId)->value('kot_batch_no'));
    }

    // ── 3. Waiter path (KotPrintService) station split mirrors the invariants ─

    public function test_waiter_path_station_split_bakes_snapshot_and_survives_the_race(): void
    {
        $orderId = $this->makeOrder(['Seekh Kabab' => 101, 'Garlic Naan' => 102]);
        $company = Company::find(1);
        $order = \App\Models\RestaurantOrder::find($orderId);

        $result = KotPrintService::enqueueForOrder($company, $order, null, delta: true);
        $this->assertTrue($result['printed']);
        $this->assertCount(2, $result['job_ids'], 'one waiter job per station with items');

        $jobs = $this->jobsByPrinter($orderId, ['GrillPrinter', 'TandoorPrinter', 'CounterPrinter']);
        $itemIds = DB::table('restaurant_order_items')->where('order_id', $orderId)->pluck('id')->map(fn ($i) => (int) $i)->all();
        foreach (['GrillPrinter', 'TandoorPrinter', 'CounterPrinter'] as $printer) {
            $this->assertEqualsCanonicalizing($itemIds, $this->bakedIds($jobs[$printer]), "waiter {$printer} job bakes the snapshot");
        }

        $this->agentGetContent($jobs['GrillPrinter']->id)->assertOk();
        $this->agentReportSuccess($jobs['GrillPrinter']->id);

        $b = $this->agentGetContent($jobs['TandoorPrinter']->id);
        $this->assertSame(200, $b->getStatusCode(), 'waiter-path second station must not vanish');
        $this->assertStringContainsString('Garlic Naan', $b->getContent());
        $this->assertStringNotContainsString('Seekh Kabab', $this->ticketBody($b->getContent()));
    }

    // ── 4. prepareTicket: station filter operates on the BAKED set correctly ─

    public function test_prepare_ticket_station_filter_on_baked_set(): void
    {
        $orderId = $this->makeOrder(['Seekh Kabab' => 101, 'Garlic Naan' => 102]);
        // Simulate render-time resolution from a baked snapshot: all rows are
        // ALREADY stamped (the race), but the baked set still selects them.
        DB::table('restaurant_order_items')->where('order_id', $orderId)
            ->update(['kot_printed_at' => now(), 'kot_batch_no' => 1]);
        $bakedItems = \App\Models\RestaurantOrderItem::where('order_id', $orderId)->get()->values();

        $prep = \App\Models\PosStation::prepareTicket(1, $bakedItems, (string) $this->tandoorStationId);
        $this->assertCount(1, $prep['items'], 'station filter keeps only its own rows');
        $this->assertSame('Garlic Naan', $prep['items']->first()->item_name);
        $this->assertSame('Tandoor', $prep['stationLabel']);
        $this->assertSame(['Tandoor'], $prep['grouped']->keys()->all());

        // '0' = implicit default Kitchen bucket — nothing routes there here.
        $prep0 = \App\Models\PosStation::prepareTicket(1, $bakedItems, '0');
        $this->assertCount(0, $prep0['items'], 'default Kitchen bucket empty when all categories are claimed');
        $this->assertSame(\App\Models\PosStation::DEFAULT_LABEL, $prep0['stationLabel']);
    }
}
