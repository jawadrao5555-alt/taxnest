<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\PosCustomer;
use App\Models\PosCustomerAddress;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PosSavedCustomerAddressEditTest extends TestCase
{
    use RefreshDatabase;

    public function test_editing_saved_addresses_preserves_snapshots_and_rejects_stale_and_foreign_rows(): void
    {
        $company = $this->company('my');
        $other = $this->company('other');
        $customer = $this->customer($company, 'Original default');
        $foreignCustomer = $this->customer($other, 'Foreign address');
        $extra = PosCustomerAddress::create([
            'company_id' => $company->id, 'customer_id' => $customer->id,
            'label' => 'Work', 'address' => 'Original office',
        ]);
        $otherExtra = PosCustomerAddress::create([
            'company_id' => $other->id, 'customer_id' => $foreignCustomer->id,
            'label' => 'Work', 'address' => 'Foreign office',
        ]);
        $bill = DB::table('pos_transactions')->insertGetId([
            'company_id' => $company->id, 'customer_id' => $customer->id,
            'invoice_number' => 'DEMO-1001', 'invoice_mode' => 'local', 'status' => 'completed',
            'subtotal' => 100, 'total_amount' => 100, 'payment_method' => 'cash',
            'delivery_address' => 'Original default',
        ]);
        $owner = User::factory()->create([
            'company_id' => $company->id, 'pos_role' => 'pos_admin', 'role' => 'company_admin', 'is_active' => true,
        ]);
        $this->actingAs($owner, 'pos');

        $defaultUrl = route('pos.customers.addresses.update', [$customer->id, 0], false);
        $defaultUpdate = ['address' => 'New default', 'expected_address' => 'Original default', 'expected_label' => null];
        $this->patchJson($defaultUrl, $defaultUpdate)->assertOk()->assertJsonPath('address.address', 'New default');
        $this->patchJson($defaultUrl, $defaultUpdate)->assertConflict();
        $this->assertSame('New default', $customer->fresh()->address);
        $this->assertDatabaseHas('pos_transactions', ['id' => $bill, 'delivery_address' => 'Original default']);

        $extraUrl = route('pos.customers.addresses.update', [$customer->id, $extra->id], false);
        $extraUpdate = ['address' => 'New office', 'label' => 'Office', 'expected_address' => 'Original office', 'expected_label' => 'Work'];
        $this->patchJson($extraUrl, array_replace($extraUpdate, ['address' => 'NEW DEFAULT']))->assertUnprocessable();
        $this->patchJson($extraUrl, $extraUpdate)->assertOk()->assertJsonPath('address.label', 'Office');
        $this->assertSame('New office', $extra->fresh()->address);

        $this->patchJson(route('pos.customers.addresses.update', [$foreignCustomer->id, $otherExtra->id], false), $extraUpdate)->assertNotFound();
        $this->patchJson(route('pos.customers.addresses.update', [$customer->id, $otherExtra->id], false), $extraUpdate)->assertNotFound();
        $this->assertSame('Foreign office', $otherExtra->fresh()->address);
    }

    private function company(string $name): Company
    {
        return Company::create([
            'name' => 'Synthetic address shop ' . $name, 'ntn' => $name === 'my' ? '100000001' : '100000002',
            'email' => $name . '@test.invalid', 'status' => 'approved',
            'company_status' => 'active', 'product_type' => 'pos',
            'pos_integration_mode' => 'pra', 'is_internal_account' => true, 'pos_setup_completed' => true,
        ]);
    }

    private function customer(Company $company, string $address): PosCustomer
    {
        return PosCustomer::create([
            'company_id' => $company->id, 'name' => 'Synthetic customer', 'address' => $address,
        ]);
    }
}
