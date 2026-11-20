<?php

namespace Tests\Feature;

use App\Models\{AdminUser, Company, PaymentProof, PricingPlan, Subscription, SubscriptionInvoice, SubscriptionPayment};
use App\Services\SubscriptionBillingHistory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HistoricalSubscriptionReceiptTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_dated_ledger_payment_can_be_downloaded_and_printed_without_reissuing_the_ledger(): void
    {
        $admin = AdminUser::create(['name' => 'Billing reviewer', 'email' => 'receipt@example.invalid',
            'role' => 'super_admin', 'password' => 'SyntheticOnly123!']);
        $this->actingAs($admin, 'admin');
        $company = Company::create(['name' => 'Example customer', 'ntn' => 'T-HIST-1', 'product_type' => 'di',
            'status' => 'approved', 'company_status' => 'active']);
        $other = Company::create(['name' => 'Other tenant', 'ntn' => 'T-HIST-2', 'product_type' => 'di']);
        $plan = PricingPlan::create(['name' => 'Kaarobar', 'price' => 1000, 'product_type' => 'di', 'is_trial' => false, 'invoice_limit' => 100]);
        $sub = Subscription::create(['company_id' => $company->id, 'pricing_plan_id' => $plan->id,
            'billing_cycle' => 'annual', 'start_date' => '2025-01-01', 'end_date' => '2025-12-31', 'active' => false]);
        $invoice = SubscriptionInvoice::create(['company_id' => $company->id, 'subscription_id' => $sub->id,
            'amount' => 1000, 'status' => 'paid', 'due_date' => '2025-01-01']);
        $payment = $invoice->payments()->create(['payment_method' => 'cash', 'transaction_ref' => 'TEST-CSH',
            'amount' => 1000, 'paid_at' => '2025-01-02 12:00:00']);
        $before = $payment->fresh()->getAttributes();
        $route = 'saas.admin.billing.documents.receipt';
        $params = ['company' => $company->id, 'payment' => $payment->id];
        $download = $this->get(route($route, $params))->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringStartsWith('attachment;', $download->headers->get('Content-Disposition'));
        $print = $this->get(route($route, $params + ['print' => 1]))->assertOk();
        $this->assertStringStartsWith('inline;', $print->headers->get('Content-Disposition'));
        $this->get(route($route, ['company' => $other->id, 'payment' => $payment->id]))->assertNotFound();
        $this->assertSame($before, $payment->fresh()->getAttributes());
        $this->assertSame([$payment->id], SubscriptionBillingHistory::forCompany($company->id)->first()->payments->modelKeys());
        $this->assertCount(0, SubscriptionBillingHistory::forCompany($other->id));
        $this->view('billing.subscription-document-pdf', ['kind' => 'receipt', 'invoice' => $invoice, 'payment' => $payment,
            'snapshot' => [], 'number' => $payment->receiptDisplayNumber(), 'historical' => true])
            ->assertSee('TaxNest')->assertSee('Example customer')->assertSee('Cash')->assertSee('TEST-CSH')
            ->assertSee('Kaarobar')->assertSee('Annual')->assertSee('02 Jan 2025')->assertSee('1,000.00');
    }

    public function test_partial_receipt_metadata_and_an_undated_legacy_payment_do_not_qualify(): void
    {
        $partial = new SubscriptionPayment(['receipt_number' => 'RCP-INCOMPLETE', 'amount' => 100, 'paid_at' => now()]);
        $undated = new SubscriptionPayment(['amount' => 100]);
        $zero = new SubscriptionPayment(['amount' => 0, 'paid_at' => now()]);
        $this->assertFalse($partial->hasDownloadableReceipt());
        $this->assertFalse($undated->hasDownloadableReceipt());
        $this->assertFalse($zero->hasDownloadableReceipt());
    }

    public function test_only_the_scoped_verified_attestation_supplies_missing_details_on_a_historical_reprint(): void
    {
        $admin = AdminUser::create(['name' => 'Review', 'email' => 'attested@example.invalid',
            'role' => 'super_admin', 'password' => 'SyntheticOnly123!']);
        $this->actingAs($admin, 'admin');
        $company = Company::create(['name' => 'Customer', 'ntn' => 'T-PMT-1', 'product_type' => 'di']);
        $other = Company::create(['name' => 'Other tenant', 'ntn' => 'T-PMT-2', 'product_type' => 'di']);
        $plan = PricingPlan::create(['name' => 'Annual', 'price' => 100, 'product_type' => 'di', 'is_trial' => false, 'invoice_limit' => 100]);
        $sub = Subscription::create(['company_id' => $company->id, 'pricing_plan_id' => $plan->id,
            'billing_cycle' => 'annual', 'start_date' => '2025-01-01', 'end_date' => '2025-12-31', 'active' => false]);
        $invoice = SubscriptionInvoice::create(['company_id' => $company->id, 'subscription_id' => $sub->id,
            'amount' => 100, 'status' => 'paid', 'due_date' => '2025-01-01']);
        $payment = $invoice->payments()->create(['amount' => 100, 'payment_method' => '', 'paid_at' => '2025-01-01']);
        $proof = new PaymentProof;
        $proof->forceFill(['company_id' => $other->id, 'subscription_id' => $sub->id, 'request_type' => 'subscription',
            'status' => 'verified', 'verified_by' => $admin->id, 'verified_at' => now(), 'payment_date' => '2025-01-01',
            'amount' => 100, 'payment_method' => 'card', 'reference' => 'TEST-REF', 'proof_path' => '',
            'admin_settlement_origin' => 'allocated', 'admin_allocated_payment_id' => $payment->id,
            'admin_settlement_invoice_id' => $invoice->id])->save();
        $route = 'saas.admin.billing.documents.receipt';
        $params = ['company' => $company->id, 'payment' => $payment->id];
        $rendered = [];
        \Illuminate\Support\Facades\View::composer('billing.subscription-document-pdf', function ($view) use (&$rendered) {
            $rendered = $view->getData();
        });
        $this->get(route($route, $params))->assertOk();
        $this->assertNull($rendered['attestation']);
        $proof->update(['company_id' => $company->id, 'status' => 'pending']);
        $this->get(route($route, $params))->assertOk();
        $this->assertNull($rendered['attestation']);
        $proof->update(['status' => 'verified']);
        foreach ([[], ['print' => 1]] as $query) {
            $this->get(route($route, $params + $query))->assertOk();
            $this->assertSame('card', $rendered['attestation']->payment_method);
            $this->view('billing.subscription-document-pdf', $rendered)->assertSee('Card')
                ->assertSee('confirmed by admin')->assertSee('TEST-REF');
        }
        $payment->update(['payment_method' => 'bank_transfer', 'transaction_ref' => 'LEDGER']);
        $this->get(route($route, $params))->assertOk();
        $this->view('billing.subscription-document-pdf', $rendered)->assertSee('Bank Transfer')->assertSee('LEDGER')
            ->assertDontSee('confirmed by admin')->assertDontSee('TEST-REF');
    }
}
