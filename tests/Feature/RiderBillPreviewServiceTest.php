<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\PosRider;
use App\Services\RiderBillPreviewService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/** Security contract tests for the allowlisted rider-preview boundary. */
class RiderBillPreviewServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Schema::dropAllTables();
        Schema::create('companies', function (Blueprint $t) {
            $t->id(); $t->string('name'); $t->string('ntn')->nullable();
            $t->boolean('is_internal_account')->default(true); $t->json('rider_bill_preview_prefs')->nullable(); $t->softDeletes(); $t->timestamps();
        });
        Schema::create('users', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('company_id')->nullable(); $t->string('name')->nullable();
            $t->boolean('is_active')->default(true); $t->string('pos_role')->nullable(); $t->timestamps();
        });
        Schema::create('pos_riders', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('company_id'); $t->string('name'); $t->boolean('is_active')->default(true); $t->unsignedBigInteger('user_id')->nullable(); $t->string('app_token')->nullable(); $t->timestamps();
        });
        Schema::create('pos_transactions', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('company_id'); $t->unsignedBigInteger('rider_id')->nullable();
            $t->unsignedBigInteger('customer_id')->nullable();
            $t->string('delivery_status')->nullable(); $t->unsignedBigInteger('rider_settlement_id')->nullable();
            $t->string('rider_assignment_revision')->nullable(); $t->string('invoice_number')->nullable();
            $t->string('pra_status')->nullable(); $t->string('pra_invoice_number')->nullable(); $t->decimal('total_amount', 12, 2); $t->decimal('tax_rate', 8, 2)->default(0); $t->decimal('tax_amount', 12, 2)->default(0);
            $t->string('customer_name')->nullable(); $t->string('customer_phone')->nullable(); $t->string('delivery_address')->nullable(); $t->boolean('is_archived')->default(false); $t->timestamps();
        });
        Schema::create('pos_transaction_items', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('transaction_id'); $t->string('item_name'); $t->decimal('quantity', 10, 3); $t->decimal('unit_price', 12, 2); $t->decimal('subtotal', 12, 2); $t->timestamps();
        });
        Schema::create('pos_customers', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('company_id'); $t->string('name');
            $t->string('customer_code')->nullable(); $t->timestamps();
        });
    }

    private function fixtures(): array
    {
        $company = Company::create(['name' => 'Safe Co', 'ntn' => '123']);
        $rider = PosRider::create(['company_id' => $company->id, 'name' => 'A', 'is_active' => true]);
        $customerId = \Illuminate\Support\Facades\DB::table('pos_customers')->insertGetId([
            'company_id' => $company->id, 'name' => 'Customer', 'customer_code' => 'C-17',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $bill = \App\Models\PosTransaction::create(['company_id' => $company->id, 'rider_id' => $rider->id, 'customer_id' => $customerId, 'delivery_status' => 'assigned', 'rider_assignment_revision' => 'rev', 'invoice_number' => 'B-1', 'pra_status' => 'submitted', 'pra_invoice_number' => 'PRA-1', 'total_amount' => 120, 'tax_rate' => 18, 'tax_amount' => 18, 'customer_name' => 'Customer', 'customer_phone' => '0300', 'delivery_address' => 'Street 1']);
        \App\Models\PosTransactionItem::create(['transaction_id' => $bill->id, 'item_name' => 'Pizza', 'quantity' => 2, 'unit_price' => 60, 'subtotal' => 120]);
        return [$company, $rider, $bill];
    }

    public function test_default_is_minimal_and_forbidden_keys_never_escape(): void
    {
        [$company, $rider, $bill] = $this->fixtures();
        $s = app(RiderBillPreviewService::class);
        $found = $s->assigned($rider, $bill->id, 'rev');
        $dto = $s->dto($company, $found);
        $this->assertSame(['available', 'items', 'grand_total'], array_keys($dto));
        $this->assertSame(['name' => 'Pizza'], $dto['items'][0]);
        $this->assertStringNotContainsString('cost', json_encode($dto));
        $this->assertStringNotContainsString('settlement', json_encode($dto));
    }

    public function test_master_off_and_optional_allowlist_and_filed_qr(): void
    {
        [$company, $rider, $bill] = $this->fixtures();
        $s = app(RiderBillPreviewService::class);
        $s->save($company, ['enabled' => false, 'prices' => true]);
        $this->assertSame(['available' => false], $s->dto($company->fresh(), $bill));
        $s->save($company->fresh(), array_fill_keys(['enabled','quantity','prices','tax','ntn','qr','customer_name','customer_phone','customer_address','customer_code','business'], true));
        $dto = $s->dto($company->fresh(), $bill);
        $this->assertSame('PRA-1', $dto['qr']['payload']);
        $this->assertArrayHasKey('unit_rate', $dto['items'][0]);
        $this->assertArrayHasKey('customer', $dto);
        $bill->update(['pra_invoice_number' => null]);
        $this->assertFalse($s->dto($company->fresh(), $bill->fresh())['qr']['available']);
        $bill->update(['pra_invoice_number' => 'STALE', 'pra_status' => 'failed']);
        $qr = $s->dto($company->fresh(), $bill->fresh())['qr'];
        $this->assertFalse($qr['available']);
        $this->assertArrayNotHasKey('payload', $qr);
    }

    public function test_customer_fields_are_independent_allowlisted_and_absent_values_are_omitted(): void
    {
        [$company, , $bill] = $this->fixtures();
        $s = app(RiderBillPreviewService::class);
        $s->save($company, ['enabled' => true, 'customer_phone' => true, 'customer_code' => true, 'unexpected_secret' => true]);
        $dto = $s->dto($company->fresh(), $bill);
        $this->assertSame(['phone' => '0300', 'code' => 'C-17'], $dto['customer']);
        $this->assertFalse($company->fresh()->rider_bill_preview_prefs['customer_name']);
        $this->assertArrayNotHasKey('customer', $company->fresh()->rider_bill_preview_prefs);
        $this->assertArrayNotHasKey('unexpected_secret', $company->fresh()->rider_bill_preview_prefs);

        $bill->update(['customer_phone' => null, 'customer_id' => null]);
        $this->assertArrayNotHasKey('customer', $s->dto($company->fresh(), $bill->fresh()));
    }

    public function test_legacy_broad_customer_preference_migrates_compatibly_in_memory(): void
    {
        [$company, , $bill] = $this->fixtures();
        $company->update(['rider_bill_preview_prefs' => ['v' => 1, 'enabled' => true, 'customer' => true]]);
        $s = app(RiderBillPreviewService::class);
        $prefs = $s->prefs($company->fresh());
        foreach (['customer_name', 'customer_phone', 'customer_address', 'customer_code'] as $flag) {
            $this->assertTrue($prefs[$flag]);
        }
        $this->assertSame([
            'name' => 'Customer', 'phone' => '0300', 'address' => 'Street 1', 'code' => 'C-17',
        ], $s->dto($company->fresh(), $bill)['customer']);
    }

    public function test_assignment_revision_and_terminal_settled_cross_rider_and_company_are_refused(): void
    {
        [$company, $rider, $bill] = $this->fixtures(); $s = app(RiderBillPreviewService::class);
        $this->assertNull($s->assigned($rider, $bill->id, 'wrong'));
        $bill->update(['delivery_status' => 'delivered']); $this->assertNull($s->assigned($rider, $bill->id, 'rev'));
        $bill->update(['delivery_status' => 'assigned', 'rider_settlement_id' => 9]); $this->assertNull($s->assigned($rider, $bill->id, 'rev'));
        $other = PosRider::create(['company_id' => $company->id, 'name' => 'B', 'is_active' => true]);
        $this->assertNull($s->assigned($other, $bill->id, 'rev'));
        $otherCompany = Company::create(['name' => 'Other Co']);
        $foreign = PosRider::create(['company_id' => $otherCompany->id, 'name' => 'C', 'is_active' => true]);
        $this->assertNull($s->assigned($foreign, $bill->id, 'rev'));
    }

    public function test_bearer_preview_endpoint_has_exact_default_contract_and_private_headers(): void
    {
        [$company, $rider, $bill] = $this->fixtures();
        $plain = $rider->id . '|test-token';
        $rider->update(['app_token' => hash('sha256', $plain)]);
        $response = $this->withHeader('Authorization', 'Bearer ' . $plain)
            ->getJson('/api/rider-app/v1/deliveries/' . $bill->id . '/preview?revision=rev&source=fbr');
        $response->assertOk()
            ->assertExactJson(['ok' => true, 'preview' => [
                'available' => true, 'items' => [['name' => 'Pizza']], 'grand_total' => 120,
            ]])
            ->assertHeader('Pragma', 'no-cache');
        $cache = (string) $response->headers->get('Cache-Control');
        $this->assertStringContainsString('private', $cache);
        $this->assertStringContainsString('no-store', $cache);
        $this->assertStringContainsString('no-cache', $cache);
    }

    public function test_bearer_preview_refuses_missing_wrong_and_bad_token(): void
    {
        [, $rider, $bill] = $this->fixtures();
        $plain = $rider->id . '|test-token'; $rider->update(['app_token' => hash('sha256', $plain)]);
        $this->withHeader('Authorization', 'Bearer ' . $plain)->getJson('/api/rider-app/v1/deliveries/' . $bill->id . '/preview')->assertNotFound();
        $this->withHeader('Authorization', 'Bearer ' . $plain)->getJson('/api/rider-app/v1/deliveries/' . $bill->id . '/preview?revision=bad')->assertNotFound();
        $this->withHeader('Authorization', 'Bearer invalid')->getJson('/api/rider-app/v1/deliveries/' . $bill->id . '/preview?revision=rev')->assertUnauthorized();
    }

    public function test_bearer_endpoint_master_off_and_terminal_or_settled_assignment_are_unavailable(): void
    {
        [$company, $rider, $bill] = $this->fixtures();
        $plain = $rider->id . '|test-token'; $rider->update(['app_token' => hash('sha256', $plain)]);
        app(RiderBillPreviewService::class)->save($company, ['enabled' => false]);
        $this->withHeader('Authorization', 'Bearer ' . $plain)->getJson('/api/rider-app/v1/deliveries/' . $bill->id . '/preview?revision=rev')
            ->assertOk()->assertExactJson(['ok' => true, 'preview' => ['available' => false]]);
        $bill->update(['delivery_status' => 'returned']);
        $this->withHeader('Authorization', 'Bearer ' . $plain)->getJson('/api/rider-app/v1/deliveries/' . $bill->id . '/preview?revision=rev')->assertNotFound();
        $bill->update(['delivery_status' => 'assigned', 'rider_settlement_id' => 3]);
        $this->withHeader('Authorization', 'Bearer ' . $plain)->getJson('/api/rider-app/v1/deliveries/' . $bill->id . '/preview?revision=rev')->assertNotFound();
    }
}