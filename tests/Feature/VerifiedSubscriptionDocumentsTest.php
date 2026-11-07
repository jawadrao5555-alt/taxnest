<?php

namespace Tests\Feature;

use App\Models\{AdminUser, Company, PaymentProof, PricingPlan, Subscription, SubscriptionInvoice, SubscriptionPayment};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{Http, Mail};
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class VerifiedSubscriptionDocumentsTest extends TestCase
{
    use RefreshDatabase;

    private function purchase(): array
    {
        Mail::fake(); Http::preventStrayRequests();
        $admin = AdminUser::create(['name' => 'Fictional document reviewer', 'email' => 'documents@billing.invalid',
            'role' => 'super_admin', 'password' => 'SyntheticOnly123!']);
        $company = Company::create(['name' => 'Fictional billing tenant', 'ntn' => 'RCDOC1', 'product_type' => 'pos',
            'status' => 'approved', 'company_status' => 'active']);
        $plan = PricingPlan::create(['name' => 'Starter', 'product_type' => 'pos', 'price' => 1000, 'is_trial' => false, 'invoice_limit' => 100]);
        $proof = PaymentProof::create(['company_id' => $company->id, 'pricing_plan_id' => $plan->id, 'billing_cycle' => 'annual',
            'amount' => 9999, 'payment_method' => 'bank_transfer', 'reference' => 'Fictional bank record',
            'proof_path' => 'synthetic-documents', 'status' => 'pending']);
        $this->actingAs($admin, 'admin');
        return [$company, $plan, $proof];
    }

    public static function amounts(): array
    {
        return [[600, 'part_paid', '400.00', '0.00'], [1200, 'paid', '0.00', '200.00']];
    }

    #[DataProvider('amounts')]
    public function test_reviewed_amount_is_distinct_from_claim_and_balance_is_not_silently_forgiven(int $received, string $status, string $balance, string $excess): void
    {
        [$company, $plan, $proof] = $this->purchase();
        $input = ['pricing_plan_id' => $plan->id, 'billing_cycle' => 'annual', 'verified_received_amount' => $received];
        $this->post(route('saas.admin.payment-proofs.approve', $proof->id), $input)->assertSessionHas('success');
        $invoice = SubscriptionInvoice::where('payment_proof_id', $proof->id)->sole();
        $payment = $invoice->payments()->sole();
        $this->assertSame($status, $invoice->status);
        $this->assertEquals(1000, $invoice->amount);
        $this->assertEquals($received, $payment->amount);
        $this->assertSame($balance, $invoice->document_snapshot['remaining_balance']);
        $this->assertSame($excess, $payment->receipt_snapshot['unallocated_excess']);
        $this->assertSame(9999.0, (float) $proof->fresh()->amount);
        $this->assertSame($status === 'paid', $invoice->paid_at !== null);
        $snapshot = $invoice->document_snapshot;
        $this->post(route('saas.admin.payment-proofs.approve', $proof->id), $input)->assertRedirect();
        $this->assertSame(1, SubscriptionInvoice::where('company_id', $company->id)->count());
        $this->assertSame($snapshot, $invoice->fresh()->document_snapshot);
        try { $invoice->update(['amount' => 0]); $this->fail('Issued invoice changed.'); }
        catch (\LogicException) { $this->assertEquals(1000, $invoice->fresh()->amount); }
        try { $payment->update(['receipt_snapshot' => null]); $this->fail('Issued receipt changed.'); }
        catch (\LogicException) { $this->assertNotNull($payment->fresh()->receipt_snapshot); }
        Http::assertNothingSent();
    }

    public function test_late_document_failure_rolls_back_payment_approval_and_activation(): void
    {
        [$company, $plan, $proof] = $this->purchase();
        $other = Company::create(['name' => 'Other fictional billing tenant', 'ntn' => 'RCDOC2', 'product_type' => 'di']);
        $sub = Subscription::create(['company_id' => $other->id, 'pricing_plan_id' => $plan->id, 'active' => true,
            'start_date' => now(), 'end_date' => now()->addYear()]);
        $foreign = SubscriptionInvoice::create(['company_id' => $other->id, 'subscription_id' => $sub->id,
            'amount' => 10, 'status' => 'paid', 'due_date' => now()]);
        // A genuine database uniqueness failure on the SECOND document must
        // roll back the invoice, new term and proof together, without changing
        // the unrelated existing ledger. It is not a mocked exception.
        $receipt = SubscriptionPayment::create(['subscription_invoice_id' => $foreign->id, 'amount' => 10,
            'receipt_number' => 'RCP-'.now()->format('Y').'-'.str_pad((string) $proof->id, 10, '0', STR_PAD_LEFT)]);
        $before = $receipt->fresh()->getAttributes();
        $this->withoutExceptionHandling();
        try {
            $this->post(route('saas.admin.payment-proofs.approve', $proof->id), ['pricing_plan_id' => $plan->id,
                'billing_cycle' => 'annual', 'verified_received_amount' => 1000]);
            $this->fail('Expected the real receipt uniqueness failure.');
        } catch (\Illuminate\Database\QueryException) {
            $this->assertSame('pending', $proof->fresh()->status);
            $this->assertNull($proof->fresh()->subscription_id);
            $this->assertSame(0, Subscription::where('company_id', $company->id)->count());
            $this->assertSame(0, SubscriptionInvoice::where('company_id', $company->id)->count());
            $this->assertSame($before, $receipt->fresh()->getAttributes());
            $this->assertNull($foreign->fresh()->document_snapshot);
        }
    }

    public function test_addon_documents_support_scoped_subscription_and_standalone_topup_purchases(): void
    {
        [$company, $plan, $seed] = $this->purchase();
        $sub = Subscription::create(['company_id' => $company->id, 'pricing_plan_id' => $plan->id, 'active' => true,
            'start_date' => now(), 'end_date' => now()->addYear(), 'billing_cycle' => 'annual', 'final_price' => 1000]);
        $adminId = (int) auth('admin')->id();
        $seed->update(['status' => 'verified', 'verified_by' => $adminId, 'verified_at' => now()]);

        DB::transaction(fn () => \App\Services\VerifiedSubscriptionDocuments::recordAddon(
            $seed->fresh(), $sub, 599, 600, 'pos_addon',
            ['label' => 'PRA POS feature add-on', 'codes' => ['caller_id']]
        ));
        $feature = SubscriptionInvoice::where('payment_proof_id', $seed->id)->sole();
        $this->assertSame($sub->id, $feature->subscription_id);
        $this->assertSame('ADD-', substr($feature->document_number, 0, 4));
        $this->assertSame('pos_addon', $feature->document_snapshot['kind']);
        $this->assertSame('1.00', $feature->document_snapshot['remaining_balance']);
        $this->assertSame('599.00', $feature->payments()->sole()->receipt_snapshot['verified_received']);

        $topup = PaymentProof::create(['company_id' => $company->id, 'amount' => 9999,
            'payment_method' => 'bank_transfer', 'reference' => 'Second fictional bank record',
            'proof_path' => 'synthetic-topup', 'request_type' => 'ai_pages', 'status' => 'verified',
            'verified_by' => $adminId, 'verified_at' => now()]);
        DB::transaction(fn () => \App\Services\VerifiedSubscriptionDocuments::recordAddon(
            $topup, null, 1200, 1000, 'ai_pages', ['label' => 'AI Reader page top-up', 'pages' => 100]
        ));
        $standalone = SubscriptionInvoice::where('payment_proof_id', $topup->id)->sole();
        $this->assertNull($standalone->subscription_id);
        $this->assertSame('ai_pages', $standalone->document_snapshot['kind']);
        $this->assertSame('200.00', $standalone->document_snapshot['unallocated_excess']);
        $this->assertSame(2, SubscriptionInvoice::where('company_id', $company->id)->count());
    }
}
