<?php

namespace Tests\Feature;

use App\Models\{
    AdminAuditLog, AdminUser, Company, PricingPlan, Subscription,
    SubscriptionFinancialAdjustment, SubscriptionInvoice, SubscriptionPayment
};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class SubscriptionFinancialAdjustmentTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(string $role = 'super_admin'): array
    {
        $admin = AdminUser::create([
            'name' => 'Synthetic adjustment reviewer',
            'email' => Str::uuid().'@adjustment.invalid',
            'password' => bcrypt('SyntheticOnly123!'),
            'role' => $role,
        ]);
        $company = Company::create([
            'name' => 'Synthetic adjustment company',
            'email' => Str::uuid().'@adjustment.invalid',
            'ntn' => Str::uuid(),
            'product_type' => 'pos',
            'status' => 'approved',
            'company_status' => 'active',
        ]);
        $plan = PricingPlan::create([
            'name' => 'Synthetic adjustment plan',
            'product_type' => 'pos',
            'price' => 1000,
            'invoice_limit' => 100,
            'is_active' => true,
            'is_trial' => false,
        ]);
        $subscription = Subscription::create([
            'company_id' => $company->id,
            'pricing_plan_id' => $plan->id,
            'billing_cycle' => 'annual',
            'start_date' => now(),
            'end_date' => now()->addYear(),
            'final_price' => 1000,
            'active' => true,
        ]);
        $invoice = SubscriptionInvoice::create([
            'company_id' => $company->id,
            'subscription_id' => $subscription->id,
            'document_number' => 'SUB-SYNTHETIC-'.$company->id,
            'document_snapshot' => ['version' => 1, 'synthetic' => true],
            'amount' => 1000,
            'status' => 'paid',
            'due_date' => now(),
            'paid_at' => now(),
        ]);
        $payment = SubscriptionPayment::create([
            'subscription_invoice_id' => $invoice->id,
            'amount' => 1000,
            'payment_method' => 'bank',
            'transaction_ref' => 'SYNTHETIC-PAYMENT',
            'paid_at' => now(),
            'receipt_number' => 'RCP-SYNTHETIC-'.$company->id,
            'receipt_snapshot' => ['version' => 1, 'synthetic' => true],
        ]);
        return compact('admin', 'company', 'subscription', 'invoice', 'payment');
    }

    private function postAdjustment(array $fixture, array $overrides = [])
    {
        return $this->actingAs($fixture['admin'], 'admin')->post(
            '/admin/companies/'.$fixture['company']->id.'/billing/adjustments',
            array_merge([
                'kind' => 'credit_note',
                'subscription_invoice_id' => $fixture['invoice']->id,
                'amount' => 250,
                'reason' => 'Synthetic retained credit evidence',
                'idempotency_key' => (string) Str::uuid(),
            ], $overrides)
        );
    }

    public function test_credit_evidence_is_append_only_idempotent_and_changes_no_billing_or_access_record(): void
    {
        $f = $this->fixture();
        $before = [
            'company' => $f['company']->fresh()->getAttributes(),
            'subscription' => $f['subscription']->fresh()->getAttributes(),
            'invoice' => $f['invoice']->fresh()->getAttributes(),
            'payment' => $f['payment']->fresh()->getAttributes(),
        ];
        $key = (string) Str::uuid();
        $this->postAdjustment($f, ['idempotency_key' => $key])->assertRedirect();
        $adjustment = SubscriptionFinancialAdjustment::sole();
        $this->assertSame(SubscriptionFinancialAdjustment::CREDIT, $adjustment->kind);
        $this->assertSame('evidence_only', $adjustment->evidence_snapshot['effect']);
        $this->assertSame(['move_money', 'change_access', 'change_price', 'rewrite_invoice', 'forgive_balance'],
            $adjustment->evidence_snapshot['does_not']);
        $this->assertSame(1, AdminAuditLog::where('target_type', 'SubscriptionFinancialAdjustment')
            ->where('target_id', $adjustment->id)->count());

        $this->postAdjustment($f, ['idempotency_key' => $key])->assertRedirect();
        $this->assertSame(1, SubscriptionFinancialAdjustment::count());
        $this->assertSame(1, AdminAuditLog::where('target_type', 'SubscriptionFinancialAdjustment')->count());
        foreach ($before as $name => $attributes) {
            $this->assertSame($attributes, $f[$name]->fresh()->getAttributes(), $name.' changed while recording evidence');
        }
    }

    public function test_external_refund_and_exact_reversal_remain_separate_retained_rows(): void
    {
        $f = $this->fixture();
        $invoiceBefore = $f['invoice']->fresh()->getAttributes();
        $paymentBefore = $f['payment']->fresh()->getAttributes();
        $this->postAdjustment($f, [
            'kind' => 'refund_record',
            'subscription_payment_id' => $f['payment']->id,
            'amount' => 300,
            'external_reference' => 'BANK-REFUND-SYNTHETIC',
            'reason' => 'Synthetic externally completed refund',
        ])->assertRedirect();
        $refund = SubscriptionFinancialAdjustment::sole();

        $this->postAdjustment($f, [
            'kind' => 'reversal',
            'subscription_payment_id' => $f['payment']->id,
            'reverses_adjustment_id' => $refund->id,
            'amount' => 300,
            'reason' => 'Synthetic refund evidence entered in error',
        ])->assertRedirect();
        $reversal = SubscriptionFinancialAdjustment::where('kind', 'reversal')->sole();
        $this->assertSame($refund->id, $reversal->reverses_adjustment_id);
        $this->assertSame(2, SubscriptionFinancialAdjustment::count());
        $this->assertSame($invoiceBefore, $f['invoice']->fresh()->getAttributes());
        $this->assertSame($paymentBefore, $f['payment']->fresh()->getAttributes());

        $this->postAdjustment($f, [
            'kind' => 'reversal',
            'subscription_payment_id' => $f['payment']->id,
            'reverses_adjustment_id' => $refund->id,
            'amount' => 300,
            'reason' => 'Synthetic duplicate reversal',
        ])->assertSessionHasErrors('adjustment');
        $this->assertSame(2, SubscriptionFinancialAdjustment::count());
    }

    public function test_company_payment_and_amount_boundaries_are_enforced_without_mutation(): void
    {
        $f = $this->fixture();
        $other = $this->fixture();
        $this->postAdjustment($f, [
            'subscription_invoice_id' => $other['invoice']->id,
        ])->assertSessionHasErrors('adjustment');
        $this->postAdjustment($f, [
            'kind' => 'refund_record',
            'subscription_payment_id' => $other['payment']->id,
            'external_reference' => 'FOREIGN-REFUND',
        ])->assertSessionHasErrors('adjustment');
        $this->postAdjustment($f, ['amount' => 1000])->assertRedirect();
        $this->postAdjustment($f, ['amount' => 0.01])->assertSessionHasErrors('adjustment');
        $this->assertSame(1, SubscriptionFinancialAdjustment::count());
    }

    public function test_refund_requires_external_reference_and_cannot_exceed_reviewed_receipt(): void
    {
        $f = $this->fixture();
        $this->postAdjustment($f, [
            'kind' => 'refund_record',
            'subscription_payment_id' => $f['payment']->id,
            'amount' => 600,
            'external_reference' => '',
        ])->assertSessionHasErrors('adjustment');
        $this->postAdjustment($f, [
            'kind' => 'refund_record',
            'subscription_payment_id' => $f['payment']->id,
            'amount' => 600,
            'external_reference' => 'BANK-ONE',
        ])->assertRedirect();
        $this->postAdjustment($f, [
            'kind' => 'refund_record',
            'subscription_payment_id' => $f['payment']->id,
            'amount' => 401,
            'external_reference' => 'BANK-TWO',
        ])->assertSessionHasErrors('adjustment');
        $this->assertSame(1, SubscriptionFinancialAdjustment::count());
    }

    public function test_non_billing_role_and_company_user_cannot_record_or_view_admin_evidence(): void
    {
        $f = $this->fixture('support');
        $this->postAdjustment($f)->assertSessionHasErrors('adjustment');
        auth('admin')->logout();
        $companyUser = \App\Models\User::create([
            'name' => 'Synthetic tenant user',
            'email' => Str::uuid().'@adjustment.invalid',
            'password' => bcrypt('SyntheticOnly123!'),
            'company_id' => $f['company']->id,
            'role' => 'company_admin',
            'is_active' => true,
        ]);
        $this->actingAs($companyUser, 'pos')->post(
            '/admin/companies/'.$f['company']->id.'/billing/adjustments', []
        )->assertRedirect('/admin/login');
        $this->assertSame(0, SubscriptionFinancialAdjustment::count());
    }

    public function test_company_account_page_shows_only_its_adjustments_and_immutable_rows_refuse_edit_or_delete(): void
    {
        $f = $this->fixture();
        $other = $this->fixture();
        $this->postAdjustment($f, ['reason' => 'OWN-ADJUSTMENT-EVIDENCE'])->assertRedirect();
        $this->postAdjustment($other, ['reason' => 'FOREIGN-ADJUSTMENT-EVIDENCE'])->assertRedirect();
        $this->actingAs($f['admin'], 'admin')->get('/admin/companies/'.$f['company']->id)
            ->assertOk()->assertSee('OWN-ADJUSTMENT-EVIDENCE')->assertDontSee('FOREIGN-ADJUSTMENT-EVIDENCE')
            ->assertSee('does not move money');
        $own = SubscriptionFinancialAdjustment::where('company_id', $f['company']->id)->sole();
        try {
            $own->update(['reason' => 'Changed']);
            $this->fail('Expected immutable update refusal.');
        } catch (\LogicException $e) {
            $this->assertStringContainsString('immutable', $e->getMessage());
        }
        try {
            $own->delete();
            $this->fail('Expected immutable delete refusal.');
        } catch (\LogicException $e) {
            $this->assertStringContainsString('cannot be deleted', $e->getMessage());
        }
    }
}
