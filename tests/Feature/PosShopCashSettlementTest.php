<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\PosRiderSettlement;
use App\Models\User;
use App\Services\PosShopCashSettlement;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class PosShopCashSettlementTest extends TestCase
{
    private User $actor;
    private int $companyId;

    protected function setUp(): void
    {
        parent::setUp();
        Schema::dropAllTables();
        Schema::create('companies', function (Blueprint $t) { $t->id(); $t->string('name'); $t->softDeletes(); $t->timestamps(); });
        Schema::create('users', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('company_id'); $t->string('name'); $t->string('role');
            $t->string('pos_role'); $t->string('pos_billing_scope')->nullable(); $t->timestamps();
        });
        Schema::create('pos_rider_settlements', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('company_id'); $t->unsignedBigInteger('rider_id')->nullable();
            $t->unsignedBigInteger('settled_by'); $t->decimal('total_amount', 12, 2); $t->integer('bill_count');
            $t->string('notes')->nullable(); $t->string('receipt_kind'); $t->uuid('receipt_key');
            $t->string('panel'); $t->date('business_date'); $t->unsignedBigInteger('branch_id')->nullable();
            $t->json('allocation'); $t->timestamps(); $t->unique(['company_id', 'receipt_key']);
        });
        Schema::create('pos_transactions', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('company_id'); $t->string('invoice_number'); $t->string('status');
            $t->string('transaction_type')->nullable(); $t->string('invoice_mode')->nullable();
            $t->string('pra_status')->nullable(); $t->string('pra_invoice_number')->nullable();
            $t->string('order_type'); $t->string('payment_method'); $t->decimal('total_amount', 12, 2);
            $t->date('business_date')->nullable(); $t->unsignedBigInteger('branch_id')->nullable();
            $t->unsignedBigInteger('rider_id')->nullable(); $t->unsignedBigInteger('rider_settlement_id')->nullable();
            $t->timestamp('rider_settled_at')->nullable(); $t->string('delivery_status')->nullable();
            $t->timestamp('delivered_at')->nullable(); $t->boolean('is_archived')->default(false); $t->timestamps();
        });
        $this->companyId = Company::create(['name' => 'Synthetic Shop'])->id;
        $this->actor = User::forceCreate(['company_id' => $this->companyId, 'name' => 'QA Cashier', 'role' => 'user', 'pos_role' => 'pos_cashier']);
        auth('pos')->setUser($this->actor);
        app()->instance('currentCompanyId', $this->companyId);
    }

    private function bill(array $attributes = []): int
    {
        return DB::table('pos_transactions')->insertGetId(array_merge([
            'company_id' => $this->companyId, 'invoice_number' => 'QA-' . Str::uuid(), 'status' => 'completed',
            'transaction_type' => 'sale', 'invoice_mode' => 'pra', 'order_type' => 'delivery',
            'payment_method' => 'cash', 'total_amount' => 125.5, 'business_date' => now()->toDateString(),
            'created_at' => now(), 'updated_at' => now(),
        ], $attributes));
    }

    public function test_selected_receipt_retries_once_without_changing_delivery_or_fiscal_identity(): void
    {
        $one = $this->bill(['is_archived' => true]); $two = $this->bill(); $omitted = $this->bill();
        $key = (string) Str::uuid();
        $receipt = PosShopCashSettlement::receive($this->companyId, $this->actor, [$one, $two], 251, $key);
        $again = PosShopCashSettlement::receive($this->companyId, $this->actor, [$two, $one], 251, $key);
        $this->assertSame($receipt->id, $again->id);
        $this->assertSame(1, PosRiderSettlement::count());
        $this->assertNull($receipt->rider_id);
        $this->assertSame('shop', $receipt->receipt_kind);
        $this->assertEquals($this->actor->id, $receipt->settled_by);
        foreach ([$one, $two] as $id) {
            $bill = DB::table('pos_transactions')->find($id);
            $this->assertEquals($receipt->id, $bill->rider_settlement_id);
            $this->assertNull($bill->delivery_status);
            $this->assertNull($bill->delivered_at);
            $this->assertSame('pra', $bill->invoice_mode);
        }
        $this->assertNull(DB::table('pos_transactions')->find($omitted)->rider_settlement_id);
        $this->expectException(\LogicException::class);
        $receipt->update(['total_amount' => 1]);
    }

    public function test_invalid_selection_is_atomic_and_never_silently_drops_bills(): void
    {
        $eligible = $this->bill();
        foreach ([['payment_method' => 'card'], ['status' => 'draft'], ['invoice_mode' => 'local'],
            ['transaction_type' => 'return'], ['company_id' => 999], ['rider_id' => 44],
            ['rider_settlement_id' => 77], ['delivery_status' => 'returned']] as $attributes) {
            $invalid = $this->bill($attributes);
            try {
                PosShopCashSettlement::receive($this->companyId, $this->actor, [$eligible, $invalid], 251, (string) Str::uuid());
                $this->fail('An ineligible selected bill must reject the complete receipt.');
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('bill_ids', $e->errors());
            }
        }
        $this->assertSame(0, PosRiderSettlement::count());
        $this->assertNull(DB::table('pos_transactions')->find($eligible)->rider_settlement_id);
    }

    public function test_changed_amount_and_second_receipt_key_do_not_receive_again(): void
    {
        $bill = $this->bill();
        try {
            PosShopCashSettlement::receive($this->companyId, $this->actor, [$bill], 120, (string) Str::uuid());
            $this->fail('Stale preview must fail.');
        } catch (ValidationException $e) { $this->assertSame(0, PosRiderSettlement::count()); }
        PosShopCashSettlement::receive($this->companyId, $this->actor, [$bill], 125.5, (string) Str::uuid());
        try {
            PosShopCashSettlement::receive($this->companyId, $this->actor, [$bill], 125.5, (string) Str::uuid());
            $this->fail('A second receipt must fail.');
        } catch (ValidationException $e) { $this->assertSame(1, PosRiderSettlement::count()); }
    }
    public function test_read_only_and_foreign_actors_cannot_receive_cash(): void
    {
        $bill = $this->bill();
        foreach (['pos_waiter', 'pos_kitchen', 'archive_viewer', 'local_viewer'] as $role) {
            $this->actor->pos_role = $role;
            try { PosShopCashSettlement::receive($this->companyId, $this->actor, [$bill], 125.5, (string) Str::uuid()); $this->fail('Read-only role received cash.'); }
            catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) { $this->assertSame(403, $e->getStatusCode()); }
        }
        $this->actor->pos_role = 'pos_admin'; $this->actor->company_id = 999;
        try { PosShopCashSettlement::receive($this->companyId, $this->actor, [$bill], 125.5, (string) Str::uuid()); $this->fail('Foreign company received cash.'); }
        catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) { $this->assertSame(403, $e->getStatusCode()); }
        $this->assertSame(0, PosRiderSettlement::count());
    }

    public function test_impersonation_and_revoked_billing_scope_cannot_replay_receipt(): void
    {
        $bill = $this->bill(); $key = (string) Str::uuid();
        PosShopCashSettlement::receive($this->companyId, $this->actor, [$bill], 125.5, $key);
        session(['impersonation' => ['company_id' => $this->companyId]]);
        try { PosShopCashSettlement::receive($this->companyId, $this->actor, [$bill], 125.5, $key); $this->fail('Impersonation replayed receipt.'); }
        catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) { $this->assertSame(403, $e->getStatusCode()); }
        session()->forget('impersonation');
        $this->actor->pos_billing_scope = 'pra';
        try { PosShopCashSettlement::receive($this->companyId, $this->actor, [$bill], 125.5, $key); $this->fail('Revoked stream replayed receipt.'); }
        catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) { $this->assertSame(403, $e->getStatusCode()); }
        $this->assertSame(1, PosRiderSettlement::count());
    }

}
