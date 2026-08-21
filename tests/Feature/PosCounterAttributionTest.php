<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\PosBusinessDay;
use App\Services\PosFeatureService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * COUNTER (terminal) ATTRIBUTION — END-TO-END GUARD (Task 1349 feature).
 *
 * The counter rides the WHOLE chain: sale screen payload → (offline queue) →
 * server resolver → counter-wise reports. Every link was hand-checked only,
 * and the chain is silent by design: a counter is NEVER allowed to block a
 * sale, so a dropped / stale / foreign counter just becomes NULL. That means a
 * future change that forgets to send it, forgets to replay it, or stops
 * grouping by it produces NO error at all — bills keep printing while the
 * counter-wise report quietly empties out, and the shop finds out months later.
 *
 * Locked here:
 *   1. storeInvoice with a real, active, own counter          → stamped.
 *   2. storeInvoice with a deleted / foreign / deactivated id → bill STILL
 *      stores (200) with terminal_id NULL — a sale is never rejected over it.
 *   3. RestaurantPosController::payOrder — same two outcomes on the
 *      hold → pay path.
 *   4. Offline replay books the bill on its ORIGINAL counter (and a counter
 *      that died while the bill sat in the queue never strands the replay).
 *   5. The sale screen keeps the counter INSIDE the shared payload object
 *      (the same object the offline queue persists) and on the pay request.
 *   6. Day-close counter breakdown: sales-only counts, return-netted money,
 *      bills of a since-deleted counter still listed (never silently gone),
 *      and the whole section absent when no bill carries a counter.
 *   7. Reports range analytics: counter block groups only counter-carrying
 *      bills, and is empty (section hidden) when none do.
 *
 * Pattern: APP_ENV=testing + sqlite :memory: + minimal Schema::create, real
 * HTTP through the route stack (same approach as PosPayUuidReplayGuardTest /
 * PosDayCloseStrandedBannerTest). Minimal schemas are exactly why the
 * production code guards pos_terminals / pos_transactions.terminal_id with
 * Schema::hasTable / hasColumn — those guards stay exercised elsewhere; here
 * both exist so the real attribution can be measured.
 */
class PosCounterAttributionTest extends TestCase
{
    /** @var int[] company ids seeded by this test (for static-cache cleanup). */
    private array $seededCompanyIds = [];

    /** Day-close / analytics money seeded per counter (see seedCounterDay). */
    private const A_SALE_1 = 1000.0;
    private const A_SALE_2 = 500.0;
    private const A_RETURN = 200.0;
    private const B_SALE   = 300.0;
    private const NO_COUNTER_SALE = 700.0;

    protected function setUp(): void
    {
        parent::setUp();

        // Midday freeze: safely inside the current business day (06:00 cutoff),
        // so seeded rows and the day-close page agree on "today".
        Carbon::setTestNow(now()->setTime(12, 0));

        // Static caches keyed by company id leak between classes (ids restart
        // at 1 after dropAllTables) — flushed on the way IN and on the way OUT
        // so a later suite never inherits this shop's gates.
        $this->flushStaticCaches();

        Schema::dropAllTables();
        $this->buildSchema();
    }

    protected function tearDown(): void
    {
        $this->flushStaticCaches();
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function flushStaticCaches(): void
    {
        PosFeatureService::flushGateCaches();
        User::flushScopeColumnCache();
        foreach ($this->seededCompanyIds as $id) {
            PosBusinessDay::forgetCutoff($id);
        }
        $this->seededCompanyIds = [];
    }

    // ════════════════════════════════════════════════════════════════════════
    // 1–2. storeInvoice resolver: right counter stamped, wrong counter dropped
    // ════════════════════════════════════════════════════════════════════════

    public function test_sale_with_a_valid_counter_is_stamped_on_the_bill(): void
    {
        [$companyId, $user] = $this->makeShop();
        $counterId = $this->makeCounter($companyId, 'Counter A');

        $res = $this->actingAs($user, 'pos')
            ->postJson('/pos/invoice/store', $this->salePayload(['terminal_id' => $counterId]));

        $res->assertOk()->assertJson(['success' => true]);
        $this->assertSame(
            $counterId,
            (int) DB::table('pos_transactions')->where('company_id', $companyId)->value('terminal_id'),
            'the counter the device is set to must land on the bill'
        );
    }

    public function test_a_counter_deleted_after_the_device_remembered_it_never_blocks_the_sale(): void
    {
        [$companyId, $user] = $this->makeShop();

        // The device still remembers id 99999 (localStorage); the row is gone.
        $res = $this->actingAs($user, 'pos')
            ->postJson('/pos/invoice/store', $this->salePayload(['terminal_id' => 99999]));

        $res->assertOk()->assertJson(['success' => true]);
        $this->assertSame(1, DB::table('pos_transactions')->where('company_id', $companyId)->count(),
            'a stale counter must never cost the shop a bill');
        $this->assertNull(DB::table('pos_transactions')->where('company_id', $companyId)->value('terminal_id'));
    }

    public function test_another_companys_counter_is_dropped_not_stamped(): void
    {
        [$companyId, $user] = $this->makeShop();
        [$otherCompanyId] = $this->makeShop(['name' => 'Padosi Store']);
        $foreignCounterId = $this->makeCounter($otherCompanyId, 'Padosi Counter');

        $res = $this->actingAs($user, 'pos')
            ->postJson('/pos/invoice/store', $this->salePayload(['terminal_id' => $foreignCounterId]));

        $res->assertOk()->assertJson(['success' => true]);
        $this->assertNull(
            DB::table('pos_transactions')->where('company_id', $companyId)->value('terminal_id'),
            'a counter from another shop must never be stamped'
        );
    }

    public function test_deactivated_counter_is_dropped_not_stamped(): void
    {
        [$companyId, $user] = $this->makeShop();
        $counterId = $this->makeCounter($companyId, 'Purana Counter', false);

        $res = $this->actingAs($user, 'pos')
            ->postJson('/pos/invoice/store', $this->salePayload(['terminal_id' => $counterId]));

        $res->assertOk()->assertJson(['success' => true]);
        $this->assertNull(
            DB::table('pos_transactions')->where('company_id', $companyId)->value('terminal_id'),
            'a deactivated counter must stamp NULL, never fail the sale'
        );
    }

    // ════════════════════════════════════════════════════════════════════════
    // 3. Restaurant hold → pay path: both outcomes
    // ════════════════════════════════════════════════════════════════════════

    public function test_restaurant_pay_order_stamps_the_counter(): void
    {
        [$companyId, $user] = $this->makeRestaurantShop();
        $counterId = $this->makeCounter($companyId, 'Counter A');
        $orderId = $this->makeHeldOrder($companyId);

        $res = $this->actingAs($user, 'pos')->postJson("/pos/restaurant/orders/{$orderId}/pay", [
            'payment_method' => 'cash',
            'terminal_id' => $counterId,
        ]);

        $res->assertOk()->assertJson(['success' => true]);
        $this->assertSame(
            $counterId,
            (int) DB::table('pos_transactions')->where('id', $res->json('transaction_id'))->value('terminal_id'),
            'restaurant pay-order bills must carry the counter too'
        );
    }

    public function test_restaurant_pay_order_drops_a_stale_counter_without_blocking_payment(): void
    {
        [$companyId, $user] = $this->makeRestaurantShop();
        $deadCounterId = $this->makeCounter($companyId, 'Band Counter', false);
        [$otherCompanyId] = $this->makeShop(['name' => 'Padosi Store']);
        $foreignCounterId = $this->makeCounter($otherCompanyId, 'Padosi Counter');

        foreach ([$deadCounterId, $foreignCounterId, 99999] as $badId) {
            $orderId = $this->makeHeldOrder($companyId);
            $res = $this->actingAs($user, 'pos')->postJson("/pos/restaurant/orders/{$orderId}/pay", [
                'payment_method' => 'cash',
                'terminal_id' => $badId,
            ]);

            $res->assertOk()->assertJson(['success' => true]);
            $this->assertNull(
                DB::table('pos_transactions')->where('id', $res->json('transaction_id'))->value('terminal_id'),
                "counter id {$badId} must be dropped, and the payment must still go through"
            );
        }
    }

    // ════════════════════════════════════════════════════════════════════════
    // 4. Offline queue replay keeps the ORIGINAL counter
    // ════════════════════════════════════════════════════════════════════════

    public function test_offline_replay_books_the_bill_on_its_original_counter(): void
    {
        [$companyId, $user] = $this->makeShop();
        $counterId = $this->makeCounter($companyId, 'Counter A');

        // Rung up 3 hours ago on Counter A, synced now from the same device.
        $res = $this->actingAs($user, 'pos')->postJson('/pos/invoice/store', $this->salePayload([
            'terminal_id' => $counterId,
            'offline_uuid' => 'queued-bill-0001',
            'offline_queued_at' => now()->subHours(3)->toIso8601String(),
            'offline_queued_by' => $user->id,
        ]));

        $res->assertOk()->assertJson(['success' => true]);
        $row = DB::table('pos_transactions')->where('offline_uuid', 'queued-bill-0001')->first();
        $this->assertNotNull($row);
        $this->assertSame($counterId, (int) $row->terminal_id,
            'a replayed bill must keep the counter it was rung up on, not lose it at sync time');

        // A re-sync of the SAME queued bill replays the original — the counter
        // must survive the dedupe path untouched (no second, counter-less bill).
        $again = $this->actingAs($user, 'pos')->postJson('/pos/invoice/store', $this->salePayload([
            'terminal_id' => $counterId,
            'offline_uuid' => 'queued-bill-0001',
            'offline_queued_at' => now()->subHours(3)->toIso8601String(),
            'offline_queued_by' => $user->id,
        ]));
        $again->assertOk()->assertJson(['success' => true]);
        $this->assertSame(1, DB::table('pos_transactions')->where('company_id', $companyId)->count());
        $this->assertSame($counterId,
            (int) DB::table('pos_transactions')->where('offline_uuid', 'queued-bill-0001')->value('terminal_id'));
    }

    public function test_offline_replay_of_a_bill_whose_counter_died_meanwhile_is_never_stranded(): void
    {
        [$companyId, $user] = $this->makeShop();
        $counterId = $this->makeCounter($companyId, 'Counter A');

        // Counter deactivated while the bill sat in the device queue.
        DB::table('pos_terminals')->where('id', $counterId)->update(['is_active' => false]);

        $res = $this->actingAs($user, 'pos')->postJson('/pos/invoice/store', $this->salePayload([
            'terminal_id' => $counterId,
            'offline_uuid' => 'queued-bill-0002',
            'offline_queued_at' => now()->subDay()->toIso8601String(),
            'offline_queued_by' => $user->id,
        ]));

        $res->assertOk()->assertJson(['success' => true]);
        $row = DB::table('pos_transactions')->where('offline_uuid', 'queued-bill-0002')->first();
        $this->assertNotNull($row, 'losing a rung-up bill is far worse than losing its counter');
        $this->assertNull($row->terminal_id);
    }

    // ════════════════════════════════════════════════════════════════════════
    // 5. Sale screen payload keeps the counter on BOTH submit paths
    // ════════════════════════════════════════════════════════════════════════

    public function test_sale_screen_payload_carries_the_counter_on_both_submit_paths(): void
    {
        $src = file_get_contents(resource_path('views/pos/universal.blade.php'));

        // (a) The direct-bill payload. The counter must sit INSIDE the payload
        // object literal that is built once and then either POSTed or handed to
        // queueOfflineBill — a counter attached only on the online branch would
        // vanish from every offline-queued bill.
        $start = strpos($src, 'offline_uuid: this._newOfflineUuid(),');
        $this->assertNotFalse($start, 'sale payload builder not found — update this guard');
        $end = strpos($src, 'navigator.onLine', $start);
        $this->assertNotFalse($end);
        $this->assertStringContainsString('terminal_id:', substr($src, $start, $end - $start),
            'the counter must ride inside the shared payload object, so offline replays carry it too');

        // (b) The restaurant pay request body.
        $payLine = collect(preg_split("/\r?\n/", $src))
            ->first(fn ($line) => str_contains($line, '/pay`') && str_contains($line, 'JSON.stringify'));
        $this->assertNotNull($payLine, 'restaurant pay fetch not found — update this guard');
        $this->assertStringContainsString('terminal_id:', $payLine,
            'the pay request must send the counter or restaurant bills fall out of counter reports');
    }

    // ════════════════════════════════════════════════════════════════════════
    // 6. Day-close counter breakdown
    // ════════════════════════════════════════════════════════════════════════

    public function test_day_close_counter_breakdown_counts_bills_and_nets_returns(): void
    {
        [$companyId, $user] = $this->makeShop();
        [$counterA, $counterB, $deletedCounterId] = $this->seedCounterDay($companyId);

        $res = $this->actingAs($user, 'pos')->get('/pos/day-close');
        $res->assertOk();

        $breakdown = $res->viewData('terminalBreakdown');
        $this->assertNotNull($breakdown);
        $this->assertTrue($breakdown->isNotEmpty(), 'counter-wise section must be built for a counter day');

        // Counter A: two sales, one return — counts are sales-only, money nets.
        $a = $breakdown['Counter A'] ?? null;
        $this->assertNotNull($a, 'Counter A row missing from the day-close breakdown');
        $this->assertSame(2, $a->count, 'the return must not be counted as a sale');
        $this->assertSame(self::A_SALE_1 + self::A_SALE_2 - self::A_RETURN, (float) $a->revenue,
            'returns must be subtracted from the counter revenue');
        $this->assertSame(100.0 + 50.0 - 20.0, (float) $a->tax);

        // Counter B: untouched by Counter A's return.
        $b = $breakdown['Counter B'] ?? null;
        $this->assertNotNull($b);
        $this->assertSame(1, $b->count);
        $this->assertSame(self::B_SALE, (float) $b->revenue);

        // A bill whose counter row was DELETED still shows up (fallback label) —
        // its money must never quietly disappear from the day's figures.
        $fallbackLabel = __('pos.counter_word') . ' #' . $deletedCounterId;
        $this->assertArrayHasKey($fallbackLabel, $breakdown->all(),
            'bills of a deleted counter must stay visible under a fallback label');
        $this->assertSame(250.0, (float) $breakdown[$fallbackLabel]->revenue);

        // Counter-less bills group under their own row (never merged into a counter).
        $none = $breakdown[__('pos.counter_not_set')] ?? null;
        $this->assertNotNull($none);
        $this->assertSame(self::NO_COUNTER_SALE, (float) $none->revenue);

        // The section actually renders on the page, not just in the view data.
        $res->assertSee(__('pos.counter_breakdown'));
        $res->assertSee('Counter A');
        $res->assertSee('Counter B');
    }

    public function test_day_close_hides_the_counter_section_when_no_bill_carries_one(): void
    {
        [$companyId, $user] = $this->makeShop();
        $this->makeCounter($companyId, 'Counter A'); // exists, but never used on a bill
        $this->makeSale($companyId, 'INV-NC1', ['total_amount' => 900, 'tax_amount' => 0]);

        $res = $this->actingAs($user, 'pos')->get('/pos/day-close');
        $res->assertOk();

        $this->assertTrue($res->viewData('terminalBreakdown')->isEmpty(),
            'a shop that never picked counters must get an EMPTY breakdown, not a "No counter" row');
        $res->assertDontSee(__('pos.counter_breakdown'));
    }

    // ════════════════════════════════════════════════════════════════════════
    // 7. Reports range analytics counter block
    // ════════════════════════════════════════════════════════════════════════

    public function test_range_analytics_counter_block_groups_only_counter_bills(): void
    {
        [$companyId, $user] = $this->makeShop(planAttrs: ['analytics_enabled' => true]);
        [$counterA, $counterB, $deletedCounterId] = $this->seedCounterDay($companyId);

        $res = $this->actingAs($user, 'pos')->get('/pos/reports');
        $res->assertOk();

        $analytics = $res->viewData('rangeAnalytics');
        $this->assertNotNull($analytics, 'analytics plan must build the range analytics');
        $rows = collect($analytics->terminals)->keyBy('name');

        // Range analytics are GROSS by design (same as the cashier block right
        // above it): return rows are EXCLUDED from the set, not netted — so
        // Counter A shows both sales at full value here while the day-close
        // figure above is netted. Locked so a refactor cannot silently flip one
        // of the two conventions.
        $this->assertSame(2, $rows['Counter A']->count);
        $this->assertSame(self::A_SALE_1 + self::A_SALE_2, (float) $rows['Counter A']->revenue);
        $this->assertSame(750.0, (float) $rows['Counter A']->avg);
        $this->assertSame(1, $rows['Counter B']->count);
        $this->assertSame(self::B_SALE, (float) $rows['Counter B']->revenue);

        // Deleted counter keeps its money under a fallback label; counter-less
        // bills are simply not part of the counter block.
        $this->assertTrue($rows->has('#' . $deletedCounterId),
            'bills of a deleted counter must stay in the counter block');
        $this->assertSame(3, $rows->count(), 'counter-less bills must not create a counter row');

        $res->assertSee(__('pos.sales_by_counter'));
    }

    public function test_range_analytics_hides_the_counter_section_when_no_bill_carries_one(): void
    {
        [$companyId, $user] = $this->makeShop(planAttrs: ['analytics_enabled' => true]);
        $this->makeCounter($companyId, 'Counter A');
        $this->makeSale($companyId, 'INV-NC1', ['total_amount' => 900, 'tax_amount' => 0]);

        $res = $this->actingAs($user, 'pos')->get('/pos/reports');
        $res->assertOk();

        $this->assertTrue(collect($res->viewData('rangeAnalytics')->terminals)->isEmpty(),
            'no counter on any bill → empty counter block');
        $res->assertDontSee(__('pos.sales_by_counter'));
    }

    // ════════════════════════════════════════════════════════════════════════
    // Fixtures
    // ════════════════════════════════════════════════════════════════════════

    private static int $seq = 0;

    /** Reporting-OFF POS shop + logged-in POS admin, on an active paid plan. */
    private function makeShop(array $attrs = [], array $planAttrs = []): array
    {
        $seq = ++self::$seq;

        $companyId = (int) DB::table('companies')->insertGetId(array_merge([
            'name' => 'Counter Test Shop ' . $seq,
            'product_type' => 'pos',
            'status' => 'active',
            'company_status' => 'active',
            'is_internal_account' => false,
            'pra_reporting_enabled' => false,
            'inventory_enabled' => false,
            'pos_setup_completed' => true,
            'pos_tax_rate_cash' => 0,
            'pos_tax_rate_card' => 0,
            'invoice_limit_override' => -1,
            'user_limit_override' => -1,
            'created_at' => now(), 'updated_at' => now(),
        ], $attrs));
        $this->seededCompanyIds[] = $companyId;
        PosBusinessDay::forgetCutoff($companyId);

        $planId = (int) DB::table('pricing_plans')->insertGetId(array_merge([
            'name' => 'Business',
            'product_type' => 'pos',
            'is_trial' => false,
            'invoice_limit' => -1,
            'user_limit' => -1,
            'offline_enabled' => true,
            'created_at' => now(), 'updated_at' => now(),
        ], $planAttrs));

        DB::table('subscriptions')->insert([
            'company_id' => $companyId,
            'pricing_plan_id' => $planId,
            'active' => true,
            'start_date' => now()->subMonth()->toDateString(),
            'end_date' => now()->addMonth()->toDateString(),
            'override_type' => 'none',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $userId = (int) DB::table('users')->insertGetId([
            'name' => 'Shop Admin',
            'email' => "admin{$seq}@counter.test",
            'password' => bcrypt('Secret@12345'),
            'company_id' => $companyId,
            'role' => 'company_admin',
            'pos_role' => 'pos_admin',
            'is_active' => true,
            'language' => 'en',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return [$companyId, User::find($userId)];
    }

    /** Restaurant-featured shop (RestaurantOnly middleware + plan module). */
    private function makeRestaurantShop(): array
    {
        return $this->makeShop(
            ['feature_flags' => json_encode(['tables' => true, 'kot' => true, 'kitchen' => true])],
            ['restaurant_enabled' => true],
        );
    }

    private function makeCounter(int $companyId, string $name, bool $active = true): int
    {
        return (int) DB::table('pos_terminals')->insertGetId([
            'company_id' => $companyId,
            'terminal_name' => $name,
            'terminal_code' => 'C-' . (++self::$seq),
            'is_active' => $active,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** One manual-line cash sale posted through the real sale-screen route. */
    private function salePayload(array $overrides = []): array
    {
        return array_merge([
            'items' => [[
                'type' => 'product',
                'item_id' => null,
                '_manual' => true,
                'name' => 'Chai',
                'quantity' => 1,
                'unit_price' => 200,
                'is_tax_exempt' => false,
            ]],
            'payment_method' => 'cash',
            'discount_type' => 'amount',
            'discount_value' => 0,
            'order_type' => 'takeaway',
        ], $overrides);
    }

    /** A held takeaway order ready for the pay route. */
    private function makeHeldOrder(int $companyId): int
    {
        $orderId = (int) DB::table('restaurant_orders')->insertGetId([
            'company_id' => $companyId,
            'order_number' => 'ORD-' . (++self::$seq),
            'order_type' => 'takeaway',
            'status' => 'held',
            'subtotal' => 100,
            'discount_type' => 'amount',
            'discount_value' => 0,
            'discount_amount' => 0,
            'total_amount' => 100,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        DB::table('restaurant_order_items')->insert([
            'order_id' => $orderId,
            'item_type' => 'product',
            'item_id' => 9001, // no recipe rows → stock validation is a no-op
            'item_name' => 'Karahi',
            'quantity' => 1,
            'unit_price' => 100,
            'subtotal' => 100,
            'is_tax_exempt' => false,
            'item_discount_type' => 'percentage',
            'item_discount_value' => 0,
            'item_discount_amount' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return $orderId;
    }

    /** A completed PRA-stream sale on today's business day. */
    private function makeSale(int $companyId, string $invoice, array $attrs = []): int
    {
        return (int) DB::table('pos_transactions')->insertGetId(array_merge([
            'company_id' => $companyId,
            'invoice_number' => $invoice,
            'transaction_type' => 'sale',
            'business_date' => PosBusinessDay::current($companyId),
            'status' => 'completed',
            'invoice_mode' => 'pra',
            'pra_status' => 'submitted',
            'pra_invoice_number' => 'PRA-' . $invoice,
            'subtotal' => 0, 'discount_amount' => 0, 'tax_amount' => 0, 'total_amount' => 0,
            'payment_method' => 'cash',
            'created_at' => now(), 'updated_at' => now(),
        ], $attrs));
    }

    /**
     * A mixed counter day:
     *   Counter A  — 1000 (tax 100) + 500 (tax 50), minus a 200 (tax 20) return
     *   Counter B  — 300 (tax 30)
     *   deleted counter — 250, its pos_terminals row removed after the sale
     *   no counter — 700 (tax 70)
     *
     * @return array{0:int,1:int,2:int} [counterA, counterB, deletedCounterId]
     */
    private function seedCounterDay(int $companyId): array
    {
        $counterA = $this->makeCounter($companyId, 'Counter A');
        $counterB = $this->makeCounter($companyId, 'Counter B');
        $counterC = $this->makeCounter($companyId, 'Counter C');

        $a1 = $this->makeSale($companyId, 'INV-A1', [
            'terminal_id' => $counterA, 'subtotal' => 900, 'tax_amount' => 100, 'total_amount' => self::A_SALE_1,
        ]);
        $this->makeSale($companyId, 'INV-A2', [
            'terminal_id' => $counterA, 'subtotal' => 450, 'tax_amount' => 50, 'total_amount' => self::A_SALE_2,
        ]);
        $this->makeSale($companyId, 'RET-A1', [
            'terminal_id' => $counterA, 'transaction_type' => 'return', 'parent_transaction_id' => $a1,
            'pra_status' => 'pending', 'pra_invoice_number' => null,
            'subtotal' => 180, 'tax_amount' => 20, 'total_amount' => self::A_RETURN,
        ]);
        $this->makeSale($companyId, 'INV-B1', [
            'terminal_id' => $counterB, 'subtotal' => 270, 'tax_amount' => 30, 'total_amount' => self::B_SALE,
        ]);
        $this->makeSale($companyId, 'INV-C1', [
            'terminal_id' => $counterC, 'subtotal' => 250, 'tax_amount' => 0, 'total_amount' => 250,
        ]);
        $this->makeSale($companyId, 'INV-N1', [
            'subtotal' => 630, 'tax_amount' => 70, 'total_amount' => self::NO_COUNTER_SALE,
        ]);

        // Counter C is retired AFTER its bill — the bill keeps the id.
        DB::table('pos_terminals')->where('id', $counterC)->delete();

        return [$counterA, $counterB, $counterC];
    }

    // ════════════════════════════════════════════════════════════════════════
    // Schema (union of the storeInvoice / payOrder / day-close / reports paths)
    // ════════════════════════════════════════════════════════════════════════

    private function buildSchema(): void
    {
        Schema::create('companies', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->string('product_type')->default('pos');
            $t->string('status')->default('active');
            $t->string('company_status')->default('active');
            $t->boolean('is_internal_account')->default(false);
            $t->integer('invoice_limit_override')->nullable();
            $t->integer('user_limit_override')->nullable();
            $t->boolean('pra_reporting_enabled')->default(false);
            $t->boolean('agent_enabled')->default(false);
            $t->string('pra_connection_mode')->nullable();
            $t->string('pra_environment')->nullable();
            $t->text('pra_production_token')->nullable();
            $t->string('pra_proxy_url')->nullable();
            $t->string('pra_pos_id')->nullable();
            $t->decimal('pos_tax_rate_cash', 8, 2)->nullable();
            $t->decimal('pos_tax_rate_card', 8, 2)->nullable();
            $t->string('pos_business_day_cutoff', 5)->nullable();
            $t->boolean('inventory_enabled')->default(false);
            $t->decimal('cashier_discount_limit', 8, 2)->nullable();
            $t->boolean('pos_tax_inclusive')->default(false);
            $t->string('pos_tax_pricing_mode')->nullable();
            $t->text('feature_flags')->nullable();
            $t->string('business_category')->nullable();
            $t->string('default_language')->nullable();
            $t->boolean('pos_setup_completed')->default(true);
            $t->string('pos_dashboard_style')->nullable();
            $t->string('pos_dayclose_provisional_action')->nullable();
            $t->string('pos_dayclose_final_local_action')->nullable();
            $t->boolean('pos_customer_spend_persist')->default(true);
            $t->string('local_number_style', 10)->default('serial');
            $t->string('pra_number_style', 10)->default('serial');
            $t->integer('bill_token_counter_local')->default(0);
            $t->date('bill_token_date_local')->nullable();
            $t->integer('bill_token_counter_pra')->default(0);
            $t->date('bill_token_date_pra')->nullable();
            $t->softDeletes();
            $t->timestamps();
        });

        Schema::create('users', function (Blueprint $t) {
            $t->id();
            $t->string('name')->nullable();
            $t->string('email')->nullable()->unique();
            $t->string('phone')->nullable();
            $t->string('password')->nullable();
            $t->unsignedBigInteger('company_id')->nullable();
            $t->string('role')->nullable();
            $t->string('pos_role')->nullable();
            $t->string('pos_billing_scope', 10)->nullable();
            $t->boolean('is_active')->default(true);
            $t->string('language')->nullable();
            $t->boolean('pra_reporting_enabled')->nullable(); // NULL = inherit company
            $t->rememberToken();
            $t->timestamps();
        });

        Schema::create('pos_terminals', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('company_id');
            $t->string('terminal_name');
            $t->string('terminal_code')->unique();
            $t->string('location')->nullable();
            $t->boolean('is_active')->default(true);
            $t->timestamps();
        });

        Schema::create('pos_transactions', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('company_id');
            $t->unsignedBigInteger('branch_id')->nullable();
            $t->unsignedBigInteger('terminal_id')->nullable();
            $t->unsignedBigInteger('customer_id')->nullable();
            $t->string('invoice_number');
            $t->string('transaction_type')->nullable()->default('sale');
            $t->unsignedBigInteger('parent_transaction_id')->nullable();
            $t->string('invoice_mode')->nullable();
            $t->string('business_date')->nullable();
            $t->string('status');
            $t->string('order_type')->nullable();
            $t->string('pra_status')->nullable();
            $t->string('pra_invoice_number')->nullable();
            $t->string('pra_response_code')->nullable();
            $t->text('pra_qr_code')->nullable();
            $t->string('customer_name')->nullable();
            $t->string('customer_phone')->nullable();
            $t->string('delivery_address')->nullable();
            $t->decimal('subtotal', 12, 2)->default(0);
            $t->string('discount_type')->nullable();
            $t->decimal('discount_value', 12, 2)->nullable();
            $t->decimal('discount_amount', 12, 2)->default(0);
            $t->decimal('tax_rate', 8, 2)->nullable();
            $t->decimal('tax_amount', 12, 2)->default(0);
            $t->decimal('exempt_amount', 12, 2)->nullable();
            $t->decimal('total_amount', 12, 2)->default(0);
            $t->string('payment_method')->nullable();
            $t->decimal('cash_received', 12, 2)->nullable();
            $t->decimal('change_due', 12, 2)->nullable();
            $t->string('submission_hash')->nullable();
            $t->string('offline_uuid', 64)->nullable();
            $t->boolean('tax_inclusive')->default(false);
            $t->decimal('tax_menu_rate', 8, 2)->nullable();
            $t->integer('bill_token')->nullable();
            $t->unsignedBigInteger('created_by')->nullable();
            $t->unsignedBigInteger('locked_by_terminal_id')->nullable();
            $t->timestamp('lock_time')->nullable();
            $t->text('notes')->nullable();
            $t->boolean('is_archived')->default(false);
            $t->timestamp('archived_at')->nullable();
            $t->unsignedBigInteger('archived_by_report_id')->nullable();
            $t->timestamps();
            $t->unique(['company_id', 'invoice_number']);
            $t->unique(['company_id', 'offline_uuid'], 'pos_txn_offline_uuid_unique');
        });

        Schema::create('pos_transaction_items', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('transaction_id');
            $t->string('item_type')->nullable();
            $t->unsignedBigInteger('item_id')->nullable();
            $t->string('item_name');
            $t->text('special_notes')->nullable();
            $t->text('deal_snapshot')->nullable();
            $t->decimal('quantity', 12, 3)->default(1);
            $t->decimal('unit_price', 12, 2)->default(0);
            $t->decimal('cost_price', 12, 4)->nullable();
            $t->decimal('subtotal', 12, 2)->default(0);
            $t->boolean('is_tax_exempt')->default(false);
            $t->decimal('tax_rate', 8, 2)->nullable();
            $t->decimal('tax_amount', 12, 2)->nullable();
            $t->string('item_discount_type')->nullable();
            $t->decimal('item_discount_value', 12, 2)->nullable();
            $t->decimal('item_discount_amount', 12, 2)->nullable();
            $t->timestamps();
        });

        Schema::create('pos_payments', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('transaction_id');
            $t->string('payment_method')->nullable();
            $t->decimal('amount', 12, 2)->default(0);
            $t->string('reference_number')->nullable();
            $t->timestamps();
        });

        Schema::create('pos_products', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('company_id');
            $t->string('name');
            $t->string('category')->nullable();
            $t->decimal('price', 12, 2)->default(0);
            $t->decimal('cost_price', 12, 4)->nullable();
            $t->boolean('is_active')->default(true);
            $t->boolean('is_tax_exempt')->default(false);
            $t->string('barcode')->nullable();
            $t->softDeletes();
            $t->timestamps();
        });

        Schema::create('branches', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('company_id');
            $t->string('name')->nullable();
            $t->boolean('is_head_office')->default(false);
            $t->boolean('is_active')->default(true);
            $t->timestamps();
        });

        Schema::create('pos_tax_rules', function (Blueprint $t) {
            $t->id();
            $t->string('payment_method');
            $t->decimal('tax_rate', 8, 2)->default(0);
            $t->boolean('is_active')->default(true);
            $t->timestamps();
        });
        DB::table('pos_tax_rules')->insert([
            ['payment_method' => 'cash', 'tax_rate' => 0, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
            ['payment_method' => 'debit_card', 'tax_rate' => 0, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
        ]);

        Schema::create('pricing_plans', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->string('product_type')->default('pos');
            $t->boolean('is_trial')->default(false);
            $t->integer('invoice_limit')->nullable();
            $t->integer('user_limit')->nullable();
            $t->boolean('offline_enabled')->default(true);
            $t->boolean('restaurant_enabled')->default(false);
            $t->boolean('analytics_enabled')->default(false);
            $t->boolean('deals_enabled')->default(false);
            $t->timestamps();
        });

        Schema::create('subscriptions', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('company_id');
            $t->unsignedBigInteger('pricing_plan_id')->nullable();
            $t->boolean('active')->default(true);
            $t->date('start_date')->nullable();
            $t->date('end_date')->nullable();
            $t->timestamp('trial_ends_at')->nullable();
            $t->string('override_type')->default('none');
            $t->timestamp('override_until')->nullable();
            $t->timestamp('override_granted_at')->nullable();
            $t->integer('free_invoice_limit')->nullable();
            $t->timestamps();
        });

        Schema::create('restaurant_orders', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('company_id');
            $t->string('order_number')->nullable();
            $t->unsignedInteger('token_no')->nullable();
            $t->unsignedBigInteger('table_id')->nullable();
            $t->string('order_type')->nullable();
            $t->string('source')->nullable();
            $t->string('status')->nullable();
            $t->unsignedBigInteger('customer_id')->nullable();
            $t->string('customer_name')->nullable();
            $t->string('customer_phone')->nullable();
            $t->string('delivery_address')->nullable();
            $t->decimal('subtotal', 12, 2)->default(0);
            $t->string('discount_type')->nullable();
            $t->decimal('discount_value', 12, 2)->nullable();
            $t->decimal('discount_amount', 12, 2)->default(0);
            $t->decimal('tax_amount', 12, 2)->default(0);
            $t->decimal('total_amount', 12, 2)->default(0);
            $t->string('payment_method')->nullable();
            $t->text('kitchen_notes')->nullable();
            $t->unsignedBigInteger('pos_transaction_id')->nullable();
            $t->unsignedBigInteger('created_by')->nullable();
            $t->timestamps();
        });

        Schema::create('restaurant_order_items', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('order_id');
            $t->string('item_type')->nullable();
            $t->unsignedBigInteger('item_id')->nullable();
            $t->string('item_name');
            $t->decimal('quantity', 12, 3)->default(1);
            $t->decimal('unit_price', 12, 2)->default(0);
            $t->decimal('subtotal', 12, 2)->default(0);
            $t->boolean('is_tax_exempt')->default(false);
            $t->string('item_discount_type')->nullable();
            $t->decimal('item_discount_value', 12, 2)->nullable();
            $t->decimal('item_discount_amount', 12, 2)->nullable();
            $t->timestamps();
        });

        // payOrder validates recipe stock for product lines even with inventory
        // OFF — the table must exist (stays empty here).
        Schema::create('product_recipes', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('company_id');
            $t->unsignedBigInteger('product_id');
            $t->unsignedBigInteger('ingredient_id');
            $t->decimal('quantity_needed', 12, 4)->default(0);
            $t->timestamps();
        });

        Schema::create('pra_logs', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('company_id')->nullable();
            $t->unsignedBigInteger('transaction_id')->nullable();
            $t->text('request_payload')->nullable();
            $t->text('response_payload')->nullable();
            $t->string('response_code')->nullable();
            $t->string('status')->nullable();
            $t->timestamps();
        });

        Schema::create('notifications', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('company_id')->nullable();
            $t->unsignedBigInteger('user_id')->nullable();
            $t->string('type');
            $t->string('title');
            $t->text('message');
            $t->boolean('read')->default(false);
            $t->json('metadata')->nullable();
            $t->timestamps();
        });

        Schema::create('pos_day_openings', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('company_id');
            $t->date('business_date');
            $t->decimal('opening_cash', 15, 2)->default(0);
            $t->unsignedBigInteger('entered_by')->nullable();
            $t->string('notes', 500)->nullable();
            $t->timestamps();
            $t->unique(['company_id', 'business_date']);
        });

        Schema::create('pos_day_close_reports', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('company_id');
            $t->date('report_date');
            $t->string('report_number', 50)->nullable();
            $t->integer('deleted_final_count')->default(0);
            $t->integer('deleted_provisional_count')->default(0);
            $t->text('local_summary')->nullable();
            $t->text('rider_summary')->nullable();
            $t->text('stream_summary')->nullable();
            $t->integer('total_invoices')->default(0);
            $t->decimal('total_amount', 15, 2)->default(0);
            $t->unsignedBigInteger('closed_by')->nullable();
            $t->text('notes')->nullable();
            $t->string('hash')->nullable();
            $t->timestamps();
        });
    }
}
