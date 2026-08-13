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
 * COUNTER KOT DELTA SNAPSHOT — Task #567 (regression lock for Task #566,
 * Pizza Master's vanishing counter slip, Aug 2026).
 *
 * One kitchen-send can enqueue MULTIPLE delta KOT jobs (kitchen ticket +
 * counter copy). The agent prints them sequentially and stamps
 * kot_printed_at only at RESULT time — so before the fix, the first job's
 * success emptied every later overlapping delta job: the counter copy
 * rendered after the kitchen ticket printed, found zero whereNull rows,
 * got a 204, and no slip ever reached the counter.
 *
 * The fix bakes the unprinted item-id snapshot (printed_item_ids) into
 * EVERY job of the send at ENQUEUE time; render consumes the baked set
 * instead of re-deriving whereNull. Locked here for BOTH enqueue paths:
 *   • cashier path  — PosController::apiCreatePrintJob KOT branch
 *   • waiter path   — KotPrintService::enqueueForOrder
 * plus stamping invariants, the edit-path (recall + new item) case, the
 * empty-delta case, and the pos_kot_full_mode branch.
 *
 * Pattern: sqlite :memory: minimal schema; enqueue via direct controller
 * call (auth pos guard + currentCompanyId binding, same as
 * PosCashReceivedToggleTest); agent render/result via real HTTP with the
 * bearer agent key (same as PrintJobLongPollTest).
 *
 * NEVER "fix" a failure here by re-deriving the delta set at render time —
 * that is exactly the race this file exists to prevent.
 */
class PosCounterKotDeltaSnapshotTest extends TestCase
{
    private string $agentKey = 'test-agent-key-counterkot';

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

        DB::table('companies')->insert([
            'id' => 1,
            'name' => 'Counter KOT Co',
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

    private function makeOrder(array $itemNames, array $printed = []): int
    {
        $orderId = DB::table('restaurant_orders')->insertGetId([
            'company_id' => 1,
            'order_number' => 'ORD-260813-TEST1',
            'order_type' => 'dine_in',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        foreach ($itemNames as $name) {
            DB::table('restaurant_order_items')->insert([
                'order_id' => $orderId,
                'item_type' => 'manual',
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

    /** [kitchenJob, counterJob] for an order, asserting both exist. */
    private function jobsFor(int $orderId): array
    {
        $jobs = DB::table('pos_print_jobs')->where('restaurant_order_id', $orderId)->orderBy('id')->get();
        $kitchen = $jobs->firstWhere('target_printer', 'KitchenPrinter');
        $counter = $jobs->firstWhere('target_printer', 'CounterPrinter');
        $this->assertNotNull($kitchen, 'kitchen KOT job enqueued');
        $this->assertNotNull($counter, 'counter KOT copy job enqueued');
        return [$kitchen, $counter];
    }

    private function bakedIds(object $jobRow): array
    {
        return array_map('intval', (array) json_decode($jobRow->printed_item_ids ?? '[]', true));
    }

    // ── 1. Core race lock: second job must still print after first success ──

    public function test_counter_copy_prints_after_kitchen_job_success(): void
    {
        $this->makePosUser();
        $orderId = $this->makeOrder(['Chicken Karahi', 'Garlic Naan']);

        $res = $this->enqueue($orderId);
        $this->assertTrue($res->getData(true)['success'] ?? false);

        [$kitchen, $counter] = $this->jobsFor($orderId);
        $itemIds = DB::table('restaurant_order_items')->where('order_id', $orderId)->pluck('id')->map(fn ($i) => (int) $i)->sort()->values()->all();

        // BOTH jobs carry the same baked snapshot at enqueue time.
        $this->assertEqualsCanonicalizing($itemIds, $this->bakedIds($kitchen), 'kitchen job bakes the unprinted snapshot');
        $this->assertEqualsCanonicalizing($itemIds, $this->bakedIds($counter), 'counter job bakes the SAME snapshot');

        // Job A (kitchen): render → success (stamps kot_printed_at).
        $contentA = $this->agentGetContent($kitchen->id);
        $contentA->assertOk();
        $this->assertStringContainsString('Chicken Karahi', $contentA->getContent());
        $this->agentReportSuccess($kitchen->id);
        $this->assertSame(2, DB::table('restaurant_order_items')->where('order_id', $orderId)->whereNotNull('kot_printed_at')->count(), 'kitchen success stamps the items');

        // Job B (counter): rendered AFTER the stamping — the exact race.
        // It MUST return the full slip content, never a 204/empty.
        $contentB = $this->agentGetContent($counter->id);
        $this->assertSame(200, $contentB->getStatusCode(), 'counter copy must render content, not 204 — the Pizza Master race');
        $this->assertStringContainsString('Chicken Karahi', $contentB->getContent());
        $this->assertStringContainsString('Garlic Naan', $contentB->getContent());
    }

    public function test_second_job_success_does_not_restamp_or_bump_batches(): void
    {
        $this->makePosUser();
        $orderId = $this->makeOrder(['Chicken Karahi', 'Garlic Naan']);
        $this->enqueue($orderId);
        [$kitchen, $counter] = $this->jobsFor($orderId);

        $this->agentGetContent($kitchen->id)->assertOk();
        $this->agentReportSuccess($kitchen->id);

        $stampsAfterA = DB::table('restaurant_order_items')->where('order_id', $orderId)
            ->pluck('kot_batch_no', 'id')->all();
        $timesAfterA = DB::table('restaurant_order_items')->where('order_id', $orderId)
            ->pluck('kot_printed_at', 'id')->all();

        // Counter copy prints and reports success too.
        $this->agentGetContent($counter->id)->assertOk();
        $this->agentReportSuccess($counter->id);

        // Already-stamped rows keep their ORIGINAL stamp + batch number
        // (result-time update is whereNull-guarded — no restamp, no re-batch).
        $this->assertSame($stampsAfterA, DB::table('restaurant_order_items')->where('order_id', $orderId)->pluck('kot_batch_no', 'id')->all(), 'batch numbers unchanged by the second job');
        $this->assertSame($timesAfterA, DB::table('restaurant_order_items')->where('order_id', $orderId)->pluck('kot_printed_at', 'id')->all(), 'print stamps unchanged by the second job');
    }

    // ── 2. Edit path: recall + new item → both jobs bake ONLY the new row ──

    public function test_edit_path_both_jobs_bake_only_the_new_item(): void
    {
        $this->makePosUser();
        // Original send already printed (stamped); the recall adds one new item.
        $orderId = $this->makeOrder(['Chicken Karahi', 'Extra Raita'], printed: ['Chicken Karahi']);
        $newId = (int) DB::table('restaurant_order_items')->where('item_name', 'Extra Raita')->value('id');

        $this->enqueue($orderId);
        [$kitchen, $counter] = $this->jobsFor($orderId);

        $this->assertSame([$newId], $this->bakedIds($kitchen), 'kitchen delta bakes only the new row');
        $this->assertSame([$newId], $this->bakedIds($counter), 'counter delta bakes only the new row');

        // Render order A then B — B still carries the new item only.
        $a = $this->agentGetContent($kitchen->id);
        $a->assertOk();
        $this->assertStringNotContainsString('Chicken Karahi', $this->ticketBody($a->getContent()), 'delta ticket excludes already-printed rows');
        $this->agentReportSuccess($kitchen->id);

        $b = $this->agentGetContent($counter->id);
        $this->assertSame(200, $b->getStatusCode(), 'edit-path counter slip must not vanish');
        $this->assertStringContainsString('Extra Raita', $b->getContent());
        $this->assertStringNotContainsString('Chicken Karahi', $this->ticketBody($b->getContent()));

        // The originally-printed row keeps batch 1; the new row gets batch 2.
        $this->assertSame(1, (int) DB::table('restaurant_order_items')->where('item_name', 'Chicken Karahi')->value('kot_batch_no'));
        $this->assertSame(2, (int) DB::table('restaurant_order_items')->where('id', $newId)->value('kot_batch_no'));
    }

    /** Markup after </head> — <title>/CSS legitimately carry no item names but keep it safe. */
    private function ticketBody(string $html): string
    {
        $pos = strpos($html, '</head>');
        return $pos === false ? $html : substr($html, $pos);
    }

    // ── 3. Waiter path (KotPrintService) mirrors the same invariants ──────

    public function test_waiter_path_bakes_snapshot_and_survives_the_race(): void
    {
        $orderId = $this->makeOrder(['Seekh Kabab', 'Roti'], printed: ['Seekh Kabab']);
        $newId = (int) DB::table('restaurant_order_items')->where('item_name', 'Roti')->value('id');
        $company = Company::find(1);
        $order = \App\Models\RestaurantOrder::find($orderId);

        $result = KotPrintService::enqueueForOrder($company, $order, null, delta: true);
        $this->assertTrue($result['printed']);

        [$kitchen, $counter] = $this->jobsFor($orderId);
        $this->assertSame([$newId], $this->bakedIds($kitchen), 'waiter kitchen job bakes the snapshot');
        $this->assertSame([$newId], $this->bakedIds($counter), 'waiter counter job bakes the snapshot');

        $this->agentGetContent($kitchen->id)->assertOk();
        $this->agentReportSuccess($kitchen->id);

        $b = $this->agentGetContent($counter->id);
        $this->assertSame(200, $b->getStatusCode(), 'waiter-path counter slip must not vanish either');
        $this->assertStringContainsString('Roti', $b->getContent());
    }

    // ── 4. Empty delta: nothing unprinted → success, zero jobs ────────────

    public function test_empty_delta_creates_no_jobs_on_either_path(): void
    {
        $this->makePosUser();
        $orderId = $this->makeOrder(['Chicken Karahi'], printed: ['Chicken Karahi']);

        $res = $this->enqueue($orderId);
        $data = $res->getData(true);
        $this->assertTrue($data['success']);
        $this->assertSame([], $data['job_ids'] ?? null, 'cashier empty delta succeeds with no jobs');

        $result = KotPrintService::enqueueForOrder(Company::find(1), \App\Models\RestaurantOrder::find($orderId), null, delta: true);
        $this->assertTrue($result['printed']);
        $this->assertSame([], $result['job_ids'], 'waiter empty delta succeeds with no jobs');

        $this->assertSame(0, DB::table('pos_print_jobs')->count(), 'no job rows created — counter copy must not fire either');
    }

    // ── 5. Full mode: both jobs render the WHOLE order ────────────────────

    public function test_full_mode_both_jobs_render_whole_order(): void
    {
        DB::table('companies')->where('id', 1)->update(['pos_kot_full_mode' => true]);
        $this->makePosUser();
        $orderId = $this->makeOrder(['Chicken Karahi', 'Extra Raita'], printed: ['Chicken Karahi']);

        $this->enqueue($orderId);
        [$kitchen, $counter] = $this->jobsFor($orderId);

        $a = $this->agentGetContent($kitchen->id);
        $a->assertOk();
        $this->assertStringContainsString('Chicken Karahi', $a->getContent(), 'full mode prints already-printed rows too');
        $this->assertStringContainsString('Extra Raita', $a->getContent());
        $this->agentReportSuccess($kitchen->id);

        // Job B renders after A's stamping — full mode must STILL show the
        // whole order (the baked snapshot keeps the "new rows" set non-empty).
        $b = $this->agentGetContent($counter->id);
        $this->assertSame(200, $b->getStatusCode(), 'full-mode counter copy must not 204 after the kitchen print');
        $this->assertStringContainsString('Chicken Karahi', $b->getContent());
        $this->assertStringContainsString('Extra Raita', $b->getContent());
    }
}
