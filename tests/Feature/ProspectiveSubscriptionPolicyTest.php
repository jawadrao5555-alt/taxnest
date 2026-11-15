<?php

namespace Tests\Feature;

use App\Models\AdminUser;
use App\Models\Company;
use App\Models\PaymentProof;
use App\Models\PricingPlan;
use App\Models\Subscription;
use App\Models\SubscriptionInvoice;
use App\Services\DiFeatureService;
use App\Services\PosFeatureService;
use App\Services\ProspectiveSubscriptionPolicy as Policy;
use App\Services\SubscriptionAccessService;
use App\Services\SubscriptionAssignmentService;
use App\Services\TrialSubscriptionService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ProspectiveSubscriptionPolicyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        Notification::fake();
        $this->travelTo(Carbon::parse('2028-02-27 18:30:00', 'UTC'));
    }

    private function fixture(string $product = 'di'): array
    {
        $c = Company::create(['name' => 'Synthetic prospective buyer', 'ntn' => 'PROSPECTIVE-'.uniqid(), 'product_type' => $product, 'status' => 'approved', 'company_status' => 'active']);
        $p = PricingPlan::create(['name' => 'Business', 'product_type' => $product, 'price' => 1000, 'is_trial' => false, 'invoice_limit' => 2, 'user_limit' => 3, 'branch_limit' => 1, 'inventory_enabled' => false, 'reports_enabled' => false, 'caller_id_enabled' => false, 'rider_tracking_enabled' => false, 'ai_page_limit' => 4]);

        return [$c, $p, TrialSubscriptionService::ensureSelectedTrial($c->id, $product, $p->id)];
    }

    public static function products(): array
    {
        return [['di'], ['pos'], ['fbrpos']];
    }

    #[DataProvider('products')]
    public function test_selected_trial_freezes_exact_72_hours_only_bill_count_unlimited(string $product): void
    {
        [$c,$p,$s] = $this->fixture($product);
        $d = Policy::display($s);
        self::assertSame('selected_trial_v1', $d['version']);
        self::assertSame('trial', $d['phase']);
        self::assertSame(72, (int) Carbon::parse($d['trial_starts_at'])->diffInHours(Carbon::parse($d['trial_ends_at'])));
        self::assertSame(-1, $s->entitlementPlan()->invoice_limit);
        self::assertSame(3, $s->entitlementPlan()->user_limit);
        self::assertFalse((bool) $s->entitlementPlan()->inventory_enabled);
        self::assertSame(4, $s->entitlementPlan()->ai_page_limit);
        $p->update(['inventory_enabled' => true, 'user_limit' => 99]);
        self::assertFalse((bool) $s->fresh()->entitlementPlan()->inventory_enabled);
        self::assertSame(3, $s->fresh()->entitlementPlan()->user_limit);
        $this->travelTo(Carbon::parse($d['trial_ends_at'])->subSecond());
        self::assertTrue(SubscriptionAccessService::hasAccess($c)['allowed']);
        $this->travelTo(Carbon::parse($d['trial_ends_at']));
        self::assertFalse(SubscriptionAccessService::hasAccess($c)['allowed']);
        self::assertSame('expired', Policy::display($s)['phase']);
        self::assertDatabaseCount('subscription_invoices', 0);
        self::assertDatabaseCount('subscription_payments', 0);
        Http::assertNothingSent();
    }

    public function test_early_full_approval_preserves_trial_then_paid_and_exact_grace_and_retry_documents_once(): void
    {
        [$c,$p,$trial] = $this->fixture();
        $trialEnd = Policy::display($trial)['trial_ends_at'];
        $admin = AdminUser::create(['name' => 'Synthetic reviewer', 'email' => 'prospective@example.invalid', 'password' => bcrypt('Synthetic123!'), 'role' => 'super_admin']);
        $quote = SubscriptionAssignmentService::computePrice($p, 'annual', $c);
        $proof = PaymentProof::create(['company_id' => $c->id, 'pricing_plan_id' => $p->id, 'billing_cycle' => 'annual', 'amount' => $quote['final_price'], 'proof_path' => 'synthetic-policy', 'status' => 'pending']);
        $input = ['pricing_plan_id' => $p->id, 'billing_cycle' => 'annual', 'verified_received_amount' => $quote['final_price']];
        $this->actingAs($admin, 'admin')->post(route('saas.admin.payment-proofs.approve', $proof->id), $input)->assertSessionHas('success');
        $paid = Subscription::findOrFail($proof->fresh()->subscription_id);
        $d = Policy::display($paid);
        self::assertSame($trialEnd, $d['paid_starts_at']);
        self::assertSame('trial', $d['phase']);
        self::assertSame(-1, $paid->entitlementPlan()->invoice_limit);
        $this->post(route('saas.admin.payment-proofs.approve', $proof->id), $input)->assertRedirect();
        self::assertSame(2, Subscription::where('company_id', $c->id)->count());
        self::assertDatabaseCount('subscription_invoices', 1);
        self::assertDatabaseCount('subscription_payments', 1);
        $this->travelTo(Carbon::parse($trialEnd));
        self::assertSame('paid', Policy::display($paid)['phase']);
        self::assertSame(2, $paid->fresh()->entitlementPlan()->invoice_limit);
        self::assertFalse($paid->isTrialExpired());
        $this->travelTo(Carbon::parse($d['paid_ends_at']));
        self::assertSame('grace', Policy::display($paid)['phase']);
        self::assertTrue(SubscriptionAccessService::hasAccess($c)['allowed']);
        $this->travelTo(Carbon::parse($d['grace_ends_at'])->subSecond());
        self::assertTrue(SubscriptionAccessService::hasAccess($c)['allowed']);
        $this->travelTo(Carbon::parse($d['grace_ends_at']));
        self::assertFalse(SubscriptionAccessService::hasAccess($c)['allowed']);
        self::assertTrue($paid->isExpired());
        $owner = \App\Models\User::create(['name' => 'Synthetic expired owner', 'email' => 'expired-policy@example.invalid', 'password' => bcrypt('Synthetic123!'), 'company_id' => $c->id, 'role' => 'company_admin', 'is_active' => true]);
        $invoice = SubscriptionInvoice::where('payment_proof_id', $proof->id)->sole();
        $receipt = $invoice->payments()->sole();
        $this->actingAs($owner, 'web')->get(route('billing.plans'))->assertOk()->assertSee($invoice->document_number);
        $this->get(route('billing.documents.receipt', $receipt->id))->assertOk()->assertHeader('content-type', 'application/pdf');
        self::assertDatabaseCount('subscription_payments', 1);
        Http::assertNothingSent();
    }

    public function test_partial_confirmation_refuses_without_touching_original_trial(): void
    {
        [$c,$p,$s] = $this->fixture();
        $before = $s->fresh()->getAttributes();
        $quote = SubscriptionAssignmentService::computePrice($p, 'annual', $c);
        try {
            SubscriptionAssignmentService::assign($c->id, $p->id, 'annual', paidRenewal: true, verifiedReceived: $quote['final_price'] - 1);
            self::fail('Partial amount activated.');
        } catch (\Illuminate\Validation\ValidationException $e) {
            self::assertArrayHasKey('verified_received_amount', $e->errors());
        }
        self::assertSame($before, $s->fresh()->getAttributes());
        self::assertSame(1, Subscription::where('company_id', $c->id)->count());
    }

    public function test_late_activation_and_grace_renewal_never_backdate(): void
    {
        [$c,$p,$s] = $this->fixture();
        $this->travelTo(Carbon::parse($s->trial_ends_at)->addDay());
        $quote = SubscriptionAssignmentService::computePrice($p, 'annual', $c);
        $paid = SubscriptionAssignmentService::assign($c->id, $p->id, 'annual', paidRenewal: true, verifiedReceived: $quote['final_price']);
        self::assertSame(now()->utc()->toIso8601String(), Policy::display($paid)['paid_starts_at']);
        $this->travelTo(Carbon::parse(Policy::display($paid)['paid_ends_at'])->addDays(3));
        $renew = SubscriptionAssignmentService::assign($c->id, $p->id, 'annual', paidRenewal: true, verifiedReceived: $quote['final_price']);
        self::assertSame(now()->utc()->toIso8601String(), Policy::display($renew)['paid_starts_at']);
    }

    public function test_legacy_rows_keep_existing_terms_and_caps(): void
    {
        $c = Company::create(['name' => 'Synthetic legacy', 'ntn' => 'LEGACY-POLICY', 'product_type' => 'di']);
        $p = PricingPlan::create(['name' => 'Legacy trial', 'product_type' => 'di', 'price' => 0, 'is_trial' => true, 'invoice_limit' => 10]);
        $s = TrialSubscriptionService::ensureTrial($c->id, 'di');
        self::assertNull(Policy::display($s));
        self::assertSame(10, $s->entitlementPlan()->invoice_limit);
    }

    public function test_selected_trial_does_not_unlock_other_plan_features_or_addon_purchase(): void
    {
        [$di,$dp,$ds] = $this->fixture();
        self::assertFalse(DiFeatureService::planAllows($di, 'public_api'), 'DI public API');
        [$pos,$pp,$ps] = $this->fixture('pos');
        self::assertFalse(PosFeatureService::planAllows($pos, 'reports_enabled'), 'POS reports');
        self::assertFalse(\App\Services\PosAddonService::purchaseEligibility($pos)['allowed'], 'Trial add-on purchase');
    }

    public function test_early_renewal_keeps_current_frozen_features_until_new_term_and_original_dates(): void
    {
        [$c,$p,$s] = $this->fixture();
        $q = SubscriptionAssignmentService::computePrice($p, 'annual', $c);
        $paid = SubscriptionAssignmentService::assign($c->id, $p->id, 'annual', paidRenewal: true, verifiedReceived: $q['final_price']);
        $first = Policy::display($paid);
        $this->travelTo(Carbon::parse($first['paid_starts_at'])->addDay());
        $p->update(['inventory_enabled' => true, 'invoice_limit' => 99]);
        $renew = SubscriptionAssignmentService::assign($c->id, $p->id, 'annual', paidRenewal: true, verifiedReceived: $q['final_price']);
        self::assertSame($first['paid_ends_at'], Policy::display($renew)['paid_starts_at']);
        self::assertFalse((bool) $renew->entitlementPlan()->inventory_enabled);
        self::assertSame(2, $renew->entitlementPlan()->invoice_limit);
        $this->travelTo(Carbon::parse($first['paid_ends_at']));
        self::assertTrue((bool) $renew->fresh()->entitlementPlan()->inventory_enabled);
        self::assertSame(99, $renew->fresh()->entitlementPlan()->invoice_limit);
    }

    public function test_paid_plan_change_starts_new_features_without_old_period_overlap(): void
    {
        [$c,$p,$s] = $this->fixture();
        $this->travelTo(Carbon::parse(Policy::display($s)['trial_ends_at']));
        $q = SubscriptionAssignmentService::computePrice($p, 'annual', $c);
        SubscriptionAssignmentService::assign($c->id, $p->id, 'annual', paidRenewal: true, verifiedReceived: $q['final_price']);
        $this->travel(1)->days();
        $new = PricingPlan::create(['name'=>'Business Plus','product_type'=>'di','price'=>2000,'is_trial'=>false,'invoice_limit'=>99,'inventory_enabled'=>true]);
        $quote = SubscriptionAssignmentService::computePrice($new, 'annual', $c);
        $changed = SubscriptionAssignmentService::assign($c->id, $new->id, 'annual', paidRenewal:true, verifiedReceived:$quote['final_price']);
        self::assertSame(now('UTC')->toIso8601String(), Policy::display($changed)['paid_starts_at']);
        self::assertSame(99, $changed->entitlementPlan()->invoice_limit);
        self::assertTrue((bool)$changed->entitlementPlan()->inventory_enabled);
    }

    public function test_access_grant_does_not_unlock_unpurchased_prospective_addons(): void
    {
        [$c,$p,$s] = $this->fixture('pos');
        $s->update(['override_type'=>'temporary','override_until'=>now()->addDay()]);
        PosFeatureService::flushGateCaches();
        self::assertFalse(PosFeatureService::planAllows($c, 'caller_id_enabled'));
        self::assertFalse(PosFeatureService::planAllows($c, 'rider_tracking_enabled'));
    }

    public function test_nested_cross_company_trial_purchase_evidence_is_not_catalogue_fallback(): void
    {
        [$c,$p,$s] = $this->fixture();
        $bad = $s->lifecycle_snapshot;
        $bad['trial_purchase_snapshot']['company_id'] = $c->id + 100;
        \Illuminate\Support\Facades\DB::table('subscriptions')->where('id',$s->id)->update(['lifecycle_snapshot'=>json_encode($bad)]);
        $fresh = $s->fresh();
        self::assertNull(Policy::snapshot($fresh));
        self::assertNull($fresh->entitlementPlan());
        self::assertFalse($fresh->isTrialActive());
        self::assertTrue($fresh->isExpired());
        self::assertFalse(SubscriptionAccessService::hasAccess($c)['allowed']);
    }

    public function test_policy_snapshot_is_immutable_and_malformed_evidence_fails_closed(): void
    {
        [$c,$p,$s] = $this->fixture();
        try {
            $s->update(['lifecycle_snapshot' => ['version' => 'changed']]);
            self::fail('Changed policy saved.');
        } catch (\LogicException) {
            self::assertTrue(true);
        }
        \Illuminate\Support\Facades\DB::table('subscriptions')->where('id', $s->id)->update(['lifecycle_snapshot' => json_encode(['version' => Policy::VERSION])]);
        self::assertFalse(SubscriptionAccessService::hasAccess($c)['allowed']);
        foreach (['lifetime', 'usage_free', 'temporary', 'grace'] as $type) {
            \Illuminate\Support\Facades\DB::table('subscriptions')->where('id',$s->id)->update(['override_type'=>$type,'override_until'=>now()->addDay()]);
            self::assertFalse(SubscriptionAccessService::hasAccess($c)['allowed'], $type);
        }
    }
}
