<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\PosCustomer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class PosSaleCustomerNameEditTest extends TestCase
{
    use RefreshDatabase;

    private function company(string $name, string $productType = 'pos'): Company
    {
        return Company::create([
            'name' => $name,
            'ntn' => (string) random_int(100000000, 999999999),
            'email' => uniqid('customer-edit-', true) . '@test.pk',
            'status' => 'approved',
            'company_status' => 'active',
            'product_type' => $productType,
            'pos_integration_mode' => $productType === 'fbrpos' ? 'fbr' : 'pra',
            'fbr_pos_enabled' => $productType === 'fbrpos',
            'is_internal_account' => true,
        ]);
    }

    private function user(Company $company, string $role = 'pos_cashier'): User
    {
        return User::create([
            'name' => 'Sale User',
            'email' => uniqid('sale-user-', true) . '@test.pk',
            'password' => Hash::make('Secret@12345'),
            'company_id' => $company->id,
            'role' => $role === 'pos_admin' ? 'company_admin' : 'staff',
            'pos_role' => $role,
            'is_active' => true,
        ]);
    }

    private function customer(Company $company, string $name, string $phone): PosCustomer
    {
        return PosCustomer::create([
            'company_id' => $company->id,
            'name' => $name,
            'phone' => $phone,
            'is_active' => true,
        ]);
    }

    public function test_cashier_can_correct_customer_name_but_cannot_change_phone(): void
    {
        $company = $this->company('PRA Shop');
        $cashier = $this->user($company);
        $customer = $this->customer($company, 'XYZ', '03001234567');

        $this->actingAs($cashier, 'pos')
            ->patchJson(route('pos.api.customers.name', $customer->id), [
                'name' => 'Ahmed Khan',
                'phone' => '03009999999',
            ])
            ->assertOk()
            ->assertJsonPath('customer.name', 'Ahmed Khan')
            ->assertJsonPath('customer.phone', '03001234567')
            ->assertHeader('Cache-Control', 'no-store, private');

        $customer->refresh();
        $this->assertSame('Ahmed Khan', $customer->name);
        $this->assertSame('03001234567', $customer->phone);
    }

    public function test_customer_name_edit_is_company_scoped_and_rejects_blank_names(): void
    {
        $company = $this->company('Own Shop');
        $other = $this->company('Other Shop');
        $cashier = $this->user($company);
        $foreignCustomer = $this->customer($other, 'Foreign XYZ', '03002222222');

        $this->actingAs($cashier, 'pos')
            ->patchJson(route('pos.api.customers.name', $foreignCustomer->id), ['name' => 'Hijacked'])
            ->assertNotFound();
        $this->assertSame('Foreign XYZ', $foreignCustomer->fresh()->name);

        $ownCustomer = $this->customer($company, 'Own XYZ', '03001111111');
        $this->actingAs($cashier, 'pos')
            ->patchJson(route('pos.api.customers.name', $ownCustomer->id), ['name' => '   '])
            ->assertStatus(422);
        $this->assertSame('Own XYZ', $ownCustomer->fresh()->name);
    }

    public function test_fbr_sale_route_uses_the_same_name_only_update_handler(): void
    {
        $company = $this->company('FBR Shop', 'fbrpos');
        $cashier = $this->user($company);
        $customer = $this->customer($company, 'AX', '03003333333');

        $this->actingAs($cashier, 'fbrpos')
            ->patchJson(route('fbrpos.api.customers.name', $customer->id), ['name' => 'Ali Raza'])
            ->assertOk()
            ->assertJsonPath('customer.name', 'Ali Raza');

        $this->assertSame('Ali Raza', $customer->fresh()->name);
    }

    public function test_both_sale_screens_expose_the_name_edit_action(): void
    {
        $pra = file_get_contents(resource_path('views/pos/partials/sale-customer-box.blade.php'));
        $praScript = file_get_contents(resource_path('views/pos/universal.blade.php'));
        $fbr = file_get_contents(resource_path('views/fbr-pos/universal.blade.php'));

        $this->assertStringContainsString('@click.stop="editCustomerName(cr)"', $pra);
        $this->assertStringContainsString('/pos/api/customers/', $praScript);
        $this->assertStringContainsString('@click.stop="editCustomerName(cr)"', $fbr);
        $this->assertStringContainsString('/fbr-pos/api/customers/', $fbr);
    }
}