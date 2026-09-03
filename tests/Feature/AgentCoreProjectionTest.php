<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Services\AgentCoreProjectorRegistry;
use App\Services\AgentCoreReconciliationService;
use App\Services\AgentCoreSaleProjector;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Mockery;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class AgentCoreProjectionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Schema::dropAllTables();
        Schema::create('companies', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('agent_api_key');
            $table->boolean('agent_enabled')->default(true);
            $table->boolean('agent_core_enabled')->default(true);
            $table->softDeletes();
            $table->timestamps();
        });
        Schema::create('pos_agent_devices', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->string('device_uid', 64);
            $table->timestamps();
        });
        Schema::create('users', function (Blueprint $table): void {
            $table->id(); $table->unsignedBigInteger('company_id'); $table->string('name');
            $table->string('pos_role')->nullable(); $table->string('role')->nullable();
            $table->boolean('is_active')->default(true); $table->timestamps();
        });
        Schema::create('agent_core_events', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->string('device_uid', 64);
            $table->string('event_id', 64);
            $table->string('idempotency_key', 128);
            $table->string('event_type', 64);
            $table->timestamp('occurred_at')->nullable();
            $table->json('payload');
            $table->string('content_hash', 64);
            $table->json('event_scope')->nullable();
            $table->string('projection_status', 24)->nullable();
            $table->json('projection_result')->nullable();
            $table->text('projection_error')->nullable();
            $table->string('projection_dependency', 191)->nullable();
            $table->unsignedInteger('projection_attempts')->default(0);
            $table->timestamp('projected_at')->nullable();
            $table->boolean('legacy_backfilled')->default(false);
            $table->timestamps();
            $table->unique(['company_id', 'device_uid', 'event_id']);
            $table->unique(['company_id', 'device_uid', 'idempotency_key']);
        });
        Schema::create('agent_core_scope_leases', function (Blueprint $table): void {
            $table->id(); $table->unsignedBigInteger('company_id'); $table->string('device_uid');
            $table->unsignedBigInteger('branch_id'); $table->unsignedBigInteger('user_id');
            $table->string('token_hash'); $table->uuid('nonce'); $table->json('allowed_actions');
            $table->string('permission_version'); $table->timestamp('expires_at'); $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
        });
        foreach ([1, 2] as $id) {
            DB::table('companies')->insert([
                'id' => $id, 'name' => "Shop {$id}", 'agent_api_key' => "key-{$id}",
                'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('pos_agent_devices')->insert([
                'company_id' => $id, 'device_uid' => 'counter-1',
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        $stamp = now();
        DB::table('users')->insert(['id' => 20, 'company_id' => 1, 'name' => 'Cashier',
            'pos_role' => 'pos_admin', 'role' => 'user', 'is_active' => true,
            'created_at' => $stamp, 'updated_at' => $stamp]);
        DB::table('agent_core_scope_leases')->insert([
            'id' => 1, 'company_id' => 1, 'device_uid' => 'counter-1', 'branch_id' => 10, 'user_id' => 20,
            'token_hash' => hash('sha256', 'test-lease'), 'nonce' => '00000000-0000-0000-0000-000000000001',
            'allowed_actions' => json_encode(['*']),
            'permission_version' => hash('sha256', implode('|', [$stamp->getTimestamp(), 1, 'pos_admin'])),
            'expires_at' => now()->addHour(), 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function event(string $id, string $type, array $payload, int $company = 1): array
    {
        if (str_starts_with((string) ($payload['schema'] ?? ''), 'local-core.')
            && !array_key_exists('data', $payload)) {
            $commands = ['order.created' => 'order.open', 'stock.adjusted' => 'stock.adjust',
                'expense.created' => 'cash.expense'];
            $payload += ['command_type' => $commands[$type] ?? $type, 'aggregate_id' => $id,
                'aggregate_revision' => 1, 'data' => []];
        }
        return [
            'event_id' => $id,
            'event_type' => $type,
            'occurred_at' => '2026-10-06T12:00:00Z',
            'idempotency_key' => "idem-{$id}",
            'payload' => $payload,
            'scope' => [
                'company_id' => (string) $company, 'branch_id' => '10',
                'device_id' => 'counter-1', 'user_id' => '20',
            ],
            'scope_lease_id' => 1,
            'scope_lease' => 'test-lease',
        ];
    }

    private function send(array $events, int $company = 1)
    {
        return $this->withToken("key-{$company}")->postJson('/api/agent/v2/events', [
            'version' => 1, 'device_uid' => 'counter-1', 'events' => $events,
        ]);
    }

    public function test_missing_domain_schema_is_retryable_but_not_falsely_acknowledged(): void
    {
        $event = $this->event('order-1', 'order.created', ['schema' => 'local-core.order.v1']);
        $this->send([$event])->assertOk()
            ->assertJsonPath('acknowledged_ids', [])
            ->assertJsonPath('results.0.status', 'retryable')
            ->assertJsonPath('results.0.dependency', 'restaurant-schema');

        $this->send([$event])->assertOk()->assertJsonPath('duplicate_count', 1);
        $row = DB::table('agent_core_events')->first();
        $this->assertSame('retryable', $row->projection_status);
        $this->assertSame(2, (int) $row->projection_attempts);
    }

    public function test_dependencies_are_device_scoped_and_legacy_events_keep_ack_semantics(): void
    {
        $dependent = $this->event('stock-1', 'stock.adjusted', [
            'schema' => 'local-core.stock.v1', 'depends_on' => ['ring-1'],
        ]);
        $this->send([$dependent])->assertJsonPath('results.0.status', 'blocked-dependency');

        $ring = $this->event('ring-1', 'caller.ring', ['phone' => '03001234567']);
        $this->send([$ring])->assertJsonPath('acknowledged_ids.0', 'ring-1')
            ->assertJsonPath('results.0.legacy_inbox', true);
        $this->send([$dependent])->assertJsonPath('results.0.status', 'pending-domain');
    }

    public function test_wrong_schema_is_terminal_and_exactly_once_result_is_replayed(): void
    {
        $event = $this->event('expense-1', 'expense.created', ['schema' => 'wrong.v1']);
        $first = $this->send([$event])->assertJsonPath('rejected.0.error', 'projection_rejected')
            ->json('results.0');
        $second = $this->send([$event])->json('results.0');
        $this->assertSame($first, $second);
        $this->assertSame(1, (int) DB::table('agent_core_events')->value('projection_attempts'));
    }

    public function test_reconciliation_never_reads_another_company_or_device(): void
    {
        $this->send([$this->event('same-id', 'caller.ring', ['ring_id' => 'one'])], 1)->assertOk();
        $this->send([$this->event('same-id', 'caller.ring', ['ring_id' => 'two'], 2)], 2)->assertOk();

        $page = app(AgentCoreReconciliationService::class)->status(
            Company::query()->findOrFail(1), 'counter-1', ['same-id']
        );
        $this->assertCount(1, $page->items());
        $this->assertSame('same-id', $page->items()[0]->event_id);
        $this->assertSame(1, DB::table('agent_core_events')->where('company_id', 1)->count());
        $this->assertSame(1, DB::table('agent_core_events')->where('company_id', 2)->count());
    }

    public function test_unexpected_projector_failure_is_retryable_not_poisoned_or_acknowledged(): void
    {
        $registry = Mockery::mock(AgentCoreProjectorRegistry::class);
        $registry->shouldReceive('project')->andThrow(new \RuntimeException('temporary'));
        $this->app->instance(AgentCoreProjectorRegistry::class, $registry);

        $event = $this->event('order-transient', 'order.created', ['schema' => 'local-core.order.v1']);
        $this->send([$event])->assertOk()
            ->assertJsonPath('acknowledged_ids', [])
            ->assertJsonPath('results.0.status', 'retryable');
        $this->assertSame('retryable', DB::table('agent_core_events')->value('projection_status'));
    }

    public function test_local_core_scope_lease_rejects_spoof_expiry_revocation_action_and_permission_changes_before_inbox(): void
    {
        $base = $this->event('secured-order', 'order.created', ['schema' => 'local-core.order.v1']);

        $spoof = $base;
        $spoof['scope']['user_id'] = '21';
        $this->send([$spoof])->assertForbidden()->assertJsonPath('error', 'scope_lease_invalid');

        DB::table('agent_core_scope_leases')->where('id', 1)->update(['expires_at' => now()->subMinute()]);
        $this->send([$base])->assertForbidden();
        DB::table('agent_core_scope_leases')->where('id', 1)->update(['expires_at' => now()->addHour(), 'revoked_at' => now()]);
        $this->send([$base])->assertForbidden();
        DB::table('agent_core_scope_leases')->where('id', 1)->update(['revoked_at' => null, 'allowed_actions' => json_encode([])]);
        $this->send([$base])->assertForbidden();
        DB::table('agent_core_scope_leases')->where('id', 1)->update(['allowed_actions' => json_encode(['*'])]);
        DB::table('users')->where('id', 20)->update(['updated_at' => now()->addMinute()]);
        $this->send([$base])->assertForbidden();

        $this->assertSame(0, DB::table('agent_core_events')->count());
    }

    public function test_http_order_settled_is_atomic_and_lost_ack_replays_one_sale_and_order(): void
    {
        $this->createRestaurantProjectionSchema();
        $aggregate = 'http-order-settlement';
        $open = $this->event('http-open', 'order.created', [
            'schema' => 'local-core.order.v1', 'command_type' => 'order.open',
            'aggregate_id' => $aggregate, 'aggregate_revision' => 1,
            'data' => ['business_date' => '2026-10-06'],
        ]);
        $line = $this->event('http-line', 'order.updated', [
            'schema' => 'local-core.order.v1', 'command_type' => 'order.line.add',
            'aggregate_id' => $aggregate, 'aggregate_revision' => 2,
            'data' => ['line_id' => 'http-line-1', 'product_id' => 50, 'name' => 'Burger',
                'quantity' => 1, 'unit_price_cents' => 500],
        ]);
        $this->send([$open, $line])->assertOk()->assertJsonCount(2, 'acknowledged_ids');

        $sales = Mockery::mock(AgentCoreSaleProjector::class);
        $sales->shouldReceive('project')->once()->andReturnUsing(function (): array {
            DB::table('pos_transactions')->insert([
                'id' => 990, 'company_id' => 1, 'branch_id' => 10, 'business_date' => '2026-10-06',
                'invoice_number' => 'HTTP-990', 'payment_method' => 'cash', 'subtotal' => 5,
                'tax_amount' => 0, 'total_amount' => 5, 'created_at' => now(), 'updated_at' => now(),
            ]);
            return ['status' => 'projected', 'transaction_id' => 990, 'invoice_number' => 'HTTP-990', 'replayed' => false];
        });
        $this->app->instance(AgentCoreSaleProjector::class, $sales);
        $settle = $this->event('http-settle', 'order.settled', [
            'schema' => 'local-core.order.v1', 'command_type' => 'order.settle',
            'aggregate_id' => $aggregate, 'aggregate_revision' => 3,
            'data' => ['sale_snapshot' => $this->httpSaleSnapshot('http-offline-sale-0001')],
        ]);

        $this->send([$settle])->assertOk()->assertJsonPath('results.0.transaction_id', 990);
        $this->send([$settle])->assertOk()
            ->assertJsonPath('duplicate_count', 1)
            ->assertJsonPath('results.0.transaction_id', 990);
        $this->assertSame(1, DB::table('pos_transactions')->where('id', 990)->count());
        $this->assertSame(1, DB::table('restaurant_orders')->where('status', 'completed')->where('pos_transaction_id', 990)->count());
        $this->assertSame(1, DB::table('restaurant_orders')->count());
        $this->assertSame('projected', DB::table('agent_core_events')->where('event_id', 'http-settle')->value('projection_status'));
    }

    public function test_http_rejected_settlement_rolls_back_sale_and_leaves_order_open(): void
    {
        $this->createRestaurantProjectionSchema();
        $aggregate = 'http-order-rejected';
        $this->send([
            $this->event('reject-open', 'order.created', [
                'schema' => 'local-core.order.v1', 'command_type' => 'order.open',
                'aggregate_id' => $aggregate, 'aggregate_revision' => 1,
                'data' => ['business_date' => '2026-10-06'],
            ]),
            $this->event('reject-line', 'order.updated', [
                'schema' => 'local-core.order.v1', 'command_type' => 'order.line.add',
                'aggregate_id' => $aggregate, 'aggregate_revision' => 2,
                'data' => ['line_id' => 'reject-http-line', 'product_id' => 50, 'name' => 'Burger',
                    'quantity' => 1, 'unit_price_cents' => 500],
            ]),
        ])->assertOk();

        $sales = Mockery::mock(AgentCoreSaleProjector::class);
        $sales->shouldReceive('project')->once()->andReturnUsing(function (): never {
            DB::table('pos_transactions')->insert([
                'id' => 991, 'company_id' => 1, 'branch_id' => 10, 'business_date' => '2026-10-06',
                'invoice_number' => 'HTTP-991', 'payment_method' => 'card', 'subtotal' => 5,
                'tax_amount' => 0, 'total_amount' => 5, 'created_at' => now(), 'updated_at' => now(),
            ]);
            throw ValidationException::withMessages(['payload.sale' => ['Payment rejected.']]);
        });
        $this->app->instance(AgentCoreSaleProjector::class, $sales);
        $settle = $this->event('reject-settle', 'order.settled', [
            'schema' => 'local-core.order.v1', 'command_type' => 'order.settle',
            'aggregate_id' => $aggregate, 'aggregate_revision' => 3,
            'data' => ['sale_snapshot' => $this->httpSaleSnapshot('http-offline-sale-0002', 'card')],
        ]);
        $this->send([$settle])->assertOk()->assertJsonPath('results.0.status', 'rejected');
        $this->assertSame(0, DB::table('pos_transactions')->count());
        $this->assertSame(0, DB::table('restaurant_orders')->where('status', 'completed')->count());
        $this->assertSame(1, DB::table('restaurant_orders')->where('status', 'held')->count());
        $this->assertSame(0, DB::table('agent_core_aggregate_mappings')->where('local_type', 'restaurant_sale')->count());
    }

    public function test_http_order_held_projects_frozen_snapshot_atomically_and_replays_lost_ack(): void
    {
        $this->createRestaurantProjectionSchema();
        $event = $this->event('held-core-event', 'order.held', [
            'schema' => 'local-core.order.v1', 'command_type' => 'order.hold',
            'aggregate_id' => 'held-order-opaque-1', 'aggregate_revision' => 1,
            'data' => ['order_snapshot' => $this->heldOrderSnapshot('held-order-opaque-1')],
        ]);
        $this->send([$event])->assertOk()
            ->assertJsonPath('results.0.status', 'projected')
            ->assertJsonPath('results.0.order_status', 'held');
        $orderId = (int) DB::table('restaurant_orders')->value('id');

        $this->send([$event])->assertOk()
            ->assertJsonPath('duplicate_count', 1)
            ->assertJsonPath('results.0.order_id', $orderId);
        $this->assertSame(1, DB::table('restaurant_orders')->count());
        $this->assertSame(2, DB::table('restaurant_order_items')->where('order_id', $orderId)->count());
        $this->assertSame(4, DB::table('agent_core_aggregate_mappings')->count());
        $this->assertSame(0, DB::table('pos_transactions')->count());
        $this->assertDatabaseHas('restaurant_tables', ['id' => 70, 'status' => 'occupied']);
    }

    public function test_http_order_hold_invalid_snapshot_and_table_race_write_no_domain_rows(): void
    {
        $this->createRestaurantProjectionSchema();
        $invalid = $this->heldOrderSnapshot('held-invalid-order');
        $invalid['totals']['total_cents'] = 99900;
        $this->send([$this->event('held-invalid', 'order.held', [
            'schema' => 'local-core.order.v1', 'command_type' => 'order.hold',
            'aggregate_id' => 'held-invalid-order', 'aggregate_revision' => 1,
            'data' => ['order_snapshot' => $invalid],
        ])])->assertOk()->assertJsonPath('results.0.status', 'rejected');
        $this->assertSame(0, DB::table('restaurant_orders')->count());
        $this->assertSame(0, DB::table('restaurant_order_items')->count());
        $this->assertSame(0, DB::table('agent_core_aggregate_mappings')->count());

        DB::table('restaurant_tables')->where('id', 70)->update([
            'status' => 'occupied', 'occupied_since' => now(),
        ]);
        $this->send([$this->event('held-table-race', 'order.held', [
            'schema' => 'local-core.order.v1', 'command_type' => 'order.hold',
            'aggregate_id' => 'held-race-order', 'aggregate_revision' => 1,
            'data' => ['order_snapshot' => $this->heldOrderSnapshot('held-race-order')],
        ])])->assertOk()->assertJsonPath('results.0.status', 'rejected');
        $this->assertSame(0, DB::table('restaurant_orders')->count());
        $this->assertSame(0, DB::table('restaurant_order_items')->count());
        $this->assertSame(0, DB::table('agent_core_aggregate_mappings')->count());
        $this->assertDatabaseHas('restaurant_tables', ['id' => 70, 'status' => 'occupied']);
    }

    private function heldOrderSnapshot(string $orderId): array
    {
        return [
            'order_id' => $orderId, 'business_date' => '2026-10-06', 'order_type' => 'dine_in', 'table_id' => '70',
            'lines' => [
                ['line_id' => 'held-line-burger', 'product_id' => 50, 'name' => 'Burger',
                    'quantity' => 2, 'unit_price_cents' => 500, 'tax_snapshot' => [], 'recipe_snapshot' => [],
                    'deal_snapshot' => [], 'direct_consumption_snapshot' => []],
                ['line_id' => 'held-line-note', 'product_id' => 50, 'name' => 'Burger Extra',
                    'quantity' => 1, 'unit_price_cents' => 100, 'tax_snapshot' => [], 'recipe_snapshot' => [],
                    'deal_snapshot' => [], 'direct_consumption_snapshot' => []],
            ],
            'totals' => ['subtotal_cents' => 1100, 'discount_cents' => 0, 'tax_cents' => 0, 'total_cents' => 1100],
        ];
    }

    private function httpSaleSnapshot(string $uuid, string $payment = 'cash'): array
    {
        return [
            'offline_uuid' => $uuid, 'payment_method' => $payment,
            'items' => [['name' => 'Burger', 'quantity' => 1, 'unit_price' => 5,
                'line_total' => 5, 'type' => 'product', 'item_id' => 50, 'is_tax_exempt' => false]],
            'totals' => ['subtotal' => 5, 'discount_amount' => 0, 'tax_amount' => 0,
                'total_amount' => 5, 'tax_inclusive' => false],
            'discount_type' => 'fixed', 'discount_value' => 0,
            'cash_received' => $payment === 'cash' ? 5 : null,
        ];
    }

    private function createRestaurantProjectionSchema(): void
    {
        Schema::create('agent_core_aggregate_mappings', function (Blueprint $table): void {
            $table->id(); $table->unsignedBigInteger('company_id'); $table->unsignedBigInteger('branch_id');
            $table->string('local_type', 32); $table->string('local_aggregate_id', 128);
            $table->string('cloud_type', 64); $table->unsignedBigInteger('cloud_id'); $table->json('metadata')->nullable();
            $table->timestamps(); $table->unique(['company_id', 'branch_id', 'local_type', 'local_aggregate_id']);
        });
        Schema::create('restaurant_orders', function (Blueprint $table): void {
            $table->id(); $table->unsignedBigInteger('company_id'); $table->string('order_number')->unique();
            $table->unsignedBigInteger('table_id')->nullable(); $table->string('order_type')->default('dine_in');
            $table->string('status')->default('held'); $table->string('source')->nullable();
            $table->decimal('subtotal', 15, 2)->default(0); $table->decimal('discount_amount', 15, 2)->default(0);
            $table->decimal('tax_amount', 15, 2)->default(0); $table->decimal('total_amount', 15, 2)->default(0);
            $table->string('payment_method')->nullable(); $table->unsignedBigInteger('pos_transaction_id')->nullable();
            $table->unsignedBigInteger('created_by'); $table->unsignedBigInteger('assigned_cashier_id')->nullable();
            $table->timestamp('online_payment_awaited_at')->nullable(); $table->timestamps();
        });
        Schema::create('restaurant_order_items', function (Blueprint $table): void {
            $table->id(); $table->unsignedBigInteger('order_id'); $table->string('item_type');
            $table->unsignedBigInteger('item_id')->nullable(); $table->string('item_name');
            $table->decimal('quantity', 10, 2); $table->decimal('unit_price', 15, 2); $table->decimal('subtotal', 15, 2);
            $table->text('special_notes')->nullable(); $table->boolean('is_tax_exempt')->default(false);
            $table->boolean('skip_kitchen')->default(false); $table->timestamp('kot_printed_at')->nullable();
            $table->unsignedInteger('kot_batch_no')->nullable(); $table->timestamps();
        });
        Schema::create('restaurant_tables', function (Blueprint $table): void {
            $table->id(); $table->unsignedBigInteger('company_id'); $table->string('table_number');
            $table->string('status')->default('available'); $table->boolean('is_active')->default(true);
            $table->unsignedBigInteger('locked_by_user_id')->nullable(); $table->timestamp('locked_at')->nullable();
            $table->timestamp('occupied_since')->nullable(); $table->timestamps();
        });
        Schema::create('pos_products', function (Blueprint $table): void {
            $table->id(); $table->unsignedBigInteger('company_id'); $table->string('name');
            $table->decimal('price', 15, 2); $table->timestamps();
        });
        Schema::create('pos_transactions', function (Blueprint $table): void {
            $table->id(); $table->unsignedBigInteger('company_id'); $table->unsignedBigInteger('branch_id');
            $table->date('business_date'); $table->string('invoice_number'); $table->string('payment_method');
            $table->decimal('subtotal', 15, 2); $table->decimal('tax_amount', 15, 2);
            $table->decimal('total_amount', 15, 2); $table->timestamps();
        });
        DB::table('pos_products')->insert(['id' => 50, 'company_id' => 1, 'name' => 'Burger', 'price' => 5,
            'created_at' => now(), 'updated_at' => now()]);
        DB::table('restaurant_tables')->insert(['id' => 70, 'company_id' => 1, 'table_number' => '70',
            'status' => 'available', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
    }
}