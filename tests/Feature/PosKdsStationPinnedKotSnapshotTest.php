<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Http\Controllers\PosController;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * KDS STATION-PINNED KOT DELTA SNAPSHOT — Task #590 (closes the last untested
 * enqueue path of the Task #567/#572 vanishing-slip race).
 *
 * KDS counter screens reprint a single station's slip by POSTing station_id
 * to PosController::apiCreatePrintJob ("Station-pinned device" branch). That
 * branch also goes through $makeJob, so the unprinted-item snapshot
 * (printed_item_ids) is baked at ENQUEUE time — but no test locked it. If a
 * KDS-triggered pinned job overlaps a cashier kitchen-send, the cashier job's
 * SUCCESS stamps kot_printed_at; without the baked snapshot the pinned job's
 * render would re-derive the delta (whereNull finds nothing → 204) and the
 * KDS slip vanishes.
 *
 * Locked here:
 *   • station_id enqueue bakes the snapshot into the pinned job;
 *   • an overlapping cashier job's success does NOT empty the pinned job's
 *     render (200, only that station's items);
 *   • dedupe-hit on a still-PENDING pinned job MERGES fresh delta ids.
 *
 * Pattern mirrors PosStationSplitKotDeltaSnapshotTest: sqlite :memory:
 * minimal schema; enqueue via direct controller call; agent render/result
 * via real HTTP with the bearer agent key.
 *
 * NEVER "fix" a failure here by re-deriving the delta set at render time —
 * that is exactly the race this file exists to prevent.
 */
class PosKdsStationPinnedKotSnapshotTest extends TestCase
{
    private string $agentKey = 'test-agent-key-kdspinned';

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

        Schema::create('pos_products', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->string('name');
            $table->string('category')->nullable();
            $table->timestamps();
        });

        DB::table('companies')->insert([
            'id' => 1,
            'name' => 'KDS Pinned Co',
            'agent_api_key' => $this->agentKey,
            'agent_enabled' => true,
            'agent_last_seen' => now(),
            'pos_printer_settings' => json_encode([
                'silent_print_enabled' => true,
                'kot_printer' => 'KitchenPrinter',
                // counter copy OFF — pinned-path tests exercise only the KDS job
                'counter_kot_enabled' => false,
            ]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

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

    private function makeOrder(array $items, array $printed = []): int
    {
        $orderId = DB::table('restaurant_orders')->insertGetId([
            'company_id' => 1,
            'order_number' => 'ORD-260813-KDS1',
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

    /** KDS pinned enqueue — station_id rides the POST (counter-screen reprint). */
    private function enqueuePinned(int $orderId, int $stationId, bool $delta = true)
    {
        $request = Request::create('/pos/api/print-jobs', 'POST', [
            'type' => 'kot',
            'restaurant_order_id' => $orderId,
            'station_id' => $stationId,
            'delta' => $delta ? 1 : 0,
        ]);
        $request->setLaravelSession(app('session.store'));
        return app(PosController::class)->apiCreatePrintJob($request);
    }

    /** Cashier enqueue — no station_id, fans out per-station (the overlapping send). */
    private function enqueueCashier(int $orderId, bool $delta = true)
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

    private function jobRow(int $jobId): object
    {
        return DB::table('pos_print_jobs')->where('id', $jobId)->first();
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

    // ── 1. Pinned enqueue bakes the snapshot; overlapping cashier success
    //      must not empty the pinned job's render ─────────────────────────

    public function test_pinned_job_bakes_snapshot_and_survives_overlapping_cashier_success(): void
    {
        $this->makePosUser();
        $orderId = $this->makeOrder(['Seekh Kabab' => 101, 'Garlic Naan' => 102]);
        $itemIds = DB::table('restaurant_order_items')->where('order_id', $orderId)
            ->pluck('id')->map(fn ($i) => (int) $i)->all();

        // KDS counter screen fires a Tandoor reprint FIRST (pinned job queued)…
        $pinnedRes = $this->enqueuePinned($orderId, $this->tandoorStationId);
        $pinnedData = $pinnedRes->getData(true);
        $this->assertTrue($pinnedData['success'] ?? false);
        $pinnedId = (int) $pinnedData['job_id'];

        $pinned = $this->jobRow($pinnedId);
        $this->assertSame('TandoorPrinter', $pinned->target_printer, 'pinned job targets the station printer');
        $this->assertSame('station=' . $this->tandoorStationId . '&delta=1', $pinned->render_query);
        $this->assertEqualsCanonicalizing($itemIds, $this->bakedIds($pinned), 'pinned enqueue bakes the unprinted snapshot');

        // …then the cashier's full kitchen-send overlaps. Station fan-out
        // dedupes the still-pending Tandoor job, so only Grill is new.
        $cashierRes = $this->enqueueCashier($orderId);
        $cashierData = $cashierRes->getData(true);
        $this->assertTrue($cashierData['success'] ?? false);
        $grillId = collect($cashierData['job_ids'])->first(fn ($id) => (int) $id !== $pinnedId);
        $this->assertNotNull($grillId, 'cashier send enqueues the grill job');
        $this->assertContains($pinnedId, array_map('intval', $cashierData['job_ids']), 'cashier tandoor job dedupes onto the pending pinned job');

        // Grill prints FIRST and succeeds — stamps kot_printed_at on its row.
        $a = $this->agentGetContent((int) $grillId);
        $a->assertOk();
        $this->assertStringContainsString('Seekh Kabab', $a->getContent());
        $this->agentReportSuccess((int) $grillId);
        $this->assertNotNull(DB::table('restaurant_order_items')->where('item_name', 'Seekh Kabab')->value('kot_printed_at'));

        // THE RACE: the pinned KDS job renders AFTER that success. It MUST
        // return content (200) with ONLY its own station's item — never 204.
        $b = $this->agentGetContent($pinnedId);
        $this->assertSame(200, $b->getStatusCode(), 'pinned KDS slip must not vanish after the cashier job printed');
        $this->assertStringContainsString('Garlic Naan', $b->getContent());
        $this->assertStringNotContainsString('Seekh Kabab', $this->ticketBody($b->getContent()), 'station filter still applies on the baked set');
        $this->agentReportSuccess($pinnedId);
    }

    // ── 2. Reverse overlap: cashier send queued first, KDS reprint dedupes
    //      onto it; cashier's OTHER station succeeding must not empty it ──

    public function test_kds_reprint_after_cashier_send_dedupes_and_still_prints(): void
    {
        $this->makePosUser();
        $orderId = $this->makeOrder(['Seekh Kabab' => 101, 'Garlic Naan' => 102]);

        $cashierData = $this->enqueueCashier($orderId)->getData(true);
        $this->assertTrue($cashierData['success']);
        $this->assertCount(2, $cashierData['job_ids']);
        $tandoorJob = DB::table('pos_print_jobs')->where('restaurant_order_id', $orderId)
            ->where('target_printer', 'TandoorPrinter')->first();
        $grillJob = DB::table('pos_print_jobs')->where('restaurant_order_id', $orderId)
            ->where('target_printer', 'GrillPrinter')->first();

        // KDS fires the same Tandoor slip — must dedupe onto the pending job,
        // never create a second physical ticket.
        $pinnedData = $this->enqueuePinned($orderId, $this->tandoorStationId)->getData(true);
        $this->assertTrue($pinnedData['success']);
        $this->assertSame((int) $tandoorJob->id, (int) $pinnedData['job_id'], 'KDS reprint dedupes onto the in-flight station job');
        $this->assertSame(2, DB::table('pos_print_jobs')->where('restaurant_order_id', $orderId)->count(), 'no extra job created');

        // Grill succeeds first; the (deduped) Tandoor job still renders.
        $this->agentGetContent((int) $grillJob->id)->assertOk();
        $this->agentReportSuccess((int) $grillJob->id);

        $b = $this->agentGetContent((int) $tandoorJob->id);
        $this->assertSame(200, $b->getStatusCode(), 'deduped KDS/station job must still render after the race');
        $this->assertStringContainsString('Garlic Naan', $b->getContent());
    }

    // ── 3. Dedupe-merge: a rapid second edit's fresh ids MERGE into the
    //      still-PENDING pinned job ───────────────────────────────────────

    public function test_pending_pinned_job_dedupe_merges_fresh_delta_ids(): void
    {
        $this->makePosUser();
        $orderId = $this->makeOrder(['Garlic Naan' => 102]);
        $firstId = (int) DB::table('restaurant_order_items')->where('item_name', 'Garlic Naan')->value('id');

        $pinnedData = $this->enqueuePinned($orderId, $this->tandoorStationId)->getData(true);
        $pinnedId = (int) $pinnedData['job_id'];
        $this->assertSame([$firstId], $this->bakedIds($this->jobRow($pinnedId)));

        // Order edited: a SECOND Tandoor item lands while the job is pending.
        $secondId = (int) DB::table('restaurant_order_items')->insertGetId([
            'order_id' => $orderId,
            'item_type' => 'product',
            'item_id' => 102,
            'item_name' => 'Butter Naan',
            'quantity' => 1,
            'unit_price' => 120,
            'subtotal' => 120,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('pos_products')->where('id', 102)->update(['name' => 'Garlic Naan']); // category unchanged
        DB::table('pos_products')->insert([
            'id' => 103, 'company_id' => 1, 'name' => 'Butter Naan', 'category' => 'Bread',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('restaurant_order_items')->where('id', $secondId)->update(['item_id' => 103]);

        // KDS refires the pinned print — dedupe-hit MUST merge the fresh id.
        $again = $this->enqueuePinned($orderId, $this->tandoorStationId)->getData(true);
        $this->assertSame($pinnedId, (int) $again['job_id'], 'still-pending pinned job deduped, not duplicated');
        $this->assertEqualsCanonicalizing([$firstId, $secondId], $this->bakedIds($this->jobRow($pinnedId)), 'fresh delta ids merged into the pending pinned job');

        // Render carries BOTH items.
        $r = $this->agentGetContent($pinnedId);
        $r->assertOk();
        $this->assertStringContainsString('Garlic Naan', $r->getContent());
        $this->assertStringContainsString('Butter Naan', $r->getContent());
    }

    // ── 4. Pinned enqueue guards: unknown station 404s, delta with nothing
    //      unprinted enqueues no job ──────────────────────────────────────

    public function test_pinned_enqueue_guards(): void
    {
        $this->makePosUser();

        // Unknown station id → 404, no job (order must have unprinted rows —
        // the empty-delta guard fires BEFORE the pinned branch).
        $liveOrderId = $this->makeOrder(['Garlic Naan' => 102]);
        $res = $this->enqueuePinned($liveOrderId, 999);
        $this->assertSame(404, $res->getStatusCode());
        $this->assertSame(0, DB::table('pos_print_jobs')->where('restaurant_order_id', $liveOrderId)->count());

        // Delta with nothing unprinted → success, NO jobs (empty-delta guard
        // fires before the pinned branch).
        $orderId = $this->makeOrder(['Seekh Kabab' => 101], printed: ['Seekh Kabab']);
        $res2 = $this->enqueuePinned($orderId, $this->grillStationId);
        $data = $res2->getData(true);
        $this->assertTrue($data['success']);
        $this->assertSame([], $data['job_ids'] ?? []);
        $this->assertSame(0, DB::table('pos_print_jobs')->where('restaurant_order_id', $orderId)->count());
    }
}
