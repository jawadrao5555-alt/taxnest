<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\FbrCustomerLedger;
use App\Models\FbrKhataSettlement;
use App\Models\PosCustomer;
use App\Models\User;
use App\Services\FbrKhataService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Stage-1 FBR Khata invariants that are independent of the sale-screen payload:
 * FIFO allocations, idempotent wasooli retries, company isolation and return
 * adjustments all go through the one central service.
 */
class FbrKhataServiceTest extends TestCase
{
    private Company $company;
    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropAllTables();
        Schema::create('companies', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->timestamps();
        });
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password');
            $table->unsignedBigInteger('company_id')->nullable();
            $table->string('role')->nullable();
            $table->string('pos_role')->nullable();
            $table->timestamps();
        });
        Schema::create('pos_customers', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->string('name');
            $table->decimal('khata_balance', 12, 2)->default(0);
            $table->decimal('khata_limit', 12, 2)->nullable();
            $table->timestamps();
        });
        Schema::create('fbr_customer_ledgers', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('customer_id');
            $table->string('entry_type', 20);
            $table->decimal('amount', 12, 2);
            $table->decimal('balance_after', 12, 2);
            $table->unsignedBigInteger('transaction_id')->nullable();
            $table->string('request_uuid', 64)->nullable();
            $table->string('note', 500)->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
            $table->unique(['company_id', 'request_uuid']);
        });
        Schema::create('fbr_khata_settlements', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('customer_id');
            $table->unsignedBigInteger('settlement_ledger_id');
            $table->unsignedBigInteger('credit_ledger_id');
            $table->decimal('amount', 12, 2);
            $table->timestamps();
            $table->unique(['settlement_ledger_id', 'credit_ledger_id']);
        });

        $this->company = Company::create(['name' => 'FIFO FBR Shop']);
        $this->manager = User::create([
            'name' => 'Manager',
            'email' => 'manager@example.test',
            'password' => 'x',
            'company_id' => $this->company->id,
            'pos_role' => 'pos_manager',
        ]);
        PosCustomer::flushKhataColumnCache();
    }

    public function test_partial_wasooli_allocates_oldest_credit_lots_first(): void
    {
        $customer = $this->customer(800);
        $old = $this->credit($customer, 500, 500, now()->subDays(60));
        $new = $this->credit($customer, 300, 800, now()->subDays(4));

        $result = app(FbrKhataService::class)->recordWasooli(
            $this->company->id,
            $customer->id,
            600,
            'part payment',
            $this->manager,
            'f5f17040-f08d-4e9a-9498-045d497f06a1',
        );

        $this->assertFalse($result['replayed']);
        $this->assertSame(200.0, (float) $customer->fresh()->khata_balance);
        $allocations = DB::table('fbr_khata_settlements')
            ->where('settlement_ledger_id', $result['entry']->id)
            ->orderBy('credit_ledger_id')
            ->get();
        $this->assertCount(2, $allocations);
        $this->assertSame($old->id, (int) $allocations[0]->credit_ledger_id);
        $this->assertSame(500.0, (float) $allocations[0]->amount);
        $this->assertSame($new->id, (int) $allocations[1]->credit_ledger_id);
        $this->assertSame(100.0, (float) $allocations[1]->amount);
    }

    public function test_wasooli_retry_with_same_request_key_does_not_double_reduce_balance(): void
    {
        $customer = $this->customer(500);
        $this->credit($customer, 500, 500, now()->subDay());
        $key = 'a9d48d5b-6758-4606-a4d5-68e1d0181bd0';

        $first = app(FbrKhataService::class)->recordWasooli(
            $this->company->id, $customer->id, 200, null, $this->manager, $key
        );
        $retry = app(FbrKhataService::class)->recordWasooli(
            $this->company->id, $customer->id, 200, null, $this->manager, $key
        );

        $this->assertFalse($first['replayed']);
        $this->assertTrue($retry['replayed']);
        $this->assertSame($first['entry']->id, $retry['entry']->id);
        $this->assertSame(300.0, (float) $customer->fresh()->khata_balance);
        $this->assertSame(1, FbrCustomerLedger::where('customer_id', $customer->id)
            ->where('entry_type', 'wasooli')->count());
    }

    public function test_wasooli_request_key_cannot_be_reused_for_a_different_payment(): void
    {
        $customer = $this->customer(500);
        $otherCustomer = $this->customer(500);
        $this->credit($customer, 500, 500, now()->subDay());
        $this->credit($otherCustomer, 500, 500, now()->subDay());
        $key = 'af3d2c31-c7ad-453a-b7ce-d5c96a9f8d5b';

        app(FbrKhataService::class)->recordWasooli(
            $this->company->id, $customer->id, 200, null, $this->manager, $key
        );

        try {
            app(FbrKhataService::class)->recordWasooli(
                $this->company->id, $otherCustomer->id, 200, null, $this->manager, $key
            );
            $this->fail('A request UUID must not replay a payment for another customer.');
        } catch (\Illuminate\Validation\ValidationException $e) {
            $this->assertArrayHasKey('request_uuid', $e->errors());
        }

        $this->assertSame(300.0, (float) $customer->fresh()->khata_balance);
        $this->assertSame(500.0, (float) $otherCustomer->fresh()->khata_balance);
        $this->assertSame(1, FbrCustomerLedger::where('request_uuid', $key)->count());
    }

    public function test_return_adjustment_cannot_cross_company_or_overdraw_the_khata(): void
    {
        $customer = $this->customer(250);
        $this->credit($customer, 250, 250, now()->subDay());

        app(FbrKhataService::class)->recordReturnAdjustment(
            $this->company->id, $customer->id, 100, 77, 'FBRRET-77', $this->manager
        );
        $this->assertSame(150.0, (float) $customer->fresh()->khata_balance);

        $otherCompany = Company::create(['name' => 'Other FBR Shop']);
        $this->expectException(\Illuminate\Database\Eloquent\ModelNotFoundException::class);
        app(FbrKhataService::class)->recordReturnAdjustment(
            $otherCompany->id, $customer->id, 10, 78, 'FBRRET-78', $this->manager
        );
    }

    public function test_return_adjustment_rolls_back_if_fifo_ledger_cannot_cover_it(): void
    {
        // This intentionally represents corrupt legacy cache data: no immutable
        // credit lot exists for the cached balance. The service must leave no
        // partial return ledger row behind when FIFO rejects the adjustment.
        $customer = $this->customer(100);

        try {
            app(FbrKhataService::class)->recordReturnAdjustment(
                $this->company->id, $customer->id, 50, 79, 'FBRRET-79', $this->manager
            );
            $this->fail('An uncovered adjustment must fail.');
        } catch (\LogicException $e) {
            $this->assertSame('Khata ledger balance does not cover this settlement.', $e->getMessage());
        }

        $this->assertSame(100.0, (float) $customer->fresh()->khata_balance);
        $this->assertSame(0, FbrCustomerLedger::where('entry_type', 'return_adjust')->count());
    }

    public function test_credit_and_return_reject_non_positive_amounts_without_mutating_khata(): void
    {
        $customer = $this->customer(100);
        $this->credit($customer, 100, 100, now()->subDay());

        foreach ([-10.0, 0.0] as $amount) {
            try {
                app(FbrKhataService::class)->recordCreditSale(
                    $this->company->id, $customer->id, $amount, 90, 'FPOS-TEST', $this->manager
                );
                $this->fail('Non-positive credit must fail.');
            } catch (\Illuminate\Validation\ValidationException $e) {
                $this->assertArrayHasKey('amount', $e->errors());
            }

            try {
                app(FbrKhataService::class)->recordReturnAdjustment(
                    $this->company->id, $customer->id, $amount, 91, 'FBRRET-91', $this->manager
                );
                $this->fail('Non-positive return must fail.');
            } catch (\Illuminate\Validation\ValidationException $e) {
                $this->assertArrayHasKey('amount', $e->errors());
            }
        }

        $this->assertSame(100.0, (float) $customer->fresh()->khata_balance);
        $this->assertSame(1, FbrCustomerLedger::count());
        $this->assertSame(0, FbrKhataSettlement::count());
    }

    private function customer(float $balance): PosCustomer
    {
        return PosCustomer::create([
            'company_id' => $this->company->id,
            'name' => 'Customer ' . uniqid(),
            'khata_balance' => $balance,
        ]);
    }

    private function credit(PosCustomer $customer, float $amount, float $balanceAfter, $createdAt): FbrCustomerLedger
    {
        return FbrCustomerLedger::create([
            'company_id' => $this->company->id,
            'customer_id' => $customer->id,
            'entry_type' => 'udhaar',
            'amount' => $amount,
            'balance_after' => $balanceAfter,
            'note' => 'Credit sale',
            'created_by' => $this->manager->id,
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ]);
    }
}