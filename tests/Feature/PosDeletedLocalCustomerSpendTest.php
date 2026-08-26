<?php

namespace Tests\Feature;

use App\Http\Controllers\PosController;
use App\Models\PosCustomer;
use App\Services\PosCustomerSpend;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * A deleted local bill can retain only its value in lifetime spend. It must
 * never be reconstructed as a customer-history bill or last-order source.
 */
class PosDeletedLocalCustomerSpendTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Schema::dropAllTables();

        Schema::create('companies', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->boolean('pos_customer_spend_persist')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });
        Schema::create('pos_customers', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->string('name');
            $table->string('phone')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
        Schema::create('pos_transactions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('customer_id')->nullable();
            $table->string('customer_phone')->nullable();
            $table->string('invoice_number')->nullable();
            $table->string('invoice_mode')->nullable();
            $table->string('pra_status')->nullable();
            $table->string('status')->default('completed');
            $table->string('transaction_type')->nullable();
            $table->string('payment_method')->nullable();
            $table->decimal('subtotal', 12, 2)->default(0);
            $table->decimal('discount_amount', 12, 2)->default(0);
            $table->decimal('tax_amount', 12, 2)->default(0);
            $table->decimal('total_amount', 12, 2)->default(0);
            $table->boolean('is_archived')->default(false);
            $table->timestamps();
        });
        Schema::create('pos_customer_spend_snapshots', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('customer_id')->nullable();
            $table->string('customer_phone')->nullable();
            $table->decimal('total_amount', 12, 2)->default(0);
            $table->timestamps();
        });
    }

    public function test_deleted_local_snapshot_only_increases_lifetime_spend_not_history_or_last_order(): void
    {
        $companyId = (int) DB::table('companies')->insertGetId([
            'name' => 'Spend Test', 'pos_customer_spend_persist' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $customerId = (int) DB::table('pos_customers')->insertGetId([
            'company_id' => $companyId, 'name' => 'Bilal', 'phone' => '03001234567',
            'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $liveAt = now()->subDays(3);
        DB::table('pos_transactions')->insert([
            'company_id' => $companyId, 'customer_id' => $customerId,
            'customer_phone' => '03001234567', 'invoice_number' => 'P001',
            'status' => 'completed', 'total_amount' => 100,
            'created_at' => $liveAt, 'updated_at' => $liveAt,
        ]);
        DB::table('pos_customer_spend_snapshots')->insert([
            'company_id' => $companyId, 'customer_id' => $customerId,
            'customer_phone' => '03001234567', 'total_amount' => 500,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $customer = PosCustomer::findOrFail($customerId);
        $method = new \ReflectionMethod(PosController::class, 'customerHistoryTransactions');
        $method->setAccessible(true);
        $history = $method->invoke(new PosController(), $companyId, $customer);

        $this->assertCount(1, $history);
        $this->assertSame('P001', $history->first()->invoice_number);
        $this->assertSame(500.0, PosCustomerSpend::deletedLocalTotal($companyId, $customer));

        app()->instance('currentCompanyId', $companyId);
        $view = (new PosController())->customerHistory($customerId);
        $data = $view->getData();
        $this->assertSame(600.0, (float) $data['totalSpent']);
        $this->assertSame(1, $data['totalOrders']);
        $this->assertSame(100.0, (float) $data['avgOrder']);
        $this->assertSame('P001', $data['lastOrder']->invoice_number);
    }
}