<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\PosServiceWorkOrder;
use App\Models\PosTransaction;
use App\Models\User;
use App\Services\PosServiceWorkOrderInvoiceService;
use App\Services\PosServiceWorkOrderService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class PosServiceWorkOrderInvoiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->schema();
    }

    public function test_terminal_work_order_creates_one_fiscal_sale_without_reusing_job_number(): void
    {
        $company = $this->company('salon', 'Salon A');
        $user = User::create(['name' => 'Cashier', 'email' => 'a@example.test', 'password' => bcrypt('x'), 'company_id' => $company->id]);
        $jobs = app(PosServiceWorkOrderService::class);
        $order = $jobs->create($company, [
            'customer_name' => 'Guest', 'quantity' => 1, 'unit_price' => 1000,
            'scheduled_at' => '2030-01-01 10:00:00', 'title' => 'Haircut',
        ], null, $user->id);
        $jobs->transition($company, $order->id, 'checked_in', null, $user->id);
        $jobs->transition($company, $order->id, 'in_service', null, $user->id);
        $jobs->transition($company, $order->id, 'completed', null, $user->id);

        $txn = app(PosServiceWorkOrderInvoiceService::class)->issue($company, $order->id, 'cash', $user->id);
        $order->refresh();

        $this->assertNotNull($order->pos_transaction_id);
        $this->assertSame($txn->id, $order->pos_transaction_id);
        $this->assertMatchesRegularExpression('/^SAL-\d{6}$/', $order->job_number);
        $this->assertTrue(str_starts_with($txn->invoice_number, 'L'));
        $this->assertNotSame($order->job_number, $txn->invoice_number);
        $this->assertStringNotContainsString('SAL-', $txn->invoice_number);
        $this->assertSame(1, PosTransaction::where('company_id', $company->id)->count());
        $this->assertDatabaseHas('pos_transaction_items', [
            'transaction_id' => $txn->id,
            'item_name' => 'Haircut ('.$order->job_number.')',
        ]);
    }

    public function test_invoice_is_idempotent_and_refuses_non_terminal_or_cancelled(): void
    {
        $company = $this->company('laundry', 'Laundry A');
        $user = User::create(['name' => 'Cashier', 'email' => 'b@example.test', 'password' => bcrypt('x'), 'company_id' => $company->id]);
        $jobs = app(PosServiceWorkOrderService::class);
        $order = $jobs->create($company, [
            'customer_name' => 'Guest', 'quantity' => 2, 'unit_price' => 250,
            'scheduled_at' => '2030-01-01 10:00:00', 'title' => 'Wash',
        ], null, $user->id);

        $billing = app(PosServiceWorkOrderInvoiceService::class);
        try {
            $billing->issue($company, $order->id, 'cash', $user->id);
            $this->fail('Expected non-terminal refusal');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('terminal', strtolower($e->getMessage()));
        }

        foreach (['tagged', 'cleaning', 'ready', 'delivered'] as $to) {
            $jobs->transition($company, $order->id, $to, null, $user->id);
        }
        $first = $billing->issue($company, $order->id, 'cash', $user->id);
        $second = $billing->issue($company, $order->id, 'card', $user->id);
        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, PosTransaction::where('company_id', $company->id)->count());

        $cancelled = $jobs->create($company, [
            'customer_name' => 'Other', 'quantity' => 1, 'unit_price' => 100,
            'scheduled_at' => '2030-01-02 10:00:00', 'title' => 'Dry',
        ], null, $user->id);
        $jobs->transition($company, $cancelled->id, 'cancelled', null, $user->id);
        $this->expectException(\InvalidArgumentException::class);
        $billing->issue($company, $cancelled->id, 'cash', $user->id);
    }

    public function test_tenant_isolation_blocks_cross_company_billing(): void
    {
        $a = $this->company('salon', 'Salon A');
        $b = $this->company('salon', 'Salon B');
        $ua = User::create(['name' => 'A', 'email' => 'c@example.test', 'password' => bcrypt('x'), 'company_id' => $a->id]);
        $ub = User::create(['name' => 'B', 'email' => 'd@example.test', 'password' => bcrypt('x'), 'company_id' => $b->id]);
        $jobs = app(PosServiceWorkOrderService::class);
        $order = $jobs->create($a, [
            'customer_name' => 'Guest', 'quantity' => 1, 'unit_price' => 500,
            'scheduled_at' => '2030-01-01 10:00:00', 'title' => 'Cut',
        ], null, $ua->id);
        foreach (['checked_in', 'in_service', 'completed'] as $to) {
            $jobs->transition($a, $order->id, $to, null, $ua->id);
        }

        $this->expectException(\Illuminate\Database\Eloquent\ModelNotFoundException::class);
        app(PosServiceWorkOrderInvoiceService::class)->issue($b, $order->id, 'cash', $ub->id);
    }

    private function company(string $category, string $name): Company
    {
        $id = DB::table('companies')->insertGetId([
            'name' => $name, 'product_type' => 'pos', 'business_category' => $category,
            'pos_type' => $category, 'created_at' => now(), 'updated_at' => now(),
        ]);

        return Company::findOrFail($id);
    }

    private function schema(): void
    {
        Schema::create('companies', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('product_type')->nullable();
            $table->string('business_category')->nullable();
            $table->string('pos_type')->nullable();
            $table->string('pos_tax_pricing_mode')->nullable();
            $table->boolean('pra_reporting_enabled')->default(false);
            $table->timestamps();
            $table->softDeletes();
        });
        Schema::create('pos_services', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->string('name');
            $table->decimal('price', 15, 2)->default(0);
            $table->decimal('tax_rate', 5, 2)->default(0);
            $table->boolean('is_active')->default(true);
            $table->boolean('is_tax_exempt')->default(false);
            $table->timestamps();
        });
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name')->nullable();
            $table->string('email')->nullable();
            $table->string('password')->nullable();
            $table->unsignedBigInteger('company_id')->nullable();
            $table->boolean('pra_reporting_enabled')->nullable();
            $table->timestamps();
        });
        Schema::create('pos_transactions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('branch_id')->nullable();
            $table->string('invoice_number');
            $table->string('invoice_mode')->nullable();
            $table->string('customer_name')->nullable();
            $table->string('customer_phone')->nullable();
            $table->decimal('subtotal', 15, 2)->default(0);
            $table->string('discount_type')->nullable();
            $table->decimal('discount_value', 15, 2)->default(0);
            $table->decimal('discount_amount', 15, 2)->default(0);
            $table->decimal('tax_rate', 8, 4)->default(0);
            $table->decimal('tax_amount', 15, 2)->default(0);
            $table->decimal('exempt_amount', 15, 2)->default(0);
            $table->decimal('total_amount', 15, 2)->default(0);
            $table->string('payment_method')->nullable();
            $table->decimal('cash_received', 15, 2)->nullable();
            $table->decimal('change_due', 15, 2)->nullable();
            $table->string('status')->nullable();
            $table->string('pra_status')->nullable();
            $table->string('submission_hash')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->text('notes')->nullable();
            $table->boolean('tax_inclusive')->default(false);
            $table->decimal('tax_menu_rate', 8, 4)->nullable();
            $table->string('offline_uuid', 64)->nullable();
            $table->timestamps();
        });
        Schema::create('pos_transaction_items', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('transaction_id');
            $table->string('item_type')->nullable();
            $table->unsignedBigInteger('item_id')->nullable();
            $table->string('item_name');
            $table->decimal('quantity', 12, 3)->default(1);
            $table->decimal('unit_price', 15, 2)->default(0);
            $table->decimal('subtotal', 15, 2)->default(0);
            $table->boolean('is_tax_exempt')->default(false);
            $table->decimal('tax_rate', 8, 4)->default(0);
            $table->decimal('tax_amount', 15, 2)->default(0);
            $table->timestamps();
        });
        Schema::create('pos_local_series_counters', function (Blueprint $table) {
            $table->unsignedBigInteger('company_id')->primary();
            $table->unsignedBigInteger('last_number')->default(0);
            $table->timestamps();
        });
        Schema::create('pos_final_series_counters', function (Blueprint $table) {
            $table->unsignedBigInteger('company_id')->primary();
            $table->unsignedBigInteger('last_number')->default(0);
            $table->timestamps();
        });
        Schema::create('pos_tax_rules', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id')->nullable();
            $table->string('payment_method')->nullable();
            $table->decimal('rate', 8, 4)->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->string('action')->nullable();
            $table->string('entity_type')->nullable();
            $table->unsignedBigInteger('entity_id')->nullable();
            $table->json('old_values')->nullable();
            $table->json('new_values')->nullable();
            $table->unsignedBigInteger('company_id')->nullable();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('ip_address')->nullable();
            $table->string('sha256_hash')->nullable();
            $table->timestamps();
        });

        $wo = require database_path('migrations/2026_09_15_010000_create_pos_service_work_orders.php');
        $wo->up();
        $link = require database_path('migrations/2026_09_15_012000_add_pos_transaction_id_to_service_work_orders.php');
        $link->up();
    }
}
