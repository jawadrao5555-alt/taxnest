<?php

namespace Tests\Feature;

use App\Models\AdminUser;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminInvoiceDocumentRenderTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_di_preview_renders_actual_stored_invoice_as_pdf(): void
    {
        $this->withoutExceptionHandling();
        $company = Company::create(['name' => 'Synthetic DI preview', 'email' => 'render@preview.invalid', 'ntn' => 'RENDER-DI-001', 'product_type' => 'di', 'status' => 'approved', 'company_status' => 'active', 'is_internal_account' => true, 'fbr_environment' => 'sandbox']);
        $invoice = Invoice::forceCreate(['company_id' => $company->id, 'invoice_number' => 'RC-ADMIN-DI-PREVIEW', 'internal_invoice_number' => 'RC-ADMIN-DI-PREVIEW', 'buyer_name' => 'Synthetic Preview Buyer', 'invoice_date' => '2026-10-01', 'status' => 'draft', 'total_amount' => 118, 'total_value_excluding_st' => 100, 'total_sales_tax' => 18]);
        InvoiceItem::forceCreate(['invoice_id' => $invoice->id, 'hs_code' => '0101.2100', 'description' => 'Synthetic admin preview line', 'quantity' => 1, 'price' => 100, 'tax' => 18, 'tax_rate' => 18]);
        $admin = AdminUser::create(['name' => 'Synthetic Admin', 'email' => 'admin@preview.invalid', 'password' => bcrypt('SyntheticOnly123!'), 'role' => 'super_admin', 'is_active' => true]);
        $response = $this->actingAs($admin, 'admin')->get('/admin/dashboard/invoices/di/'.$company->id.'/'.$invoice->id.'/preview');
        $response->assertOk()->assertHeader('content-type', 'application/pdf');
        $this->assertStringStartsWith('%PDF-', $response->getContent());
        $this->assertStringContainsString('Synthetic Preview Buyer', (new \Smalot\PdfParser\Parser)->parseContent($response->getContent())->getText());
    }
}
