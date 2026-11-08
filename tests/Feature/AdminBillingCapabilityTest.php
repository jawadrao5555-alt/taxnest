<?php

namespace Tests\Feature;

use App\Models\AdminUser;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\Product;
use App\Models\PricingPlan;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class AdminBillingCapabilityTest extends TestCase
{
    use RefreshDatabase;

    private function admin(string $role): AdminUser
    {
        return AdminUser::create(['name' => 'Fictional '.$role, 'email' => uniqid('billing').'@example.invalid',
            'password' => 'Synthetic123!', 'role' => $role]);
    }

    private function paid(): Subscription
    {
        $company = Company::create(['name' => 'Fictional capability company', 'ntn' => uniqid('CAP'), 'product_type' => 'di']);
        $plan = PricingPlan::firstOrCreate(['name' => 'Business', 'product_type' => 'di', 'is_trial' => false], ['price' => 12000, 'invoice_limit' => 100]);

        return Subscription::create(['company_id' => $company->id, 'pricing_plan_id' => $plan->id, 'active' => true,
            'start_date' => now(), 'end_date' => now()->addYear(), 'final_price' => 12000]);
    }

    public function test_read_only_support_and_unknown_roles_cannot_change_billing(): void
    {
        Mail::fake();
        $sub = $this->paid();
        $invoice = Invoice::create([
            'company_id' => $sub->company_id,
            'invoice_number' => 'CAPABILITY-REFUSAL-ONLY',
            'buyer_name' => 'Fictional buyer',
            'status' => 'failed',
            'fbr_status' => 'failed',
            'total_amount' => 1,
        ]);
        $product = Product::create([
            'company_id' => $sub->company_id,
            'name' => 'Fictional retained product',
            'default_price' => 10,
            'is_active' => true,
        ]);
        $before = $sub->fresh()->getAttributes();
        $companyBefore = Company::findOrFail($sub->company_id)->getAttributes();
        $invoiceBefore = $invoice->fresh()->getAttributes();
        $plans = DB::table('pricing_plans')->orderBy('id')->get()->toJson();
        $usersBefore = User::count();
        foreach (['viewer', 'support', 'unknown_role'] as $role) {
            $this->actingAs($this->admin($role), 'admin');
            foreach ([
                ['POST', '/admin/subscriptions/'.$sub->id.'/toggle', []],
                ['POST', '/admin/subscriptions/assign', ['company_id' => $sub->company_id, 'pricing_plan_id' => $sub->pricing_plan_id, 'billing_cycle' => 'annual']],
                ['POST', '/admin/payment-proofs/999999/approve', []], ['POST', '/admin/payment-proofs/999999/reject', []],
                ['POST', '/admin/companies', []],
                ['POST', '/admin/di-companies/store', []],
                ['POST', '/admin/all-users', []],
                ['POST', '/admin/companies/'.$sub->company_id.'/approve', []],
                ['POST', '/admin/companies/'.$sub->company_id.'/limits', ['extra_branch_slots' => 10]],
                ['POST', '/admin/companies/'.$sub->company_id.'/override/lifetime', []],
                ['POST', '/admin/companies/'.$sub->company_id.'/override/temporary', []],
                ['DELETE', '/admin/companies/'.$sub->company_id.'/override', []],
                ['POST', '/admin/company/'.$sub->company_id.'/approve', []],
                ['POST', '/admin/company/'.$sub->company_id.'/suspend', []],
                ['POST', '/admin/company/'.$sub->company_id.'/reject', []],
                ['POST', '/admin/company/'.$sub->company_id.'/toggle-watermark', []],
                ['POST', '/admin/company/'.$sub->company_id.'/change-plan', ['pricing_plan_id' => $sub->pricing_plan_id]],
                ['POST', '/admin/company/'.$sub->company_id.'/toggle-internal', []],
                ['POST', '/admin/company/'.$sub->company_id.'/toggle-inventory', []],
                ['POST', '/admin/company/'.$sub->company_id.'/pos-features/extras', []],
                ['POST', '/admin/company/'.$sub->company_id.'/pos-features/extras/inventory/revoke', []],
                ['PUT', '/admin/company/'.$sub->company_id.'/pos-features', []],
                ['POST', '/admin/company/'.$sub->company_id.'/toggle-fbr-pos', []],
                ['POST', '/admin/company/'.$sub->company_id.'/update-limits', ['invoice_limit_override' => -1]],
                ['POST', '/admin/company/'.$sub->company_id.'/reset-limits', []],
                ['POST', '/admin/risk-settings', []],
                ['POST', '/admin/madadgar-settings', []],
                ['POST', '/admin/invoice-override/'.$invoice->id, ['action' => 'lock']],
                ['POST', '/admin/fbr/retry/'.$invoice->id, []],
                ['POST', '/admin/pricing/apply/'.$product->id, []],
                ['POST', '/admin/consultants/999999/toggle', []],
                ['POST', '/admin/consultants/999999/rate', []],
                ['POST', '/admin/consultant-links/999999/revoke', []],
                ['POST', '/admin/consultant-commissions/999999/paid', []],
                ['POST', '/admin/consultants/min-payout', []],
                ['POST', '/admin/groups/resync', []],
                ['PUT', '/admin/groups/999999', []],
                ['POST', '/admin/groups/999999/link', []],
                ['POST', '/admin/groups/999999/detach/'.$sub->company_id, []],
                ['POST', '/admin/plans', []], ['POST', '/admin/plans/addons', []], ['PUT', '/admin/plans/'.$sub->pricing_plan_id, []],
            ] as [$method,$url,$input]) {
                $this->call($method, $url, $input)->assertForbidden();
            }
        }
        $this->assertSame($before, $sub->fresh()->getAttributes());
        $this->assertSame($companyBefore, Company::findOrFail($sub->company_id)->getAttributes());
        $this->assertSame($invoiceBefore, $invoice->fresh()->getAttributes());
        $this->assertSame($plans, DB::table('pricing_plans')->orderBy('id')->get()->toJson());
        $this->assertSame($usersBefore, User::count());
        $this->assertSame(1, Subscription::where('company_id', $sub->company_id)->count());
        Mail::assertNothingSent();
    }

    public function test_persisted_role_downgrade_revokes_a_cached_billing_session(): void
    {
        $sub = $this->paid();
        $admin = $this->admin('admin');
        $this->actingAs($admin, 'admin');
        AdminUser::whereKey($admin->id)->update(['role' => 'viewer']);
        $this->post('/admin/subscriptions/'.$sub->id.'/toggle')->assertForbidden();
        $this->assertTrue((bool) $sub->fresh()->active);
    }

    public function test_read_only_billing_pages_keep_history_but_do_not_offer_write_forms(): void
    {
        $sub = $this->paid();
        User::create([
            'company_id' => $sub->company_id,
            'name' => 'Fictional retained user history',
            'email' => 'fictional-retained-history@example.test',
            'password' => 'SyntheticOnly123!',
            'role' => 'viewer',
        ]);
        $this->actingAs($this->admin('viewer'), 'admin');
        $this->get('/admin/subscriptions')->assertOk()->assertSee('Fictional capability company')
            ->assertDontSee('action="'.route('saas.admin.subscriptions.assign').'"', false)
            ->assertDontSee('action="'.route('saas.admin.subscriptions.toggle', $sub->id).'"', false);
        $this->get('/admin/plans')->assertOk()
            ->assertDontSee('action="'.route('saas.admin.plans.store').'"', false)
            ->assertDontSee('action="'.route('saas.admin.plans.addons.update').'"', false)
            ->assertDontSee('action="'.route('saas.admin.plans.update', $sub->pricing_plan_id).'"', false);
        $this->get('/admin/all-users')->assertOk()
            ->assertSee('Fictional retained user history')
            ->assertSee('User and company assignments are read-only for this account.')
            ->assertDontSee('+ Add User')
            ->assertDontSee('id="addUserModal"', false)
            ->assertDontSee('action="/admin/all-users"', false);
    }

    public function test_existing_admin_and_owner_billing_authority_is_preserved(): void
    {
        foreach (['admin', 'super_admin'] as $role) {
            $sub = $this->paid();
            $this->actingAs($this->admin($role), 'admin');
            $this->post('/admin/subscriptions/'.$sub->id.'/toggle')->assertRedirect();
            $this->assertFalse((bool) $sub->fresh()->active);
        }
    }

    public function test_existing_administrator_can_create_a_fictional_company_user(): void
    {
        $sub = $this->paid();
        $this->actingAs($this->admin('admin'), 'admin')
            ->post('/admin/all-users', [
                'name' => 'Fictional retained viewer',
                'email' => 'fictional-retained-viewer@example.test',
                'password' => 'SyntheticOnly123!',
                'role' => 'viewer',
                'company_id' => $sub->company_id,
            ])->assertRedirect('/admin/all-users');

        $this->assertDatabaseHas('users', [
            'company_id' => $sub->company_id,
            'email' => 'fictional-retained-viewer@example.test',
            'role' => 'viewer',
        ]);
    }
}
