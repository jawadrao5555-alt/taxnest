<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Services\InvoicePdfService;
use App\Support\QrImage;
use Tests\TestCase;

/**
 * The QR on a filed Digital Invoice carries the FBR invoice number — alone.
 *
 * FBR tells buyers to "enter the FBR invoice no. OR scan the QR code", so the
 * scan is nothing more than a shortcut for typing that number: Tax Asaan reads
 * the scanned text and looks it up. We used to encode a JSON object (NTN,
 * number, date, total). A generic phone scanner displayed the JSON, which made
 * the code look healthy, but Tax Asaan could not find an invoice number in it —
 * a shop's buyer scanning a properly filed invoice simply got nothing back.
 * (Reported Aug 2026 by the owner: "QR scan karne se invoice number nahi aata
 * Tax Asaan app pe".)
 *
 * FBR POS receipts already encoded the bare number; DI was the odd one out.
 *
 * Run:
 *   php vendor/bin/phpunit tests/Feature/DiInvoiceQrPayloadTest.php --testdox
 */
class DiInvoiceQrPayloadTest extends TestCase
{
    private const FBR_NUMBER = '3120180085013DI8I449K417830';

    protected function setUp(): void
    {
        parent::setUp();
        QrImage::fake();
    }

    protected function tearDown(): void
    {
        QrImage::resetFake();
        parent::tearDown();
    }

    private function invoice(?string $fbrNumber = self::FBR_NUMBER): Invoice
    {
        $company = new Company([
            'name' => 'AL REHMAN TRADERS',
            'address' => 'AHMED PUR SHARKIA',
            'city' => 'AHMED PUR EAST',
            'ntn' => 'B282410-8',
            'fbr_registration_no' => '3120180085013',
        ]);
        $company->id = 22;

        $item = new InvoiceItem([
            'hs_code' => '2402.2000',
            'description' => 'Morven',
            'default_uom' => 'Thousand Unit',
            'quantity' => 0.16,
            'price' => 9903.77,
            'tax' => 285.23,
            'tax_rate' => 18,
        ]);
        $item->id = 8002;

        $invoice = new Invoice([
            'buyer_name' => 'Hassan Super Store',
            'buyer_address' => 'Ghalla Mandi Road',
            'buyer_registration_type' => 'Unregistered',
            'destination_province' => 'Punjab',
            'supplier_province' => 'Punjab',
            'document_type' => 'Sale Invoice',
            'invoice_number' => 'DI-QR-PAYLOAD',
            'invoice_date' => '2026-08-20',
            'status' => 'locked',
            'fbr_status' => 'production',
            'total_amount' => 1869.83,
        ]);
        $invoice->id = 90212;
        $invoice->company_id = 22;
        $invoice->fbr_invoice_number = $fbrNumber;
        $invoice->created_at = now();
        $invoice->updated_at = now();
        $invoice->setRelation('items', collect([$item]));
        $invoice->setRelation('company', $company);
        $invoice->setRelation('branch', null);

        return $invoice;
    }

    public function test_the_pdf_qr_encodes_the_fbr_invoice_number_and_nothing_else(): void
    {
        InvoicePdfService::buildData($this->invoice());

        $this->assertSame(
            [self::FBR_NUMBER],
            QrImage::recorded(),
            'Tax Asaan looks up whatever the QR spells out — it must be the FBR invoice number by itself.'
        );
    }

    public function test_the_pdf_qr_is_never_a_json_blob(): void
    {
        InvoicePdfService::buildData($this->invoice());

        foreach (QrImage::recorded() as $payload) {
            $this->assertStringNotContainsString('{', $payload, 'A JSON payload scans, but verifies nowhere.');
            $this->assertStringNotContainsString('sellerNTNCNIC', $payload);
        }
    }

    public function test_an_unfiled_invoice_draws_no_qr_at_all(): void
    {
        $data = InvoicePdfService::buildData($this->invoice(null));

        $this->assertSame([], QrImage::recorded(), 'Without an FBR number there is nothing to verify.');
        $this->assertSame('', $data['qrBase64'] ?? '');
    }

    public function test_the_screen_and_share_page_qr_uses_the_same_number(): void
    {
        $invoice = $this->invoice();
        $invoice->qr_data = json_encode([
            'sellerNTNCNIC' => '3120180085013',
            'fbr_invoice_number' => self::FBR_NUMBER,
            'invoiceDate' => '2026-08-20',
            'totalValues' => 1869.83,
        ]);

        $this->assertNotNull($invoice->qr_image_url, 'The invoice screen must still show a QR.');
        $this->assertSame([self::FBR_NUMBER], QrImage::recorded());
    }

    /**
     * Older rows stored the JSON in qr_data and nothing else; their QR must
     * still resolve to the number rather than re-encoding the blob.
     */
    public function test_a_legacy_row_without_the_column_falls_back_to_the_stored_number(): void
    {
        $invoice = $this->invoice(null);
        $invoice->qr_data = json_encode(['fbr_invoice_number' => self::FBR_NUMBER]);

        $this->assertNotNull($invoice->qr_image_url);
        $this->assertSame([self::FBR_NUMBER], QrImage::recorded());
    }
}
