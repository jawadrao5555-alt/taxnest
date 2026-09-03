<?php

namespace Tests\Feature;

use App\Models\AgentCoreEvent;
use App\Models\Company;
use App\Services\AgentCoreLedgerRefundProjector;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class AgentCoreLedgerRefundProjectorTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Schema::dropAllTables();

        Schema::create('companies', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->boolean('inventory_enabled')->default(false);
            $table->boolean('fbr_reporting_enabled')->default(false);
            $table->boolean('agent_enabled')->default(false);
            $table->softDeletes();
            $table->timestamps();
        });
        Schema::create('branches', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->string('name');
            $table->boolean('is_active')->default(true);
            $table->boolean('is_head_office')->default(false);
            $table->timestamps();
        });
        Schema::create('branch_user', function (Blueprint $table): void {
            $table->unsignedBigInteger('branch_id');
            $table->unsignedBigInteger('user_id');
        });
        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('default_branch_id')->nullable();
            $table->string('name');
            $table->string('role')->nullable();
            $table->string('pos_role')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
        Schema::create('pos_customers', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->string('name');
            $table->string('phone')->nullable();
            $table->string('email')->nullable();
            $table->text('address')->nullable();
            $table->string('city')->nullable();
            $table->string('ntn')->nullable();
            $table->string('cnic')->nullable();
            $table->decimal('khata_balance', 12, 2)->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
        Schema::create('fbr_customer_ledgers', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('customer_id');
            $table->string('entry_type');
            $table->decimal('amount', 12, 2);
            $table->decimal('balance_after', 12, 2);
            $table->unsignedBigInteger('transaction_id')->nullable();
            $table->string('request_uuid')->nullable();
            $table->text('note')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
            $table->unique(['company_id', 'request_uuid']);
        });
        Schema::create('agent_core_aggregate_mappings', function (Blueprint $table): void {
            $table->id(); $table->unsignedBigInteger('company_id'); $table->unsignedBigInteger('branch_id');
            $table->string('local_type'); $table->string('local_aggregate_id'); $table->string('cloud_type');
            $table->unsignedBigInteger('cloud_id'); $table->json('metadata')->nullable(); $table->timestamps();
            $table->unique(['company_id', 'branch_id', 'local_type', 'local_aggregate_id']);
        });
        Schema::create('fbr_pos_transactions', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('branch_id')->nullable();
            $table->unsignedBigInteger('terminal_id')->nullable();
            $table->string('invoice_number')->unique();
            $table->string('invoice_mode')->nullable();
            $table->string('transaction_type')->default('sale');
            $table->unsignedBigInteger('parent_transaction_id')->nullable();
            $table->unsignedBigInteger('customer_id')->nullable();
            $table->string('customer_name')->nullable();
            $table->string('customer_phone')->nullable();
            $table->string('customer_ntn')->nullable();
            $table->decimal('subtotal', 12, 2)->default(0);
            $table->decimal('discount_amount', 12, 2)->default(0);
            $table->decimal('tax_amount', 12, 2)->default(0);
            $table->decimal('total_amount', 12, 2)->default(0);
            $table->string('payment_method');
            $table->json('payment_breakdown')->nullable();
            $table->string('status');
            $table->string('fbr_status')->nullable();
            $table->string('fbr_invoice_number')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
        });
        Schema::create('fbr_pos_transaction_items', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('transaction_id');
            $table->unsignedBigInteger('parent_item_id')->nullable();
            $table->unsignedBigInteger('product_id')->nullable();
            $table->string('item_name');
            $table->string('hs_code')->nullable();
            $table->string('uom')->nullable();
            $table->decimal('quantity', 12, 3);
            $table->decimal('returned_quantity', 12, 3)->default(0);
            $table->decimal('unit_price', 12, 2);
            $table->decimal('cost_price', 12, 4)->nullable();
            $table->decimal('item_discount', 12, 2)->default(0);
            $table->decimal('tax_rate', 8, 2)->default(0);
            $table->decimal('tax_amount', 12, 2)->default(0);
            $table->decimal('subtotal', 12, 2);
            $table->decimal('total', 12, 2);
            $table->boolean('is_tax_exempt')->default(false);
            $table->timestamps();
        });
        Schema::create('inventory_stocks', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('branch_id')->nullable();
            $table->decimal('quantity', 12, 3)->default(0);
            $table->decimal('min_stock_level', 12, 3)->default(0);
            $table->decimal('avg_purchase_price', 12, 2)->default(0);
            $table->decimal('last_purchase_price', 12, 2)->default(0);
            $table->timestamps();
            $table->unique(['company_id', 'product_id', 'branch_id']);
        });
        Schema::create('inventory_movements', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('branch_id')->nullable();
            $table->string('type');
            $table->decimal('quantity', 12, 3);
            $table->decimal('unit_price', 12, 2)->default(0);
            $table->decimal('total_price', 12, 2)->default(0);
            $table->decimal('balance_after', 12, 3)->default(0);
            $table->string('reference_type')->nullable();
            $table->unsignedBigInteger('reference_id')->nullable();
            $table->string('reference_number')->nullable();
            $table->text('notes')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
        });

        DB::table('companies')->insert(['id' => 1, 'name' => 'Shop', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('branches')->insert(['id' => 10, 'company_id' => 1, 'name' => 'Main', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('users')->insert([
            'id' => 20, 'company_id' => 1, 'default_branch_id' => 10, 'name' => 'Manager',
            'role' => 'user', 'pos_role' => 'pos_manager', 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('branch_user')->insert(['branch_id' => 10, 'user_id' => 20]);
        DB::table('pos_customers')->insert([
            'id' => 30, 'company_id' => 1, 'name' => 'Customer', 'khata_balance' => 100,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('fbr_customer_ledgers')->insert([
            'company_id' => 1, 'customer_id' => 30, 'entry_type' => 'udhaar',
            'amount' => 100, 'balance_after' => 100, 'transaction_id' => 99,
            'created_by' => 20, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('agent_core_aggregate_mappings')->insert([
            'company_id' => 1, 'branch_id' => 10, 'local_type' => 'customer',
            'local_aggregate_id' => '30', 'cloud_type' => 'pos_customer', 'cloud_id' => 30,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_canonical_nested_wasooli_is_projected_once_and_never_exceeds_outstanding(): void
    {
        $projector = app(AgentCoreLedgerRefundProjector::class);
        $event = $this->event('wasooli-one');
        $wire = $this->wire('wasooli.recorded', [
            'customer' => ['id' => 30],
            'wasooli' => ['amount' => 40, 'note' => 'Counter payment'],
        ]);

        $first = $projector->project(Company::findOrFail(1), $event, $wire);
        $second = $projector->project(Company::findOrFail(1), $event, $wire);

        $this->assertSame('projected', $first->status);
        $this->assertFalse($first->result['replayed']);
        $this->assertSame('projected', $second->status);
        $this->assertTrue($second->result['replayed']);
        $this->assertSame(60.0, (float) DB::table('pos_customers')->where('id', 30)->value('khata_balance'));
        $this->assertSame(1, DB::table('fbr_customer_ledgers')->where('entry_type', 'wasooli')->count());

        $over = $projector->project(Company::findOrFail(1), $this->event('wasooli-over'), $this->wire(
            'wasooli.recorded',
            ['customer' => ['id' => 30], 'wasooli' => ['amount' => 61]]
        ));
        $this->assertSame('rejected', $over->status);
        $this->assertSame(60.0, (float) DB::table('pos_customers')->where('id', 30)->value('khata_balance'));
    }

    public function test_scope_failure_is_a_deterministic_rejection_without_money_mutation(): void
    {
        $wire = $this->wire('wasooli.recorded', [
            'customer' => ['id' => 30],
            'wasooli' => ['amount' => 10],
        ]);
        $wire['scope']['branch_id'] = '999';

        $outcome = app(AgentCoreLedgerRefundProjector::class)
            ->project(Company::findOrFail(1), $this->event('wrong-branch'), $wire);

        $this->assertSame('rejected', $outcome->status);
        $this->assertSame('Branch scope mismatch.', $outcome->error);
        $this->assertSame(100.0, (float) DB::table('pos_customers')->where('id', 30)->value('khata_balance'));
    }

    public function test_node_customer_fixture_uses_opaque_aggregate_and_missing_sale_is_retryable(): void
    {
        $projector = app(AgentCoreLedgerRefundProjector::class);
        $upsert = $projector->project(Company::findOrFail(1), $this->event('node-customer'), $this->wire(
            'customer.upsert',
            ['name' => 'Node Customer', 'phone' => '03001234567'],
            'customer-7f9a'
        ));
        $this->assertSame('projected', $upsert->status);
        $this->assertNotSame(0, $upsert->result['customer_id']);
        $this->assertDatabaseHas('agent_core_aggregate_mappings', [
            'local_type' => 'customer', 'local_aggregate_id' => 'customer-7f9a',
            'cloud_type' => 'pos_customer', 'cloud_id' => $upsert->result['customer_id'],
        ]);

        $waiting = $projector->project(Company::findOrFail(1), $this->event('node-khata'), $this->wire(
            'khata.debit',
            ['amount_cents' => 500, 'reference' => 'sale-not-uploaded'],
            'customer-7f9a'
        ));
        $this->assertSame('retryable', $waiting->status);
        $this->assertSame('sale:sale-not-uploaded', $waiting->dependency);
        $this->assertSame(0.0, (float) DB::table('pos_customers')
            ->where('id', $upsert->result['customer_id'])->value('khata_balance'));
    }

    public function test_nested_khata_refund_and_stock_commands_preserve_money_quantity_and_symmetry(): void
    {
        DB::table('companies')->where('id', 1)->update(['inventory_enabled' => true]);
        DB::table('fbr_pos_transactions')->insert([
            'id' => 100, 'company_id' => 1, 'branch_id' => 10, 'invoice_number' => 'INV-100',
            'invoice_mode' => 'fbr', 'transaction_type' => 'sale', 'customer_id' => 30,
            'customer_name' => 'Customer', 'subtotal' => 100, 'discount_amount' => 10,
            'tax_amount' => 0, 'total_amount' => 90, 'payment_method' => 'credit',
            'status' => 'completed', 'created_by' => 20, 'created_at' => now(), 'updated_at' => now(),
        ]);
        foreach ([[501, 7], [502, 8]] as [$id, $product]) {
            DB::table('fbr_pos_transaction_items')->insert([
                'id' => $id, 'transaction_id' => 100, 'product_id' => $product,
                'item_name' => "Product {$product}", 'quantity' => 1, 'unit_price' => 50,
                'subtotal' => 50, 'total' => 50, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        DB::table('agent_core_aggregate_mappings')->insert([
            ['company_id' => 1, 'branch_id' => 10, 'local_type' => 'sale', 'local_aggregate_id' => '100',
                'cloud_type' => 'fbr_pos_transaction', 'cloud_id' => 100, 'created_at' => now(), 'updated_at' => now()],
            ['company_id' => 1, 'branch_id' => 10, 'local_type' => 'sale_line', 'local_aggregate_id' => '501',
                'cloud_type' => 'fbr_pos_transaction_item', 'cloud_id' => 501, 'created_at' => now(), 'updated_at' => now()],
            ['company_id' => 1, 'branch_id' => 10, 'local_type' => 'sale_line', 'local_aggregate_id' => '502',
                'cloud_type' => 'fbr_pos_transaction_item', 'cloud_id' => 502, 'created_at' => now(), 'updated_at' => now()],
            ['company_id' => 1, 'branch_id' => 10, 'local_type' => 'customer', 'local_aggregate_id' => 'customer-30',
                'cloud_type' => 'pos_customer', 'cloud_id' => 30, 'created_at' => now(), 'updated_at' => now()],
            ['company_id' => 1, 'branch_id' => 10, 'local_type' => 'order', 'local_aggregate_id' => 'order-100',
                'cloud_type' => 'fbr_pos_transaction', 'cloud_id' => 100, 'created_at' => now(), 'updated_at' => now()],
            ['company_id' => 1, 'branch_id' => 10, 'local_type' => 'sale_line', 'local_aggregate_id' => 'line-501',
                'cloud_type' => 'fbr_pos_transaction_item', 'cloud_id' => 501, 'created_at' => now(), 'updated_at' => now()],
            ['company_id' => 1, 'branch_id' => 10, 'local_type' => 'sale_line', 'local_aggregate_id' => 'line-502',
                'cloud_type' => 'fbr_pos_transaction_item', 'cloud_id' => 502, 'created_at' => now(), 'updated_at' => now()],
        ]);
        DB::table('inventory_stocks')->insert([
            'company_id' => 1, 'product_id' => 7, 'branch_id' => 10, 'quantity' => 3,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('inventory_movements')->insert([
            'company_id' => 1, 'product_id' => 7, 'branch_id' => 10, 'type' => 'sale',
            'quantity' => 1, 'unit_price' => 50, 'total_price' => 50, 'balance_after' => 3,
            'reference_type' => 'fbr_pos_transaction', 'reference_id' => 100,
            'created_by' => 20, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $projector = app(AgentCoreLedgerRefundProjector::class);
        $debit = $projector->project(Company::findOrFail(1), $this->event('debit-100'), $this->wire(
            'customer.khata.debited',
            ['customer' => ['id' => 30], 'khata' => ['amount' => 90], 'sale' => ['id' => 100]]
        ));
        $this->assertSame('projected', $debit->status);
        $this->assertSame(190.0, (float) DB::table('pos_customers')->where('id', 30)->value('khata_balance'));

        $refundEvent = $this->event('refund-100');
        $refund = $projector->project(Company::findOrFail(1), $refundEvent, $this->wire(
            'refund.record',
            ['order_id' => 'order-100', 'customer_id' => 'customer-30',
                'amount_cents' => 9000, 'method' => 'khata', 'line_ids' => ['line-501', 'line-502']],
            'refund-f6e2'
        ));
        $this->assertSame('projected', $refund->status);
        $this->assertSame(90.0, $refund->result['refund_total']);
        $this->assertSame(10.0, (float) DB::table('fbr_pos_transactions')
            ->where('id', $refund->result['return_transaction_id'])->value('discount_amount'));
        $this->assertSame(100.0, (float) DB::table('pos_customers')->where('id', 30)->value('khata_balance'));

        $restoreEvent = $this->event('restore-100');
        $restoreWire = $this->wire('stock.restored', ['stock' => [
            'return_transaction_id' => $refund->result['return_transaction_id'],
        ]]);
        $restore = $projector->project(Company::findOrFail(1), $restoreEvent, $restoreWire);
        $replay = $projector->project(Company::findOrFail(1), $restoreEvent, $restoreWire);

        $this->assertSame('projected', $restore->status);
        $this->assertSame(1.0, $restore->result['restored_quantity']);
        $this->assertTrue($replay->result['replayed']);
        $this->assertSame(4.0, (float) DB::table('inventory_stocks')->where('product_id', 7)->value('quantity'));
        $this->assertFalse(DB::table('inventory_stocks')->where('product_id', 8)->exists());
        $this->assertSame(1.0, (float) DB::table('fbr_pos_transaction_items')->where('id', 501)->value('returned_quantity'));
    }

    private function event(string $eventId): AgentCoreEvent
    {
        $event = new AgentCoreEvent();
        $event->id = abs(crc32($eventId)) + 1;
        $event->event_id = $eventId;
        return $event;
    }

    private function wire(string $command, array $data, string $aggregate = ''): array
    {
        return [
            'scope' => ['company_id' => '1', 'branch_id' => '10', 'user_id' => '20'],
            'payload' => ['command_type' => $command, 'aggregate_id' => $aggregate, 'aggregate_revision' => 1, 'data' => $data],
        ];
    }
}