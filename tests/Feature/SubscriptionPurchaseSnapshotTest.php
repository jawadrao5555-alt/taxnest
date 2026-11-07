<?php

namespace Tests\Feature;

use App\Models\{AdminUser, Company, PaymentProof, PricingPlan, Subscription};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{Http, Mail};
use Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

class SubscriptionPurchaseSnapshotTest extends TestCase
{
    use RefreshDatabase;

    public static function products(): array
    {
        return [['pos'], ['fbrpos'], ['di']];
    }

    #[DataProvider('products')]
    public function test_real_payment_approval_retains_purchased_plan_after_catalogue_changes(string $product): void
    {
        Mail::fake();
        Http::preventStrayRequests();
        $admin = AdminUser::create(['name' => 'Synthetic billing reviewer', 'email' => 'snapshot-reviewer@example.test',
            'password' => bcrypt('SyntheticOnly123!'), 'role' => 'super_admin']);
        $company = Company::create(['name' => 'Synthetic snapshot company', 'ntn' => 'RCSNAP1', 'product_type' => $product,
            'email' => 'snapshot@example.test', 'status' => 'approved', 'company_status' => 'active']);
        $plan = PricingPlan::create(['name' => 'Starter', 'product_type' => $product, 'price' => 1000,
            'is_trial' => false, 'invoice_limit' => 100, 'features' => ['Original feature'], 'reports_enabled' => true]);
        $proof = PaymentProof::create(['company_id' => $company->id, 'pricing_plan_id' => $plan->id,
            'billing_cycle' => 'annual', 'amount' => 1000, 'proof_path' => 'synthetic-snapshot', 'status' => 'pending']);
        $input = ['pricing_plan_id' => $plan->id, 'billing_cycle' => 'annual', 'verified_received_amount' => 1000];
        $this->actingAs($admin, 'admin')->post(route('saas.admin.payment-proofs.approve', $proof->id), $input)
            ->assertRedirect()->assertSessionHas('success');
        $sub = Subscription::findOrFail($proof->fresh()->subscription_id);
        $this->assertSame(1, \App\Models\SubscriptionInvoice::where('company_id', $company->id)->count(), 'Verified approval must retain its subscription invoice.');
        $invoice = \App\Models\SubscriptionInvoice::where('payment_proof_id', $proof->id)->sole();
        $payment = $invoice->payments()->sole();
        $document = $invoice->document_snapshot;
        $receipt = $payment->receipt_snapshot;
        $this->assertSame((float) $sub->final_price > 1000 ? 'part_paid' : 'paid', $invoice->status);
        $this->assertSame('1000.00', $receipt['verified_received']);
        $this->assertSame($company->id, $document['company']['id']);
        $this->assertSame($invoice->document_number, $receipt['invoice_number']);
        $snapshot = $sub->purchase_snapshot;
        $this->assertIsArray($snapshot);
        $this->assertSame(1, $snapshot['version']);
        $this->assertSame($company->id, $snapshot['company_id']);
        $this->assertSame((string) $sub->final_price, $snapshot['price']['final_price']);
        $this->assertSame($product, $snapshot['plan']['product_type']);
        $this->assertSame(['Original feature'], $snapshot['plan']['features']);
        $this->assertSame(100, $snapshot['plan']['invoice_limit']);
        $this->assertTrue($snapshot['plan']['reports_enabled']);
        $plan->update(['price' => 8000, 'invoice_limit' => 5, 'features' => ['Replacement feature'], 'reports_enabled' => false]);
        $this->assertSame($snapshot, $sub->fresh()->purchase_snapshot);
        $this->assertSame($document, $invoice->fresh()->document_snapshot);
        $this->assertSame($receipt, $payment->fresh()->receipt_snapshot);
        $this->assertSame(1, $invoice->payments()->count());
        $this->post(route('saas.admin.payment-proofs.approve', $proof->id), $input)->assertRedirect();
        $this->assertSame(1, Subscription::where('company_id', $company->id)->count());
        $this->assertSame(1, \App\Models\SubscriptionInvoice::where('payment_proof_id', $proof->id)->count());
        $this->assertSame(1, $invoice->payments()->count());
        $this->assertSame($snapshot, $sub->fresh()->purchase_snapshot);
        Http::assertNothingSent();
    }

    public function test_snapshot_is_immutable_and_legacy_rows_are_not_backfilled(): void
    {
        $company = Company::create(['name' => 'Synthetic legacy company', 'ntn' => 'RCSNAP2', 'product_type' => 'pos']);
        $plan = PricingPlan::create(['name' => 'Starter', 'product_type' => 'pos', 'price' => 1000,
            'is_trial' => false, 'invoice_limit' => 100]);
        $legacy = Subscription::create(['company_id' => $company->id, 'pricing_plan_id' => $plan->id,
            'active' => true, 'start_date' => now(), 'end_date' => now()->addYear()]);
        $legacy->update(['active' => false]);
        $this->assertNull($legacy->fresh()->purchase_snapshot);
        $other = Company::create(['name' => 'Synthetic second company', 'ntn' => 'RCSNAP3', 'product_type' => 'pos']);
        $sub = \App\Services\SubscriptionAssignmentService::assign($other->id, $plan->id, 'annual');
        $original = $sub->purchase_snapshot;
        $sub->update(['active' => false]);
        $this->assertSame($original, $sub->fresh()->purchase_snapshot);
        $this->assertSame($other->id, $original['company_id']);
        $this->assertNull($legacy->fresh()->purchase_snapshot);
        try {
            $sub->update(['purchase_snapshot' => null]);
            $this->fail('Stored purchase evidence was mutable.');
        } catch (\LogicException $e) {
            $this->assertSame($original, $sub->fresh()->purchase_snapshot);
        }
        $this->expectException(\LogicException::class);
        $legacy->update(['purchase_snapshot' => $original]);
    }
}
