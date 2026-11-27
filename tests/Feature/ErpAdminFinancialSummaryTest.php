<?php

namespace Tests\Feature;

use App\Models\AdminUser;
use App\Models\Company;
use App\Services\Reporting\ErpAdminFinancialSummary;
use App\Support\NestErps;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class ErpAdminFinancialSummaryTest extends TestCase
{
    use RefreshDatabase;

    public function test_erp_package_totals_include_real_receipts_and_keep_tenant_activity_separate(): void
    {
        $erp = $this->company('Synthetic ERP', NestErps::PRODUCT_TYPE);
        $legacy = $this->company('Legacy healthcare', 'health');
        $pos = $this->company('Separate POS', 'fbrpos');
        $first = $this->packageInvoice($erp, 'ERP-0001', 71900, '2026-11-01');
        $this->packageReceipt($first, 'ERP-R1', 71900, '2026-11-02');
        $this->packageReceipt($first, 'ERP-R0', 0, '2026-11-02');
        $this->packageReceipt($first, 'ERP-R2', 50.25, '2026-11-27 23:59:59');
        $this->packageReceipt($first, null, 200, '2026-11-26');
        $this->packageReceipt($first, 'ERP-UNATTESTED', 250, '2026-11-26', reviewerId: null);
        $this->packageReceipt($first, 'ERP-FUTURE', 40, '2026-11-28');
        $this->packageInvoice($erp, 'ERP-VOID', 5000, '2026-11-03', status: 'void');

        // A credit-only activation has no real cash receipt or package invoice.
        $addon = $this->packageInvoice($erp, 'ERP-ADD', 700, '2026-11-02', kind: 'ai_pages');
        $this->packageReceipt($addon, 'ERP-ADD-R', 700, '2026-11-02');
        $legacyInvoice = $this->packageInvoice($legacy, 'LEGACY-ERP', 100, '2026-11-03');
        $this->packageReceipt($legacyInvoice, 'LEGACY-R', 100, '2026-11-03');
        // Historical platform money remains included after an ERP company is binned.
        $legacy->delete();
        $posInvoice = $this->packageInvoice($pos, 'POS-0001', 200, '2026-11-03');
        $this->packageReceipt($posInvoice, 'POS-R', 200, '2026-11-03');

        $service = app(ErpAdminFinancialSummary::class);
        $filters = ['date_from' => '2026-11-01', 'date_to' => '2026-11-27'];
        $results = $service->forCompanies(collect([$erp, $legacy, $pos]), $filters);
        $this->assertSame([$erp->id, $legacy->id], $results->keys()->all());
        $this->assertSame(['invoice_count' => 1, 'invoiced' => 71900.0, 'receipt_count' => 2, 'received' => 71950.25],
            $results[$erp->id]['subscription']);
        $this->assertSame(100.0, $results[$legacy->id]['subscription']['received']);
        $this->assertSame(0.0, $results[$erp->id]['operations']['received']);
        $overall = $service->allCompanyTotals($filters);
        $this->assertSame(['invoice_count' => 2, 'invoiced' => 72000.0, 'receipt_count' => 3, 'received' => 72050.25],
            $overall['subscription']);
        $this->assertNull($service->allCompanyTotals($filters + ['branch_id' => 0])['subscription']);
    }

    public function test_healthcare_activity_counts_only_final_bills_and_real_receipt_inflow_by_company_date_and_branch(): void
    {
        $erp = $this->company('Synthetic lab', NestErps::PRODUCT_TYPE);
        $other = $this->company('Other company', NestErps::PRODUCT_TYPE);
        $school = $this->company('Synthetic school', NestErps::PRODUCT_TYPE, 'school');
        $this->healthBill($erp, 'SYN-01', 100, 50, branch: 27);
        $this->healthBill($erp, 'SYN-CANCEL', 999, 999, branch: 27, status: 'cancelled');
        $this->healthBill($erp, 'SYN-ESTIMATE', 888, 888, branch: 27, type: 'estimate');
        $this->healthBill($erp, 'SYN-UNASSIGNED', 300, 90);
        $this->healthBill($other, 'SYN-OTHER', 40, 10, branch: 29);
        $this->healthBill($school, 'SYN-WRONG-VERTICAL', 999, 1);

        $parent = $this->healthPayment($erp, 'SYN-DEPOSIT', 60, kind: 'deposit', branch: 27);
        $this->healthPayment($erp, 'SYN-APPLIED-DEPOSIT', 40, kind: 'deposit', date: '2026-11-28', branch: 27, split: $parent);
        $this->healthPayment($erp, 'SYN-REFUNDED', 10, kind: 'refund', branch: 27);
        $this->healthPayment($erp, 'SYN-WRITEOFF', 800, kind: 'write_off', branch: 27);
        $this->healthPayment($erp, 'SYN-CREDIT', 900, branch: 27, method: 'credit');
        $this->healthPayment($erp, 'SYN-VOID', 500, branch: 27, reversed: true);
        $this->healthPayment($other, 'SYN-OTHER-PAY', 40, branch: 29);
        $this->healthPayment($other, 'SYN-FOREIGN-SPLIT', 999, kind: 'deposit', branch: 29, split: $parent);

        $service = app(ErpAdminFinancialSummary::class);
        $filters = ['date_to' => '2026-11-27', 'date_from' => '2026-11-27'];
        $report = $service->forCompanies(collect([$erp, $other, $school]), $filters + ['branch_id' => 27]);
        $this->assertSame([
            'invoice_count' => 1, 'invoiced' => 100.0, 'receipt_count' => 1, 'received' => 100.0,
            'refunds' => 10.0, 'net_received' => 90.0, 'outstanding' => 50.0,
        ], $report[$erp->id]['operations']);
        $this->assertNull($report[$erp->id]['subscription']);
        $this->assertSame(0, $report[$other->id]['operations']['invoice_count']);
        $this->assertNull($report[$school->id]['operations']);
        $this->assertSame(300.0, $service->forCompanies(collect([$erp]), $filters + ['branch_id' => 0])[$erp->id]['operations']['invoiced']);
        $this->assertSame(440.0, $service->allCompanyTotals($filters)['operations']['invoiced']);
        $this->assertSame(0.0, $service->allCompanyTotals([
            'company_id' => $erp->id, 'date_from' => '2026-11-28', 'date_to' => '2026-11-28',
        ])['operations']['received']);

        $admin = AdminUser::create(['name' => 'Synthetic reviewer', 'email' => 'erp-totals@example.invalid',
            'password' => 'ExamplePassword123!', 'role' => 'super_admin']);
        $this->actingAs($admin, 'admin')->get('/admin/company-usage?'.http_build_query($filters + [
            'product_scope' => 'erps', 'company_id' => $erp->id, 'branch_id' => 27,
        ]))->assertOk()->assertSee('Company Healthcare billing')->assertSee('PKR 90.00 net received')
            ->assertDontSee('SYN-REFUNDED')->assertDontSee('Private sample payer');
    }

    public function test_unavailable_healthcare_sources_do_not_hide_package_totals_or_invent_zeroes(): void
    {
        $erp = $this->company('Synthetic ERP', NestErps::PRODUCT_TYPE);
        DB::statement('DROP TABLE health_bills');

        $report = app(ErpAdminFinancialSummary::class)->forCompanies(collect([$erp]));
        $this->assertNull($report[$erp->id]['operations']);
        $this->assertSame(0, $report[$erp->id]['subscription']['receipt_count']);
    }

    private function company(string $name, string $product, string $vertical = 'health'): Company
    {
        return Company::create(['name' => $name, 'ntn' => 'SYN-'.substr(sha1($name), 0, 12),
            'product_type' => $product, 'erps_vertical' => $vertical, 'status' => 'approved', 'company_status' => 'active']);
    }

    private function packageInvoice(Company $company, string $number, float $amount, string $date, string $status = 'paid', string $kind = 'subscription'): int
    {
        return DB::table('subscription_invoices')->insertGetId(['company_id' => $company->id,
            'amount' => $amount, 'document_number' => $number, 'document_snapshot' => json_encode(['kind' => $kind]),
            'created_at' => $date, 'due_date' => $date, 'status' => $status]);
    }

    private function packageReceipt(int $invoice, ?string $number, float $amount, string $date, ?int $reviewerId = 1): void
    {
        DB::table('subscription_payments')->insert(['subscription_invoice_id' => $invoice, 'amount' => $amount,
            'paid_at' => $date, 'receipt_number' => $number,
            'receipt_snapshot' => $number ? json_encode(['version' => 1, 'kind' => 'subscription', 'review' => ['admin_id' => $reviewerId]]) : null]);
    }

    private function healthBill(Company $company, string $number, float $amount, float $due,
        ?int $branch = null, string $status = 'finalized', string $type = 'invoice'): void
    {
        DB::table('health_bills')->insert(['company_id' => $company->id, 'branch_id' => $branch,
            'health_patient_id' => 123, 'payer_name' => 'Private sample payer', 'bill_no' => $number,
            'bill_date' => '2026-11-27', 'total_amount' => $amount, 'outstanding_amount' => $due,
            'status' => $status, 'doc_type' => $type]);
    }

    private function healthPayment(Company $company, string $number, float $amount, string $kind = 'payment',
        string $date = '2026-11-27', ?int $branch = null, ?int $split = null, string $method = 'cash', bool $reversed = false): int
    {
        return DB::table('health_payments')->insertGetId(['company_id' => $company->id, 'branch_id' => $branch,
            'health_patient_id' => 123, 'receipt_no' => $number, 'kind' => $kind, 'method' => $method,
            'amount' => $amount, 'business_date' => $date, 'split_from_payment_id' => $split,
            'reversed_at' => $reversed ? '2026-11-27 12:00:00' : null]);
    }
}
