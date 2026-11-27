<?php

namespace Tests\Feature;

use App\Models\AdminUser;
use App\Models\Company;
use App\Models\PaymentProof;
use App\Models\PricingPlan;
use App\Models\Subscription;
use App\Models\SubscriptionInvoice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AdminSubscriptionPaidAssignmentTest extends TestCase
{
    use RefreshDatabase;

    private function setupSale(string $role = 'admin'): array
    {
        Mail::fake();
        $this->actingAs(AdminUser::create([
            'name' => 'Example reviewer', 'email' => 'package-reviewer@example.invalid',
            'role' => $role, 'password' => 'FictionalTestOnly987!',
        ]), 'admin');
        $company = Company::create([
            'name' => 'Example package customer', 'ntn' => 'TESTPKG01', 'product_type' => 'pos',
            'status' => 'approved', 'company_status' => 'active',
        ]);
        $plan = PricingPlan::create([
            'name' => 'Starter', 'product_type' => 'pos', 'price' => 1000,
            'is_trial' => false, 'invoice_limit' => 100,
        ]);
        return ['company_id' => $company->id, 'pricing_plan_id' => $plan->id, 'billing_cycle' => 'annual'];
    }

    public static function methods(): array
    {
        return [['cash'], ['card']];
    }

    public function test_erp_subscription_filter_includes_legacy_healthcare_and_school(): void
    {
        $this->setupSale();
        foreach (['health' => 'health', 'erps' => 'school', 'di' => null] as $type => $vertical) {
            $company = Company::create([
                'name' => 'Example '.$type, 'ntn' => 'FILTER-'.$type, 'product_type' => $type,
                'erps_vertical' => $vertical, 'status' => 'approved', 'company_status' => 'active',
            ]);
            Subscription::create([
                'company_id' => $company->id, 'billing_cycle' => 'annual', 'start_date' => now(),
                'end_date' => now()->addYear(), 'active' => true, 'final_price' => 1500,
            ]);
        }

        $this->get(route('saas.admin.subscriptions', ['product' => 'erps', 'status' => 'active']))
            ->assertOk()->assertViewHas('companiesPage', fn ($page) => $page->count() === 2
                && !$page->pluck('product_type')->contains('di'))
            ->assertSee('School ERP')->assertSee('Healthcare');
    }

    #[DataProvider('methods')]
    public function test_admin_can_confirm_payment_once_and_receive_document_links(string $method): void
    {
        $selection = $this->setupSale();
        $quote = $this->getJson(route('saas.admin.subscriptions.quote', $selection))
            ->assertOk()->assertJsonPath('expected_subscription_id', 0);
        $this->assertEquals(1000, $quote->json('amount'));
        $request = $selection + [
            'assignment_mode' => 'received', 'verified_received_amount' => '1000.00', 'payment_method' => $method,
            'payment_date' => now('Asia/Karachi')->toDateString(), 'idempotency_key' => (string) Str::uuid(),
            'expected_subscription_id' => $quote->json('expected_subscription_id'),
        ];
        $this->post(route('saas.admin.subscriptions.assign'), $request)
            ->assertRedirect(route('saas.admin.subscriptions'))->assertSessionHas('package_sale_documents');
        $invoice = SubscriptionInvoice::where('company_id', $selection['company_id'])->sole();
        $payment = $invoice->payments()->sole();
        $this->assertSame($method, $payment->payment_method);
        $this->assertNotEmpty($payment->receipt_number);
        $this->assertSame('verified', PaymentProof::findOrFail($invoice->payment_proof_id)->status);
        $this->get(route('saas.admin.subscriptions'))->assertOk()
            ->assertSee(route('saas.admin.billing.documents.receipt', [$selection['company_id'], $payment->id]), false)
            ->assertSee($payment->receipt_number);
        $this->post(route('saas.admin.subscriptions.assign'), $request)->assertSessionHas('package_sale_documents');
        $this->assertSame(1, SubscriptionInvoice::where('company_id', $selection['company_id'])->count());
        $this->assertSame(1, Subscription::where('company_id', $selection['company_id'])->where('active', true)->count());
        Http::assertNothingSent();
    }

    public function test_missing_method_or_invalid_amount_does_not_assign_a_package(): void
    {
        $selection = $this->setupSale();
        $this->postJson(route('saas.admin.subscriptions.assign'), $selection + [
            'assignment_mode' => 'received', 'verified_received_amount' => '10.001',
        ])->assertUnprocessable()->assertJsonValidationErrors(['verified_received_amount', 'payment_method', 'idempotency_key']);
        $this->assertFalse(Subscription::where('company_id', $selection['company_id'])->exists());
        $this->assertFalse(PaymentProof::where('company_id', $selection['company_id'])->exists());
    }

    public function test_existing_unpaid_assignment_does_not_issue_a_payment_receipt(): void
    {
        $selection = $this->setupSale();
        $this->post(route('saas.admin.subscriptions.assign'), $selection)->assertSessionHas('success');
        $this->assertTrue(Subscription::where('company_id', $selection['company_id'])->where('active', true)->exists());
        $this->assertFalse(PaymentProof::where('company_id', $selection['company_id'])->exists());
    }

    public function test_viewer_cannot_quote_or_record_a_payment(): void
    {
        $selection = $this->setupSale('viewer');
        $this->getJson(route('saas.admin.subscriptions.quote', $selection))->assertForbidden();
        $this->postJson(route('saas.admin.subscriptions.assign'), $selection + ['assignment_mode' => 'received'])->assertForbidden();
    }
}
