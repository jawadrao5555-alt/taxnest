<?php

namespace Tests\Feature;

use App\Models\AgentCoreEvent;
use App\Models\Company;
use App\Services\AgentCoreProjectorRegistry;
use App\Services\AgentCoreProjectionService;
use App\Services\AgentCoreRestaurantProjector;
use App\Services\AgentCoreSaleProjector;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Mockery;
use Tests\TestCase;

class AgentCoreRestaurantProjectorTest extends TestCase
{
    private Company $company;
    private AgentCoreRestaurantProjector $projector;

    protected function setUp(): void
    {
        parent::setUp();
        Schema::dropAllTables();
        $this->schema();
        $now = now();
        foreach ([1, 2] as $id) DB::table('companies')->insert(['id' => $id, 'name' => "C{$id}", 'created_at' => $now, 'updated_at' => $now]);
        foreach ([[10, 1], [11, 1], [20, 2]] as [$id, $company]) DB::table('users')->insert([
            'id' => $id, 'company_id' => $company, 'name' => "U{$id}", 'created_at' => $now, 'updated_at' => $now,
        ]);
        DB::table('pos_products')->insert(['id' => 50, 'company_id' => 1, 'name' => 'Burger', 'category' => 'Grill', 'price' => 5, 'created_at' => $now, 'updated_at' => $now]);
        DB::table('pos_deals')->insert(['id' => 77, 'company_id' => 1, 'name' => 'Burger Combo', 'price' => 15, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now]);
        foreach ([[70, 1, '1'], [71, 1, '2'], [72, 2, 'X']] as [$id, $company, $number]) DB::table('restaurant_tables')->insert([
            'id' => $id, 'company_id' => $company, 'table_number' => $number, 'created_at' => $now, 'updated_at' => $now,
        ]);
        $this->company = Company::findOrFail(1);
        $this->projector = app(AgentCoreRestaurantProjector::class);
    }

    public function test_order_table_claim_cancel_and_revision_rules(): void
    {
        $this->assertSame('projected', $this->projectCommand('open', 'order.opened', 1, [
            'business_date' => '2026-10-06', 'table_id' => 70,
            'lines' => [['line_id' => 501, 'item_type' => 'product', 'item_id' => 50, 'quantity' => 2]],
        ])->status);
        $this->assertDatabaseHas('restaurant_tables', ['id' => 70, 'status' => 'occupied']);
        $this->assertSame('projected', $this->projectCommand('claim', 'order.claimed', 2)->status);
        $loser = $this->projectCommand('loser', 'order.claimed', 3, [], ['company_id' => '1', 'branch_id' => '3', 'user_id' => '11']);
        $this->assertSame('already_claimed', $loser->result['code']);
        $gap = $this->projectCommand('gap', 'order.cancelled', 5);
        $this->assertSame('retryable', $gap->status);
        $this->assertSame('aggregate-revision:counter:100:4', $gap->dependency);
        $this->assertSame('projected', $this->projectCommand('cancel', 'order.cancelled', 4)->status);
        $this->assertDatabaseHas('restaurant_orders', ['id' => 100, 'status' => 'cancelled']);
        $this->assertDatabaseHas('restaurant_tables', ['id' => 70, 'status' => 'available', 'occupied_since' => null]);
    }

    public function test_shift_is_tenant_scoped_and_preserves_occupied_timer(): void
    {
        $this->projectCommand('open', 'order.opened', 1, ['business_date' => '2026-10-06', 'table_id' => 70,
            'lines' => [['item_type' => 'product', 'item_id' => 50, 'quantity' => 1]]]);
        $since = DB::table('restaurant_tables')->where('id', 70)->value('occupied_since');
        $this->assertSame('projected', $this->projectCommand('shift', 'table.shifted', 2, ['target_table_id' => 71])->status);
        $this->assertSame($since, DB::table('restaurant_tables')->where('id', 71)->value('occupied_since'));
        $this->assertSame('table_conflict', $this->projectCommand('foreign', 'table.shifted', 3, ['target_table_id' => 72])->result['code']);
    }

    public function test_amend_assign_and_release_use_the_same_locked_order_lifecycle(): void
    {
        $this->projectCommand('open', 'order.opened', 1, ['business_date' => '2026-10-06',
            'lines' => [['item_type' => 'product', 'item_id' => 50, 'quantity' => 1]]]);
        $amended = $this->projectCommand('amend', 'order.amended', 2, ['lines' => [
            ['item_type' => 'product', 'item_id' => 50, 'quantity' => 3],
        ]]);
        $this->assertSame('projected', $amended->status);
        $this->assertSame(15.0, (float) DB::table('restaurant_orders')->where('id', 100)->value('subtotal'));
        $this->assertSame('projected', $this->projectCommand('assign', 'table.assigned', 3, ['table_id' => 70])->status);
        $this->assertSame('projected', $this->projectCommand('release', 'table.released', 4)->status);
        $this->assertDatabaseHas('restaurant_orders', ['id' => 100, 'table_id' => null]);
        $this->assertDatabaseHas('restaurant_tables', ['id' => 70, 'status' => 'available']);
    }

    public function test_atomic_settlement_enforces_online_gate_branch_and_business_date(): void
    {
        $this->projectCommand('open', 'order.opened', 1, ['business_date' => '2026-10-06',
            'lines' => [['item_type' => 'product', 'item_id' => 50, 'quantity' => 1]]]);
        DB::table('restaurant_orders')->where('id', 100)->update(['online_payment_awaited_at' => now()]);
        DB::table('pos_transactions')->insert([
            'id' => 900, 'company_id' => 1, 'branch_id' => 3, 'business_date' => '2026-10-06',
            'invoice_number' => 'INV-900', 'payment_method' => 'qr_payment', 'subtotal' => 5,
            'tax_amount' => 0, 'total_amount' => 5, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $blocked = $this->projectCommand('blocked', 'order.settled', 2, ['transaction_id' => 900, 'business_date' => '2026-10-06']);
        $this->assertSame('online_payment_awaited', $blocked->result['code']);
        $settled = $this->projectCommand('settled', 'order.settled', 3, [
            'transaction_id' => 900, 'business_date' => '2026-10-06', 'online_payment_confirmed' => true,
        ]);
        $this->assertSame('projected', $settled->status);
        $this->assertDatabaseHas('restaurant_orders', ['id' => 100, 'status' => 'completed', 'pos_transaction_id' => 900]);
    }

    public function test_kot_printed_respects_hidden_lines_and_station_routing(): void
    {
        DB::table('pos_stations')->insert(['id' => 80, 'company_id' => 1, 'name' => 'Grill',
            'categories' => json_encode(['Grill']), 'created_at' => now(), 'updated_at' => now()]);
        $this->projectCommand('open', 'order.opened', 1, ['business_date' => '2026-10-06', 'order_type' => 'delivery', 'lines' => [
            ['line_id' => 501, 'item_type' => 'product', 'item_id' => 50, 'quantity' => 1],
            ['line_id' => 502, 'item_type' => 'manual', 'name' => 'Delivery Charges', 'quantity' => 1, 'unit_price_cents' => 100, 'skip_kitchen' => true],
        ]]);
        $outcome = $this->projectCommand('printed', 'kot.printed', 2, ['station_id' => 80]);
        $this->assertSame([501], $outcome->result['printed_line_ids']);
        $this->assertNotNull(DB::table('restaurant_order_items')->where('id', 501)->value('kot_printed_at'));
        $this->assertNull(DB::table('restaurant_order_items')->where('id', 502)->value('kot_printed_at'));
        $requested = $this->projectCommand('requested', 'kot.requested', 3, ['delta' => true]);
        $this->assertSame('rejected', $requested->status);
        $this->assertSame('kot_unavailable', $requested->result['code']);
    }

    public function test_node_shaped_string_aggregate_maps_across_delta_revisions(): void
    {
        $aggregate = 'order-local-01HZX9QK';
        $opened = $this->projectThroughRegistry('node-open', 'order.open', 1, [
            'business_date' => '2026-10-06', 'table_id' => null,
        ], $aggregate);
        $this->assertSame('projected', $opened->status);
        $serverOrderId = $opened->result['order_id'];
        $this->assertIsInt($serverOrderId);

        $added = $this->projectThroughRegistry('node-add', 'order.line.add', 2, [
            'line_id' => 'line-local-burger', 'product_id' => 50, 'name' => 'Burger',
            'quantity' => 2, 'unit_price_cents' => 500, 'tax_snapshot' => [], 'recipe_snapshot' => [],
        ], $aggregate);
        $this->assertSame('projected', $added->status);
        $this->assertDatabaseHas('restaurant_order_items', ['order_id' => $serverOrderId, 'quantity' => 2, 'subtotal' => 10]);

        $consumed = $this->projectThroughRegistry('node-consume', 'order.line.consume', 3, [
            'line_id' => 'line-local-burger',
        ], $aggregate);
        $this->assertSame('projected', $consumed->status);
        $this->assertTrue($consumed->result['consumed']);
        $this->assertDatabaseHas('agent_core_aggregate_mappings', [
            'company_id' => 1, 'branch_id' => 3, 'local_type' => 'restaurant_order',
            'local_aggregate_id' => $aggregate, 'cloud_id' => $serverOrderId,
        ]);
        $this->assertSame(2, DB::table('agent_core_aggregate_mappings')->count());

        $claimed = $this->projectCommand('node-claim', 'order.claim', 4, [], null, $aggregate);
        $this->assertSame('projected', $claimed->status);
        $this->assertDatabaseHas('restaurant_orders', ['id' => $serverOrderId, 'assigned_cashier_id' => 10]);

        $waiting = $this->projectCommand('node-settle', 'order.settle', 5, [
            'total_cents' => 1000, 'tax_cents' => 0, 'discount_cents' => 0, 'payment' => ['method' => 'cash'],
        ], null, $aggregate);
        $this->assertSame('rejected', $waiting->status);
        $this->assertSame('sale_snapshot_required', $waiting->result['code']);
        $this->assertDatabaseHas('restaurant_orders', ['id' => $serverOrderId, 'status' => 'held', 'pos_transaction_id' => null]);
    }

    public function test_out_of_order_string_aggregate_waits_instead_of_becoming_terminal(): void
    {
        $waiting = $this->projectCommand('early-line', 'order.line.add', 2, [
            'line_id' => 'early', 'product_id' => 50, 'quantity' => 1, 'unit_price_cents' => 500,
        ], null, 'order-not-uploaded-yet');
        $this->assertSame('retryable', $waiting->status);
        $this->assertSame('aggregate-revision:counter:order-not-uploaded-yet:1', $waiting->dependency);
    }

    public function test_node_table_claim_and_release_resolve_local_order_mapping(): void
    {
        $orderAggregate = 'order-for-table-local';
        $opened = $this->projectCommand('table-order-open', 'order.open', 1, [
            'business_date' => '2026-10-06',
        ], null, $orderAggregate);
        $claimed = $this->projectCommand('node-table-claim', 'table.claim', 1, [
            'order_id' => $orderAggregate,
        ], null, '70');
        $this->assertSame('projected', $claimed->status);
        $this->assertDatabaseHas('restaurant_orders', ['id' => $opened->result['order_id'], 'table_id' => 70]);

        $released = $this->projectCommand('node-table-release', 'table.release', 2, [], null, '70');
        $this->assertSame('projected', $released->status);
        $this->assertDatabaseHas('restaurant_orders', ['id' => $opened->result['order_id'], 'table_id' => null]);
    }

    public function test_canonical_settlement_projects_authoritative_sale_and_binds_transaction_atomically(): void
    {
        $aggregate = 'order-cloud-settlement';
        $opened = $this->projectCommand('cloud-open', 'order.open', 1, ['business_date' => '2026-10-06'], null, $aggregate);
        $this->projectCommand('cloud-line', 'order.line.add', 2, [
            'line_id' => 'cloud-line-1', 'product_id' => 50, 'name' => 'Burger',
            'quantity' => 1, 'unit_price_cents' => 500,
        ], null, $aggregate);

        $sales = Mockery::mock(AgentCoreSaleProjector::class);
        $sales->shouldReceive('project')->once()->withArgs(function ($company, $device, $wire): bool {
            $this->assertSame('counter', $device);
            $this->assertSame('pra.manual-immediate.v1', $wire['payload']['schema']);
            $this->assertSame('settlement-offline-0001', $wire['payload']['sale']['offline_uuid']);
            DB::table('pos_transactions')->insert([
                'id' => 901, 'company_id' => 1, 'branch_id' => 3, 'business_date' => '2026-10-06',
                'invoice_number' => 'INV-901', 'payment_method' => 'cash', 'subtotal' => 5,
                'tax_amount' => 0, 'total_amount' => 5, 'created_at' => now(), 'updated_at' => now(),
            ]);
            return true;
        })->andReturn([
            'status' => 'projected', 'transaction_id' => 901, 'invoice_number' => 'INV-901',
            'pra_status' => null, 'replayed' => false,
        ]);
        $this->app->instance(AgentCoreSaleProjector::class, $sales);
        $this->projector = app(AgentCoreRestaurantProjector::class);

        $settled = $this->projectThroughRegistry('cloud-settle', 'order.settle', 3, [
            'sale_snapshot' => $this->saleSnapshot('settlement-offline-0001'),
        ], $aggregate);
        $this->assertSame('projected', $settled->status);
        $this->assertSame(901, $settled->result['transaction_id']);
        $this->assertDatabaseHas('restaurant_orders', [
            'id' => $opened->result['order_id'], 'status' => 'completed', 'pos_transaction_id' => 901,
        ]);
        $this->assertDatabaseHas('agent_core_aggregate_mappings', [
            'local_type' => 'restaurant_sale', 'local_aggregate_id' => $aggregate,
            'cloud_type' => 'pos_transaction', 'cloud_id' => 901,
        ]);

        // A lost HTTP response causes the identical inbox event to be retried.
        // ProjectionService must replay the committed result without another sale.
        $replayed = app(AgentCoreProjectionService::class)->project($this->company, 'counter', [
            'event_id' => 'cloud-settle', 'event_type' => 'order.settled',
            'occurred_at' => '2026-10-06T12:00:00Z', 'idempotency_key' => 'idem-cloud-settle',
            'payload' => [
                'schema' => 'local-core.order.v1', 'command_type' => 'order.settle',
                'aggregate_id' => $aggregate, 'aggregate_revision' => 3,
                'data' => ['sale_snapshot' => $this->saleSnapshot('settlement-offline-0001')],
            ],
            'scope' => ['company_id' => '1', 'branch_id' => '3', 'device_id' => 'counter', 'user_id' => '10'],
        ]);
        $this->assertSame('projected', $replayed->status);
        $this->assertSame(901, $replayed->result['transaction_id']);
        $this->assertSame(1, DB::table('pos_transactions')->where('id', 901)->count());
    }

    public function test_sale_rejection_rolls_back_transaction_mapping_and_order_settlement(): void
    {
        $aggregate = 'order-payment-rejected';
        $opened = $this->projectCommand('reject-open', 'order.open', 1, ['business_date' => '2026-10-06'], null, $aggregate);
        $this->projectCommand('reject-line', 'order.line.add', 2, [
            'line_id' => 'reject-line-1', 'product_id' => 50, 'name' => 'Burger',
            'quantity' => 1, 'unit_price_cents' => 500,
        ], null, $aggregate);

        $sales = Mockery::mock(AgentCoreSaleProjector::class);
        $sales->shouldReceive('project')->once()->andReturnUsing(function (): never {
            DB::table('pos_transactions')->insert([
                'id' => 902, 'company_id' => 1, 'branch_id' => 3, 'business_date' => '2026-10-06',
                'invoice_number' => 'INV-902', 'payment_method' => 'card', 'subtotal' => 5,
                'tax_amount' => 0, 'total_amount' => 5, 'created_at' => now(), 'updated_at' => now(),
            ]);
            throw ValidationException::withMessages(['payload.sale' => ['Payment was rejected.']]);
        });
        $this->app->instance(AgentCoreSaleProjector::class, $sales);
        $this->projector = app(AgentCoreRestaurantProjector::class);

        $rejected = $this->projectThroughRegistry('reject-settle', 'order.settle', 3, [
            'sale_snapshot' => $this->saleSnapshot('settlement-offline-0002', 'card'),
        ], $aggregate);
        $this->assertSame('rejected', $rejected->status);
        $this->assertSame('sale_rejected', $rejected->result['code']);
        $this->assertDatabaseMissing('pos_transactions', ['id' => 902]);
        $this->assertDatabaseHas('restaurant_orders', [
            'id' => $opened->result['order_id'], 'status' => 'held', 'pos_transaction_id' => null,
        ]);
        $this->assertDatabaseMissing('agent_core_aggregate_mappings', [
            'local_type' => 'restaurant_sale', 'local_aggregate_id' => $aggregate,
        ]);
    }

    public function test_shared_universal_fixture_projects_one_sale_and_completes_one_order(): void
    {
        $fixture = json_decode(file_get_contents(base_path('tests/Fixtures/local-core-held-settlement.json')), true, 512, JSON_THROW_ON_ERROR);
        $aggregate = $fixture['aggregate_id'];
        $sale = $this->normalizedFixtureSale($fixture);
        $heldLines = array_map(function (array $line): array {
            $line['direct_consumption_snapshot'] ??= [];
            $line['deal_snapshot'] ??= [];
            return $line;
        }, $sale['items']);
        $opened = $this->projectThroughRegistry('fixture-held', 'order.hold', 1, [
            'order_snapshot' => [
                'order_id' => $aggregate, 'business_date' => $sale['business_date'],
                'order_type' => 'dine_in', 'table_id' => 70, 'lines' => $heldLines,
                'totals' => [
                    'subtotal_cents' => $sale['totals']['subtotal_cents'],
                    'discount_cents' => $sale['totals']['discount_cents'],
                    'tax_cents' => $sale['totals']['tax_cents'],
                    'total_cents' => $sale['totals']['total_cents'],
                ],
            ],
        ], $aggregate);

        $sales = Mockery::mock(AgentCoreSaleProjector::class);
        $sales->shouldReceive('project')->once()->withArgs(function ($company, $device, $wire) use ($sale): bool {
            $this->assertSame($sale, $wire['payload']['sale']);
            DB::table('pos_transactions')->insert([
                'id' => 903, 'company_id' => 1, 'branch_id' => 3, 'business_date' => $sale['business_date'],
                'invoice_number' => 'INV-903', 'payment_method' => 'card', 'subtotal' => 25,
                'tax_amount' => 3.84, 'total_amount' => 27.84, 'created_at' => now(), 'updated_at' => now(),
            ]);
            return true;
        })->andReturn([
            'status' => 'projected', 'transaction_id' => 903, 'invoice_number' => 'INV-903',
            'pra_status' => null, 'replayed' => false,
        ]);
        $this->app->instance(AgentCoreSaleProjector::class, $sales);
        $this->projector = app(AgentCoreRestaurantProjector::class);

        $settled = $this->projectThroughRegistry('fixture-settle', 'order.settle', 2, [
            'sale_snapshot' => $sale,
        ], $aggregate);
        $this->assertSame('projected', $settled->status);
        $this->assertSame(1, DB::table('pos_transactions')->count());
        $this->assertSame(1, DB::table('restaurant_orders')->where('status', 'completed')->count());
        $this->assertDatabaseHas('restaurant_orders', [
            'id' => $opened->result['order_id'], 'status' => 'completed', 'pos_transaction_id' => 903,
        ]);
    }

    public function test_table_shift_target_and_source_races_leave_both_tables_and_order_unchanged(): void
    {
        $aggregate = 'race-order';
        $opened = $this->projectCommand('race-open', 'order.open', 1, [
            'business_date' => '2026-10-06', 'table_id' => 70,
        ], null, $aggregate);
        $sourceSince = DB::table('restaurant_tables')->where('id', 70)->value('occupied_since');
        DB::table('restaurant_orders')->insert([
            'id' => 200, 'company_id' => 1, 'order_number' => 'RACE-200', 'table_id' => 71,
            'order_type' => 'dine_in', 'status' => 'held', 'source' => 'test',
            'subtotal' => 0, 'discount_amount' => 0, 'tax_amount' => 0, 'total_amount' => 0,
            'priority' => false, 'created_by' => 10, 'kot_print_count' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('restaurant_tables')->where('id', 71)->update(['status' => 'occupied', 'occupied_since' => now()]);

        $targetRace = $this->projectCommand('race-target', 'table.shift', 1, [
            'order_id' => $aggregate, 'target_table_id' => 71,
        ], null, '70');
        $this->assertSame('table_conflict', $targetRace->result['code']);
        $this->assertDatabaseHas('restaurant_orders', ['id' => $opened->result['order_id'], 'table_id' => 70]);
        $this->assertSame('occupied', DB::table('restaurant_tables')->where('id', 70)->value('status'));
        $this->assertSame($sourceSince, DB::table('restaurant_tables')->where('id', 70)->value('occupied_since'));
        $this->assertSame('occupied', DB::table('restaurant_tables')->where('id', 71)->value('status'));

        DB::table('restaurant_orders')->where('id', 200)->delete();
        DB::table('restaurant_orders')->where('id', $opened->result['order_id'])->update(['table_id' => 71]);
        DB::table('restaurant_tables')->where('id', 70)->update(['status' => 'available', 'occupied_since' => null]);
        $sourceRace = $this->projectCommand('race-source', 'table.shift', 2, [
            'order_id' => $aggregate, 'target_table_id' => 70,
        ], null, '70');
        $this->assertSame('table_conflict', $sourceRace->result['code']);
        $this->assertDatabaseHas('restaurant_orders', ['id' => $opened->result['order_id'], 'table_id' => 71]);
        $this->assertDatabaseHas('restaurant_tables', ['id' => 70, 'status' => 'available', 'occupied_since' => null]);
    }

    private function projectCommand(string $id, string $command, int $revision, array $data = [], ?array $scope = null, string $aggregate = '100')
    {
        $scope ??= ['company_id' => '1', 'branch_id' => '3', 'user_id' => '10'];
        $event = new AgentCoreEvent();
        $event->forceFill([
            'company_id' => 1, 'device_uid' => 'counter', 'event_id' => $id, 'idempotency_key' => "idem-{$id}",
            'event_type' => str_starts_with($command, 'kot.') ? 'kot.updated' : 'order.updated',
            'occurred_at' => now(), 'content_hash' => hash('sha256', $id), 'event_scope' => $scope,
            'payload' => ['schema' => 'local-core.order.v1', 'command_type' => $command, 'aggregate_id' => $aggregate, 'aggregate_revision' => $revision, 'data' => $data],
            'projection_status' => 'received',
        ])->save();
        $outcome = $this->projector->project($this->company, $event, $data, $scope);
        if ($outcome->status === 'projected') {
            $event->update(['projection_status' => 'projected', 'projection_result' => $outcome->result]);
        }
        return $outcome;
    }

    private function projectThroughRegistry(string $id, string $command, int $revision, array $data, string $aggregate)
    {
        $scope = ['company_id' => '1', 'branch_id' => '3', 'device_id' => 'counter', 'user_id' => '10'];
        $wire = [
            'event_id' => $id,
            'event_type' => match ($command) {
                'order.open' => 'order.created',
                'order.hold' => 'order.held',
                'order.settle' => 'order.settled',
                default => 'order.updated',
            },
            'occurred_at' => '2026-10-06T12:00:00Z',
            'idempotency_key' => "idem-{$id}",
            'payload' => [
                'schema' => 'local-core.order.v1', 'command_type' => $command,
                'aggregate_id' => $aggregate, 'aggregate_revision' => $revision, 'data' => $data,
            ],
            'scope' => $scope,
        ];
        $event = new AgentCoreEvent();
        $event->forceFill([
            'company_id' => 1, 'device_uid' => 'counter', 'event_id' => $id,
            'idempotency_key' => "idem-{$id}", 'event_type' => $wire['event_type'],
            'occurred_at' => $wire['occurred_at'], 'content_hash' => hash('sha256', $id),
            'event_scope' => $scope, 'payload' => $wire['payload'], 'projection_status' => 'received',
        ])->save();
        $outcome = app(AgentCoreProjectorRegistry::class)->project($this->company, 'counter', $event, $wire);
        if ($outcome->status === 'projected') {
            $event->update(['projection_status' => 'projected', 'projection_result' => $outcome->result]);
        }
        return $outcome;
    }

    private function saleSnapshot(string $offlineUuid, string $payment = 'cash'): array
    {
        return [
            'offline_uuid' => $offlineUuid, 'payment_method' => $payment,
            'items' => [[
                'name' => 'Burger', 'quantity' => 1, 'unit_price' => 5,
                'line_total' => 5, 'type' => 'product', 'item_id' => 50,
                'is_tax_exempt' => false, '_manual' => false,
            ]],
            'totals' => [
                'subtotal' => 5, 'discount_amount' => 0, 'tax_amount' => 0,
                'total_amount' => 5, 'tax_inclusive' => false,
            ],
            'discount_type' => 'fixed', 'discount_value' => 0,
            'cash_received' => $payment === 'cash' ? 5 : null,
        ];
    }

    private function normalizedFixtureSale(array $fixture): array
    {
        $sale = $fixture['universal_input'];
        $expected = $fixture['normalized'];
        $sale['order_id'] = $fixture['aggregate_id'];
        $sale['items'][0] = array_replace($sale['items'][0], $expected['line'], [
            'unit_price' => $expected['line']['unit_price_cents'] / 100,
            'line_total' => $expected['line']['unit_price_cents'] * $expected['line']['quantity'] / 100,
        ]);
        $sale['items'][1] = array_replace($sale['items'][1], $expected['deal_line'], [
            'unit_price' => $expected['deal_line']['unit_price_cents'] / 100,
            'line_total' => $expected['deal_line']['unit_price_cents'] * $expected['deal_line']['quantity'] / 100,
        ]);
        $sale['totals'] = array_replace($sale['totals'], [
            'subtotal_cents' => $expected['subtotal_cents'],
            'tax_cents' => $expected['tax_cents'],
            'discount_cents' => $expected['discount_cents'],
            'total_cents' => $expected['total_cents'],
        ]);
        $sale['immutable_refs'] = [
            'order' => $sale['order_ref'], 'customer' => $sale['customer_ref'],
            'offline_uuid' => $sale['offline_uuid'],
        ];
        $sale['immutable_metadata'] = [
            'occurred_at_ms' => $sale['occurred_at_ms'], 'terminal_id' => $sale['terminal_id'],
            'delivery_address' => null, 'tax_pricing' => $sale['tax_pricing'],
        ];
        return $sale;
    }

    private function schema(): void
    {
        Schema::create('companies', fn (Blueprint $t) => [$t->id(), $t->string('name'), $t->softDeletes(), $t->timestamps()]);
        Schema::create('users', fn (Blueprint $t) => [$t->id(), $t->unsignedBigInteger('company_id'), $t->string('name'), $t->timestamps()]);
        Schema::create('agent_core_events', function (Blueprint $t): void {
            $t->id(); $t->unsignedBigInteger('company_id'); $t->string('device_uid'); $t->string('event_id'); $t->string('idempotency_key');
            $t->string('event_type'); $t->timestamp('occurred_at')->nullable(); $t->json('payload'); $t->string('content_hash');
            $t->json('event_scope')->nullable(); $t->string('projection_status')->nullable(); $t->json('projection_result')->nullable();
            $t->text('projection_error')->nullable(); $t->string('projection_dependency')->nullable(); $t->unsignedInteger('projection_attempts')->default(0);
            $t->timestamp('projected_at')->nullable(); $t->boolean('legacy_backfilled')->default(false); $t->timestamps();
        });
        Schema::create('agent_core_aggregate_mappings', function (Blueprint $t): void {
            $t->id(); $t->unsignedBigInteger('company_id'); $t->unsignedBigInteger('branch_id');
            $t->string('local_type', 32); $t->string('local_aggregate_id', 128);
            $t->string('cloud_type', 64); $t->unsignedBigInteger('cloud_id'); $t->json('metadata')->nullable();
            $t->timestamps(); $t->unique(['company_id', 'branch_id', 'local_type', 'local_aggregate_id']);
        });
        Schema::create('restaurant_tables', function (Blueprint $t): void {
            $t->id(); $t->unsignedBigInteger('company_id'); $t->string('table_number'); $t->string('status')->default('available');
            $t->boolean('is_active')->default(true); $t->unsignedBigInteger('locked_by_user_id')->nullable();
            $t->timestamp('locked_at')->nullable(); $t->timestamp('occupied_since')->nullable(); $t->timestamps();
        });
        Schema::create('restaurant_orders', function (Blueprint $t): void {
            $t->id(); $t->unsignedBigInteger('company_id'); $t->string('order_number')->unique(); $t->unsignedBigInteger('table_id')->nullable();
            $t->string('order_type')->default('dine_in'); $t->string('status')->default('held'); $t->string('source')->nullable();
            $t->unsignedBigInteger('customer_id')->nullable(); $t->string('customer_name')->nullable(); $t->string('customer_phone')->nullable();
            $t->text('delivery_address')->nullable(); $t->decimal('subtotal', 15, 2)->default(0); $t->decimal('discount_amount', 15, 2)->default(0);
            $t->decimal('tax_amount', 15, 2)->default(0); $t->decimal('total_amount', 15, 2)->default(0); $t->string('payment_method')->nullable();
            $t->text('kitchen_notes')->nullable(); $t->boolean('priority')->default(false); $t->unsignedBigInteger('pos_transaction_id')->nullable();
            $t->unsignedBigInteger('created_by'); $t->unsignedBigInteger('assigned_cashier_id')->nullable(); $t->timestamp('kot_sent_at')->nullable();
            $t->unsignedInteger('kot_print_count')->default(0); $t->timestamp('online_payment_awaited_at')->nullable();
            $t->timestamp('cancelled_at')->nullable(); $t->unsignedBigInteger('cancelled_by')->nullable(); $t->timestamps();
        });
        Schema::create('restaurant_order_items', function (Blueprint $t): void {
            $t->id(); $t->unsignedBigInteger('order_id'); $t->string('item_type'); $t->unsignedBigInteger('item_id')->nullable(); $t->string('item_name');
            $t->decimal('quantity', 10, 2); $t->decimal('unit_price', 15, 2); $t->decimal('subtotal', 15, 2); $t->text('special_notes')->nullable();
            $t->boolean('is_tax_exempt')->default(false); $t->boolean('skip_kitchen')->default(false); $t->timestamp('kot_printed_at')->nullable();
            $t->unsignedInteger('kot_batch_no')->nullable(); $t->timestamps();
        });
        Schema::create('pos_products', function (Blueprint $t): void {
            $t->id(); $t->unsignedBigInteger('company_id'); $t->string('name'); $t->string('category')->nullable(); $t->decimal('price', 15, 2); $t->timestamps();
        });
        Schema::create('pos_services', function (Blueprint $t): void {
            $t->id(); $t->unsignedBigInteger('company_id'); $t->string('name'); $t->decimal('price', 15, 2); $t->timestamps();
        });
        Schema::create('pos_deals', function (Blueprint $t): void {
            $t->id(); $t->unsignedBigInteger('company_id'); $t->string('name'); $t->decimal('price', 15, 2);
            $t->boolean('is_active')->default(true); $t->timestamps();
        });
        Schema::create('pos_stations', function (Blueprint $t): void {
            $t->id(); $t->unsignedBigInteger('company_id'); $t->string('name'); $t->json('categories'); $t->string('printer_name')->nullable();
            $t->boolean('is_active')->default(true); $t->integer('sort')->default(0); $t->timestamps();
        });
        Schema::create('pos_transactions', function (Blueprint $t): void {
            $t->id(); $t->unsignedBigInteger('company_id'); $t->unsignedBigInteger('branch_id')->nullable(); $t->date('business_date')->nullable();
            $t->string('invoice_number'); $t->string('payment_method'); $t->decimal('subtotal', 15, 2); $t->decimal('tax_amount', 15, 2);
            $t->decimal('total_amount', 15, 2); $t->timestamps();
        });
    }
}