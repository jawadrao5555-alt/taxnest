<?php

namespace Tests\Feature;

use App\Models\{AdminUser, Company, PricingPlan, Subscription, SubscriptionInvoice, SubscriptionPayment, User};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SubscriptionDocumentAccessTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(): array
    {
        $company = Company::create(['name' => 'Fictional document tenant', 'ntn' => 'DOC-TENANT-1',
            'product_type' => 'di', 'status' => 'approved', 'company_status' => 'active']);
        $plan = PricingPlan::create(['name' => 'Documented package', 'product_type' => 'di', 'price' => 1000,
            'invoice_limit' => 100, 'user_limit' => 2, 'branch_limit' => 1, 'is_trial' => false, 'is_public' => true]);
        $subscription = Subscription::create(['company_id' => $company->id, 'pricing_plan_id' => $plan->id,
            'billing_cycle' => 'annual', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31',
            'final_price' => 1000, 'active' => true]);
        $common = ['version' => 1, 'currency' => 'PKR', 'company' => ['id' => $company->id,
            'name' => $company->name, 'product_type' => 'di'], 'subscription_id' => $subscription->id,
            'review' => ['admin_id' => 1, 'at' => '2026-01-02T10:00:00+05:00'],
            'amount_due' => '1000.00', 'verified_received' => '600.00', 'allocated' => '600.00',
            'remaining_balance' => '400.00', 'unallocated_excess' => '0.00'];
        $invoice = SubscriptionInvoice::create(['company_id' => $company->id, 'subscription_id' => $subscription->id,
            'amount' => 1000, 'status' => 'part_paid', 'due_date' => '2026-01-02', 'document_number' => 'SUB-2026-0000000001',
            'document_snapshot' => $common + ['purchase' => ['plan' => ['name' => $plan->name],
                'period' => ['start' => '2026-01-01', 'end' => '2026-12-31']]]]);
        $payment = SubscriptionPayment::create(['subscription_invoice_id' => $invoice->id, 'amount' => 600,
            'payment_method' => 'bank', 'transaction_ref' => 'FICTIONAL-REF', 'paid_at' => '2026-01-02 10:00:00',
            'receipt_number' => 'RCP-2026-0000000001', 'receipt_snapshot' => $common + [
                'invoice_number' => $invoice->document_number, 'payment_method' => 'bank', 'transaction_ref' => 'FICTIONAL-REF']]);
        $owner = User::create(['name' => 'Fictional owner', 'email' => 'owner@documents.invalid',
            'password' => bcrypt('SyntheticOnly123!'), 'company_id' => $company->id, 'role' => 'company_admin', 'is_active' => true]);

        return compact('company', 'subscription', 'invoice', 'payment', 'owner');
    }

    public function test_company_owner_sees_itemized_history_and_downloads_scoped_pdf_documents(): void
    {
        extract($this->fixture());
        $this->actingAs($owner)->get(route('billing.plans'))->assertOk()
            ->assertSee('SUB-2026-0000000001')->assertSee('RCP-2026-0000000001')
            ->assertSee('400.00')->assertSee('Invoice PDF')->assertSee('Receipt RCP');

        $this->get(route('billing.documents.invoice', $invoice->id))->assertOk()
            ->assertHeader('content-type', 'application/pdf')->assertHeader('cache-control', 'no-store, private');
        $this->get(route('billing.documents.receipt', $payment->id))->assertOk()
            ->assertHeader('content-type', 'application/pdf')->assertHeader('cache-control', 'no-store, private');
    }

    public function test_company_documents_are_owner_only_tenant_scoped_and_legacy_unknown_rows_are_not_invented(): void
    {
        extract($this->fixture());
        $employee = User::create(['name' => 'Fictional employee', 'email' => 'employee@documents.invalid',
            'password' => bcrypt('SyntheticOnly123!'), 'company_id' => $company->id, 'role' => 'employee', 'is_active' => true]);
        $this->actingAs($employee)->get(route('billing.documents.invoice', $invoice->id))->assertForbidden();

        $other = Company::create(['name' => 'Foreign document tenant', 'ntn' => 'DOC-TENANT-2',
            'product_type' => 'di', 'status' => 'approved', 'company_status' => 'active']);
        Subscription::create(['company_id' => $other->id, 'active' => true, 'start_date' => now(), 'end_date' => now()->addYear()]);
        $foreignOwner = User::create(['name' => 'Foreign owner', 'email' => 'foreign@documents.invalid',
            'password' => bcrypt('SyntheticOnly123!'), 'company_id' => $other->id, 'role' => 'company_admin', 'is_active' => true]);
        $this->actingAs($foreignOwner)->get(route('billing.documents.invoice', $invoice->id))->assertNotFound();

        // Different product guards may coexist in one browser. The DI route
        // must authorize its web identity, not whichever guard happens to be
        // checked first by implementation order.
        $posShadow = User::create(['name' => 'Foreign POS identity', 'email' => 'pos-shadow@documents.invalid',
            'password' => bcrypt('SyntheticOnly123!'), 'company_id' => $other->id, 'role' => 'company_admin',
            'pos_role' => 'pos_admin', 'is_active' => true]);
        $this->actingAs($posShadow, 'pos');
        $this->actingAs($owner, 'web')->get(route('billing.documents.invoice', $invoice->id))->assertOk();

        $legacy = SubscriptionInvoice::create(['company_id' => $company->id, 'subscription_id' => $subscription->id,
            'amount' => 12, 'status' => 'pending', 'due_date' => now()]);
        $this->actingAs($owner)->get(route('billing.documents.invoice', $legacy->id))->assertNotFound();
        $this->get(route('billing.plans'))->assertOk()->assertDontSee('#'.$legacy->id.' / subscription');
    }

    public function test_admin_downloads_are_authenticated_and_company_scoped(): void
    {
        extract($this->fixture());
        $admin = AdminUser::create(['name' => 'Fictional billing admin', 'email' => 'admin@documents.invalid',
            'password' => bcrypt('SyntheticOnly123!'), 'role' => 'super_admin']);
        $invoiceUrl = route('saas.admin.billing.documents.invoice', ['company' => $company->id, 'invoice' => $invoice->id]);
        $receiptUrl = route('saas.admin.billing.documents.receipt', ['company' => $company->id, 'payment' => $payment->id]);
        $this->get($invoiceUrl)->assertRedirect('/admin/login');
        $this->actingAs($admin, 'admin')->get($invoiceUrl)->assertOk()->assertHeader('content-type', 'application/pdf');
        $this->get($receiptUrl)->assertOk()->assertHeader('content-type', 'application/pdf');
        $this->get(route('saas.admin.billing.documents.invoice', ['company' => $company->id + 99, 'invoice' => $invoice->id]))->assertNotFound();
    }
}
