<?php

namespace Tests\Feature;

use App\Models\AdminUser;
use App\Models\Company;
use App\Models\HealthBill;
use App\Models\HealthPayment;
use App\Models\Subscription;
use App\Models\SubscriptionInvoice;
use App\Models\SubscriptionPayment;
use App\Support\NestErps;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminErpDashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_erp_dashboard_separates_verified_platform_receipts_from_company_collections(): void
    {
        $admin = AdminUser::create([
            'name' => 'Synthetic platform admin', 'email' => 'erp-dashboard-admin@example.test',
            'password' => 'SyntheticPassword!123', 'role' => 'super_admin',
        ]);
        $hospital = $this->company('Synthetic Hospital', NestErps::PRODUCT_TYPE, NestErps::HEALTH);
        $legacyLab = $this->company('Synthetic legacy Lab', 'health', null);
        $school = $this->company('Synthetic School foundation', NestErps::PRODUCT_TYPE, 'school');
        $di = $this->company('Synthetic DI company', 'di', null);

        $document = ['version' => 1, 'kind' => 'subscription', 'company' => ['id' => $hospital->id],
            'review' => ['admin_id' => $admin->id, 'at' => '2026-11-04T12:00:00+00:00'],
            'amount_due' => '400.00', 'verified_received' => '400.00',
            'erp_upgrade' => ['erp_price_paisa' => 100000, 'credit_paisa' => 60000, 'difference_paisa' => 40000]];
        $subscription = Subscription::create([
            'company_id' => $hospital->id, 'billing_cycle' => 'monthly', 'start_date' => '2026-11-04',
            'end_date' => '2026-12-04', 'active' => true, 'final_price' => 1000,
        ]);
        $invoice = SubscriptionInvoice::forceCreate([
            'company_id' => $hospital->id, 'subscription_id' => $subscription->id, 'amount' => 400,
            'created_at' => '2026-11-04 12:00:00', 'due_date' => '2026-11-04', 'status' => 'paid',
            'document_number' => 'SUB-ERP-TEST', 'document_snapshot' => $document,
        ]);
        SubscriptionPayment::create([
            'subscription_invoice_id' => $invoice->id, 'amount' => 400, 'paid_at' => '2026-11-04 12:00:00',
            'receipt_number' => 'RCP-ERP-TEST', 'receipt_snapshot' => $document + ['invoice_number' => $invoice->document_number],
        ]);

        $this->operatingEntries($hospital, 222, 122, '2026-11-09');
        $this->operatingEntries($legacyLab, 101, 81, '2026-11-09');
        $this->operatingEntries($legacyLab, 90, 30, '2026-10-30');
        $this->operatingEntries($school, 6000, 5500, '2026-11-09');
        $this->operatingEntries($di, 8000, 7000, '2026-11-09');

        $this->actingAs($admin, 'admin');
        $dashboard = $this->get('/admin/dashboard?erp_from=2026-11-01&erp_to=2026-11-30')->assertOk()
            ->assertSee('data-company-tab="erps"', false)
            ->assertSee('data-dashboard-metric="erp_companies"', false)
            ->assertSee('Healthcare company billing')
            ->assertSee('PKR 400.00')->assertSee('PKR 323.00')->assertSee('PKR 203.00')
            ->assertDontSee('PRIVATE-TEST-NOTE');
        $this->assertSame(3, $dashboard->viewData('stats')['erp_companies']);
        $this->assertSame(400.0, $dashboard->viewData('erpTotals')['subscription']['received']);
        $this->assertSame(203.0, $dashboard->viewData('erpTotals')['operations']['received']);
        $this->assertNull($dashboard->viewData('erpSummaries')->get($school->id)['operations']);
        $this->assertFalse($dashboard->viewData('erpSummaries')->has($di->id));

        $detail = $this->get('/admin/dashboard/details/erp_companies?company_id='.$legacyLab->id.'&erp_from=2026-11-01&erp_to=2026-11-30')->assertOk()
            ->assertSee('Synthetic legacy Lab')->assertDontSee('Synthetic Hospital')
            ->assertSee('PKR 81.00')->assertDontSee('PKR 122.00')->assertDontSee('PRIVATE-TEST-NOTE');
        $this->assertSame(81.0, $detail->viewData('erpSummaries')->get($legacyLab->id)['operations']['received']);
    }

    private function company(string $name, string $product, ?string $vertical): Company
    {
        return Company::create([
            'name' => $name, 'ntn' => $name, 'product_type' => $product, 'erps_vertical' => $vertical,
            'status' => 'approved', 'company_status' => 'active',
        ]);
    }

    private function operatingEntries(Company $company, int $invoiced, int $received, string $date): void
    {
        $bill = HealthBill::create([
            'company_id' => $company->id, 'health_patient_id' => 1, 'bill_no' => 'SYN-'.$date,
            'bill_date' => $date, 'doc_type' => HealthBill::TYPE_INVOICE, 'status' => HealthBill::STATUS_FINALIZED,
            'total_amount' => $invoiced, 'outstanding_amount' => $invoiced - $received, 'notes' => 'PRIVATE-TEST-NOTE',
        ]);
        HealthPayment::create([
            'company_id' => $company->id, 'health_patient_id' => 1, 'health_bill_id' => $bill->id,
            'receipt_no' => 'SYN-'.$date, 'business_date' => $date, 'received_at' => $date,
            'kind' => HealthPayment::KIND_PAYMENT, 'amount' => $received, 'method' => 'cash', 'note' => 'PRIVATE-TEST-NOTE',
        ]);
    }
}
