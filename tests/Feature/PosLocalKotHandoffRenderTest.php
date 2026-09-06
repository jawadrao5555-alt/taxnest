<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\Company;
use App\Models\RestaurantOrder;
use App\Services\KotPrintService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * OFFLINE KOT LOCAL HANDOFF — render-level single-slip proof (Sep 2026).
 *
 * An offline hold hands its kitchen slip to the shop PC. The cloud records a
 * LOCAL HANDOFF and stamps nothing until the shop PC's durable print.complete
 * ack. If no ack arrives inside the window the cloud takes the slip back as
 * one normal delta KOT job (recovery). A LATE ack — resurrected agent,
 * delayed drain — must never end in a second physical slip:
 *
 *   • pending recovery job  → the ack VOIDS it (agent never claims it);
 *   • recovery job already CLAIMED (printing) → the render path drops the
 *     shop-PC-printed lines from the baked snapshot → 204 → agent marks done
 *     without paper, and the result stamps nothing new.
 *
 * Both fences are exercised through the REAL agent HTTP endpoints (claim,
 * content, result), because the baked printed_item_ids snapshot is otherwise
 * authoritative at render (PosCounterKotDeltaSnapshotTest) and would happily
 * print the same lines again.
 */
class PosLocalKotHandoffRenderTest extends TestCase
{
    private string $agentKey = 'test-agent-key-localkot';

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
            'name' => 'Local KOT Co',
            'agent_api_key' => $this->agentKey,
            'agent_enabled' => true,
            'agent_last_seen' => now(),
            'pos_printer_settings' => json_encode([
                'silent_print_enabled' => true,
                'kot_printer' => 'KitchenPrinter',
            ]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        app()->instance('currentCompanyId', 1);
        Cache::flush();
    }

    // ── Helpers ──────────────────────────────────────────────────────────

    private function company(): Company
    {
        return Company::findOrFail(1);
    }

    /** An order whose lines the shop PC took over (offline hold + kot_document). */
    private function handedOffOrder(array $itemNames, string $aggregate): RestaurantOrder
    {
        $orderId = DB::table('restaurant_orders')->insertGetId([
            'company_id' => 1,
            'order_number' => 'ORD-' . strtoupper($aggregate),
            'order_type' => 'takeaway',
            'status' => 'held',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        foreach ($itemNames as $name) {
            DB::table('restaurant_order_items')->insert([
                'order_id' => $orderId, 'item_type' => 'manual', 'item_name' => $name,
                'quantity' => 1, 'unit_price' => 100, 'subtotal' => 100,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        $order = RestaurantOrder::findOrFail($orderId);
        $lineIds = DB::table('restaurant_order_items')->where('order_id', $orderId)->pluck('id')->map(fn ($i) => (int) $i)->all();
        $handoff = KotPrintService::openLocalHandoff($this->company(), $order, $aggregate, $lineIds, null, null, now());
        $this->assertNotNull($handoff, 'handoff row opened');
        $this->assertSame(KotPrintService::LOCAL_STATUS, $handoff->status);
        return $order;
    }

    /** Let the handoff window pass and run the sweep the agent's poll triggers. */
    private function expireHandoff(string $aggregate): void
    {
        DB::table('pos_print_jobs')->where('claim_token', 'ac:kot:' . $aggregate)
            ->update(['created_at' => now()->subSeconds(KotPrintService::LOCAL_HANDOFF_TIMEOUT_SECONDS + 5)]);
        $swept = KotPrintService::expireLocalHandoffs($this->company());
        $this->assertSame(['expired' => 1, 'queued' => 1], $swept, 'expiry = exactly one cloud recovery KOT');
    }

    private function recoveryJob(int $orderId): object
    {
        $jobs = DB::table('pos_print_jobs')->where('restaurant_order_id', $orderId)
            ->where('type', 'kot')
            ->where(fn ($q) => $q->whereNull('claim_token')->orWhere('claim_token', 'not like', 'ac:kot:%'))
            ->orderBy('id')->get();
        $this->assertCount(1, $jobs, 'exactly one cloud recovery job');
        return $jobs->first();
    }

    private function agentHeaders(): array
    {
        return ['Authorization' => 'Bearer ' . $this->agentKey];
    }

    private function agentClaim(): array
    {
        $res = $this->getJson('/api/agent/print-jobs', $this->agentHeaders());
        $res->assertOk();
        return $res->json('jobs') ?? [];
    }

    private function agentContent(int $jobId): \Illuminate\Testing\TestResponse
    {
        return $this->get('/api/agent/print-jobs/' . $jobId . '/content', $this->agentHeaders());
    }

    private function agentResult(int $jobId, bool $success): void
    {
        $this->postJson('/api/agent/print-jobs/' . $jobId . '/result', ['success' => $success], $this->agentHeaders())->assertOk();
    }

    private function lateAck(RestaurantOrder $order, string $aggregate): array
    {
        return DB::transaction(fn () => KotPrintService::acknowledgeLocalHandoff($this->company(), $order->fresh(), $aggregate, now()));
    }

    private function stamps(int $orderId): array
    {
        return DB::table('restaurant_order_items')->where('order_id', $orderId)->orderBy('id')
            ->get(['id', 'kot_printed_at', 'kot_batch_no'])->map(fn ($r) => [(int) $r->id, $r->kot_printed_at, $r->kot_batch_no])->all();
    }

    // ── 0. Recovery itself works: no ack ever → the cloud prints the slip ──

    public function test_expired_handoff_recovery_job_renders_the_lines_when_no_ack_arrives(): void
    {
        $order = $this->handedOffOrder(['Chicken Karahi', 'Garlic Naan'], 'off-0');
        // While the handoff is fresh the agent has nothing to print.
        $this->assertSame([], $this->agentClaim(), 'fresh handoff: cloud stays silent');
        $this->expireHandoff('off-0');

        $claimed = $this->agentClaim();
        $this->assertCount(1, $claimed);
        $content = $this->agentContent((int) $claimed[0]['id']);
        $content->assertOk();
        $this->assertStringContainsString('Chicken Karahi', $content->getContent());
        $this->assertStringContainsString('Garlic Naan', $content->getContent());
        $this->agentResult((int) $claimed[0]['id'], true);
        $this->assertSame(0, DB::table('restaurant_order_items')->where('order_id', $order->id)->whereNull('kot_printed_at')->count());
    }

    // ── 1. Late ack while the recovery job is still PENDING → voided, never claimed ──

    public function test_late_ack_voids_a_pending_recovery_job_before_the_agent_claims_it(): void
    {
        $order = $this->handedOffOrder(['Chicken Karahi', 'Garlic Naan'], 'off-1');
        $this->expireHandoff('off-1');
        $recovery = $this->recoveryJob($order->id);
        $this->assertSame('pending', $recovery->status);

        $ack = $this->lateAck($order, 'off-1');
        $this->assertSame([(int) $recovery->id], $ack['voided_job_ids']);
        $this->assertCount(2, $ack['printed_line_ids']);
        $this->assertSame('done', $ack['local_handoff']);

        $this->assertSame([], $this->agentClaim(), 'voided job is never handed to the agent');
        $this->assertSame('done', DB::table('pos_print_jobs')->where('id', $recovery->id)->value('status'));
        $this->assertStringContainsString('Superseded', (string) DB::table('pos_print_jobs')->where('id', $recovery->id)->value('error'));
        // Even a direct content fetch of the voided job renders nothing.
        $this->assertSame(204, $this->agentContent((int) $recovery->id)->getStatusCode());
        $this->assertSame(0, DB::table('restaurant_order_items')->where('order_id', $order->id)->whereNull('kot_printed_at')->count());
        $this->assertSame([1, 1], DB::table('restaurant_order_items')->where('order_id', $order->id)->pluck('kot_batch_no')->map(fn ($b) => (int) $b)->all(), 'shop PC slip = batch 1');
    }

    // ── 2. Late ack AFTER the agent claimed the recovery job → render fence (204), no paper ──

    public function test_late_ack_after_claim_makes_the_claimed_recovery_job_render_nothing(): void
    {
        $order = $this->handedOffOrder(['Chicken Karahi', 'Garlic Naan'], 'off-2');
        $this->expireHandoff('off-2');

        $claimed = $this->agentClaim();
        $this->assertCount(1, $claimed, 'agent claims the recovery job');
        $jobId = (int) $claimed[0]['id'];
        $this->assertSame('printing', DB::table('pos_print_jobs')->where('id', $jobId)->value('status'));
        $this->assertCount(2, (array) json_decode($claimed[0]['printed_item_ids'] ?? '[]', true), 'claim carries the baked snapshot of both lines');

        // The shop PC's ack lands between claim and render.
        $ack = $this->lateAck($order, 'off-2');
        $this->assertSame([], $ack['voided_job_ids'], 'a claimed job is fenced at render, not voided (claim fence)');
        $this->assertCount(2, $ack['printed_line_ids']);
        $stampsAfterAck = $this->stamps($order->id);

        // Render: the baked snapshot is authoritative for ordinary deltas, but
        // shop-PC-printed lines are never ours → nothing left → 204.
        $this->assertSame(204, $this->agentContent($jobId)->getStatusCode(), 'claimed recovery job must render NOTHING after the late ack');
        // Agent marks a 204 job done: no restamp, no batch bump.
        $this->agentResult($jobId, true);
        $this->assertSame($stampsAfterAck, $this->stamps($order->id), 'result of the empty job changes no stamps');
        $this->assertSame('done', DB::table('pos_print_jobs')->where('id', $jobId)->value('status'));
        // And nothing else is queued afterwards.
        $this->assertSame([], $this->agentClaim());
        $this->assertSame([], KotPrintService::enqueueForOrder($this->company(), $order->fresh(), null, true)['job_ids']);
    }

    // ── 3. Partial overlap: a cloud job that also carries lines the shop PC did NOT print keeps only those ──

    public function test_late_ack_trims_a_pending_cloud_job_to_the_lines_the_shop_pc_did_not_print(): void
    {
        $order = $this->handedOffOrder(['Chicken Karahi', 'Garlic Naan'], 'off-3');
        // A line added at the counter after the hold (cloud-side, never handed off).
        $extraId = (int) DB::table('restaurant_order_items')->insertGetId([
            'order_id' => $order->id, 'item_type' => 'manual', 'item_name' => 'Mint Raita',
            'quantity' => 1, 'unit_price' => 50, 'subtotal' => 50, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->expireHandoff('off-3');
        $recovery = $this->recoveryJob($order->id);
        $baked = array_map('intval', (array) json_decode($recovery->printed_item_ids, true));
        $this->assertCount(3, $baked, 'after expiry the recovery delta carries every unprinted line');

        $ack = $this->lateAck($order, 'off-3');
        $this->assertSame([(int) $recovery->id], $ack['trimmed_job_ids']);
        $this->assertSame([], $ack['voided_job_ids']);
        $this->assertSame([$extraId], array_map('intval', (array) json_decode(DB::table('pos_print_jobs')->where('id', $recovery->id)->value('printed_item_ids'), true)));

        $claimed = $this->agentClaim();
        $this->assertCount(1, $claimed);
        $content = $this->agentContent((int) $claimed[0]['id']);
        $content->assertOk();
        $this->assertStringContainsString('Mint Raita', $content->getContent());
        $this->assertStringNotContainsString('Chicken Karahi', $content->getContent(), 'shop-PC-printed line never reprints');
        $this->assertStringNotContainsString('Garlic Naan', $content->getContent());
        $this->agentResult((int) $claimed[0]['id'], true);
        $this->assertSame(0, DB::table('restaurant_order_items')->where('order_id', $order->id)->whereNull('kot_printed_at')->count());
    }
}
