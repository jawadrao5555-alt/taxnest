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
    public function test_reviewed_package_change_keeps_original_terms_and_issues_one_new_purchase(string $product): void
    {
        Mail::fake();
        Http::preventStrayRequests();
        $this->travelTo(\Carbon\Carbon::parse('2026-11-08 10:00:00'));
        $admin = AdminUser::create(['name' => 'Synthetic package reviewer', 'email' => 'change-reviewer@example.test',
            'password' => bcrypt('SyntheticOnly123!'), 'role' => 'super_admin']);
        $company = Company::create(['name' => 'Synthetic package change', 'ntn' => 'RCCHANGE1',
            'product_type' => $product, 'status' => 'approved', 'company_status' => 'active']);
        $oldPlan = PricingPlan::create(['name' => 'Starter', 'product_type' => $product, 'price' => 1000,
            'is_trial' => false, 'invoice_limit' => 100]);
        $old = \App\Services\SubscriptionAssignmentService::assign($company->id, $oldPlan->id, 'annual');
        $old->update(['end_date' => now()->addMonths(6)]);
        $oldTerms = $old->fresh()->getAttributes();
        $other = Company::create(['name' => 'Synthetic unchanged buyer', 'ntn' => 'RCCHANGE2', 'product_type' => $product]);
        $foreign = \App\Services\SubscriptionAssignmentService::assign($other->id, $oldPlan->id, 'annual');
        $foreignTerms = $foreign->fresh()->getAttributes();
        $newPlan = PricingPlan::create(['name' => 'Business', 'product_type' => $product, 'price' => 2000,
            'is_trial' => false, 'invoice_limit' => 500]);
        $quote = \App\Services\SubscriptionAssignmentService::computePrice($newPlan, 'annual', $company);
        $proof = PaymentProof::create(['company_id' => $company->id, 'pricing_plan_id' => $newPlan->id,
            'billing_cycle' => 'annual', 'amount' => $quote['final_price'],
            'proof_path' => 'synthetic-package-change', 'status' => 'pending']);
        $input = ['pricing_plan_id' => $newPlan->id, 'billing_cycle' => 'annual',
            'verified_received_amount' => $quote['final_price']];
        $this->actingAs($admin, 'admin')->post(route('saas.admin.payment-proofs.approve', $proof->id), $input)
            ->assertSessionHas('success');
        $new = Subscription::findOrFail($proof->fresh()->subscription_id);
        $this->assertFalse((bool) $old->fresh()->active);
        $this->assertSame(array_diff_key($oldTerms, array_flip(['active', 'updated_at'])),
            array_diff_key($old->fresh()->getAttributes(), array_flip(['active', 'updated_at'])));
        $this->assertSame($foreignTerms, $foreign->fresh()->getAttributes());
        $this->assertSame($newPlan->id, $new->pricing_plan_id);
        $this->assertEquals($quote['final_price'], $new->final_price);
        // Existing package-change policy buys a full annual term. It does not
        // silently invent a credit/refund or transfer old remaining months.
        $this->assertSame(now()->addYear()->toDateString(), $new->end_date->toDateString());
        $this->assertSame(500, $new->entitlementPlan()->invoice_limit);
        $invoice = \App\Models\SubscriptionInvoice::where('payment_proof_id', $proof->id)->sole();
        $this->assertEquals($quote['final_price'], $invoice->amount);
        $this->assertSame(1, $invoice->payments()->count());
        $this->post(route('saas.admin.payment-proofs.approve', $proof->id), $input)->assertRedirect();
        $this->assertSame(1, Subscription::where('company_id', $company->id)->where('active', true)->count());
        $this->assertSame(2, Subscription::where('company_id', $company->id)->count());
        $this->assertSame(1, \App\Models\SubscriptionInvoice::where('payment_proof_id', $proof->id)->count());
        $this->assertSame(1, $invoice->payments()->count());
        Http::assertNothingSent();
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
            'is_trial' => false, 'invoice_limit' => 100, 'features' => ['Original feature'], 'reports_enabled' => true,
            'inventory_enabled' => false, 'ai_page_limit' => 200]);
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
        $plan->update(['price' => 8000, 'invoice_limit' => 5, 'features' => ['Replacement feature'], 'reports_enabled' => false,
            'inventory_enabled' => true, 'ai_page_limit' => 10]);
        $effective = $sub->fresh()->entitlementPlan();
        $this->assertSame(100, $effective->invoice_limit);
        $this->assertTrue((bool) $effective->reports_enabled);
        $this->assertFalse((bool) $effective->inventory_enabled);
        $this->assertSame(['Original feature'], $effective->features);
        $this->assertEquals(8000, $plan->fresh()->price, 'Catalogue pricing remains independently editable.');
        $this->assertSame(100, \App\Services\PlanLimitService::getActiveSubscription($company->id)->entitlementPlan()->invoice_limit);
        $this->assertSame(100, \App\Services\PlanLimitService::getEffectiveLimits($company->id)['invoice']['limit']);
        if ($product === 'di') {
            $this->assertSame(200, \App\Services\AiInvoiceReaderService::monthlyQuota($company));
        }
        if ($product !== 'di') {
            \App\Services\PosFeatureService::flushGateCaches();
            $this->assertTrue(\App\Services\PosFeatureService::planAllows($company, 'reports_enabled'));
        }
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

    public function test_legacy_and_misattributed_snapshots_retain_catalogue_fallback_without_mutating_it(): void
    {
        $plan = PricingPlan::create(['name' => 'Starter', 'product_type' => 'pos', 'price' => 1000,
            'is_trial' => false, 'invoice_limit' => 25, 'reports_enabled' => false]);
        foreach ([null, ['version' => 99], ['version' => 1, 'company_id' => 200,
            'plan' => ['id' => $plan->id, 'invoice_limit' => -1, 'reports_enabled' => true]]] as $snapshot) {
            $sub = new Subscription(['company_id' => 100, 'pricing_plan_id' => $plan->id, 'purchase_snapshot' => $snapshot]);
            $sub->setRelation('pricingPlan', $plan);
            $this->assertSame(25, $sub->entitlementPlan()->invoice_limit);
            $this->assertFalse((bool) $sub->entitlementPlan()->reports_enabled);
        }
        $sub = new Subscription(['company_id' => 100, 'pricing_plan_id' => $plan->id,
            'purchase_snapshot' => ['version' => 1, 'company_id' => 100,
                'plan' => ['id' => $plan->id, 'invoice_limit' => 60, 'reports_enabled' => true, 'price' => 1]]]);
        $sub->setRelation('pricingPlan', $plan);
        $this->assertSame(60, $sub->entitlementPlan()->invoice_limit);
        $this->assertFalse($plan->isDirty());
        $this->assertSame(25, $plan->invoice_limit);
        $this->assertEquals(1000, $sub->entitlementPlan()->price);
    }

    public function test_healthcare_purchased_modules_and_department_limit_survive_catalogue_edits(): void
    {
        $company = Company::create(['name'=>'Synthetic purchased clinic','ntn'=>'SNAPCLINIC','product_type'=>'erps','erps_vertical'=>'health']);
        $plan = PricingPlan::create(['name'=>'Synthetic clinic package','product_type'=>'erps','erps_vertical'=>'health',
            'price'=>1000,'is_trial'=>false,'invoice_limit'=>100,'health_modules'=>['opd'],'health_department_limit'=>2]);
        \App\Services\SubscriptionAssignmentService::assign($company->id,$plan->id,'annual');
        $plan->update(['health_modules'=>['pharmacy'],'health_department_limit'=>0]);
        \App\Services\HealthModuleService::forget($company->id);
        $this->assertSame(['opd'],\App\Services\HealthModuleService::planModules($company));
        $this->assertSame(2,\App\Services\HealthPlatformService::departmentLimit($company));
        $this->assertSame(['pharmacy'],$plan->fresh()->health_modules);
    }
}
