<?php

namespace Tests\Unit;

use App\Services\InvoiceImportService;
use PHPUnit\Framework\TestCase;

/**
 * The bulk importer used to stamp every created draft with TODAY's date, so a
 * shop uploading last month's sales saw them all reported on the upload day —
 * and that wrong date went to FBR too. These tests pin the date parsing that
 * fix depends on.
 */
class ImportInvoiceDateTest extends TestCase
{
    public function test_reads_iso_and_day_first_formats(): void
    {
        $this->assertSame('2026-08-15', InvoiceImportService::normalizeDate('2026-08-15'));
        $this->assertSame('2026-08-15', InvoiceImportService::normalizeDate('15/08/2026'));
        $this->assertSame('2026-08-15', InvoiceImportService::normalizeDate('15-08-2026'));
        $this->assertSame('2026-08-15', InvoiceImportService::normalizeDate('15.08.2026'));
        $this->assertSame('2026-08-15', InvoiceImportService::normalizeDate('15-Aug-2026'));
        $this->assertSame('2026-08-15', InvoiceImportService::normalizeDate('2026-08-15 13:45:00'));
    }

    public function test_ambiguous_slash_date_is_read_day_first(): void
    {
        // 05/08/2026 in a Pakistani DMS export means 5 August, never 8 May.
        $this->assertSame('2026-08-05', InvoiceImportService::normalizeDate('05/08/2026'));
    }

    public function test_reads_excel_serial_numbers(): void
    {
        // Excel hands date cells over as serials; 45000 = 2023-03-15.
        $this->assertSame('2023-03-15', InvoiceImportService::normalizeDate(45000));
        $this->assertSame('2023-03-15', InvoiceImportService::normalizeDate('45000'));
    }

    public function test_rejects_values_that_are_not_dates(): void
    {
        $this->assertNull(InvoiceImportService::normalizeDate(''));
        $this->assertNull(InvoiceImportService::normalizeDate(null));
        $this->assertNull(InvoiceImportService::normalizeDate('not a date'));
        $this->assertNull(InvoiceImportService::normalizeDate('32/13/2026'));
        // A plain quantity must never be mistaken for a date serial.
        $this->assertNull(InvoiceImportService::normalizeDate('10'));
    }

    public function test_accepts_datetime_objects(): void
    {
        $this->assertSame('2026-01-02', InvoiceImportService::normalizeDate(new \DateTime('2026-01-02 09:00:00')));
    }

    public function test_same_buyer_on_two_dates_stays_two_invoices(): void
    {
        $service = new InvoiceImportService();
        $base = [
            'buyer_name' => 'ABC Trading Co',
            'buyer_ntn' => '1234567',
            'document_type' => 'Sale Invoice',
            'reference_invoice_number' => '',
        ];

        $this->assertNotSame(
            $service->groupKey($base + ['invoice_date' => '2026-08-15']),
            $service->groupKey($base + ['invoice_date' => '2026-08-16']),
            'Rows from different days must not merge into one draft invoice.'
        );

        $this->assertSame(
            $service->groupKey($base + ['invoice_date' => '2026-08-15']),
            $service->groupKey($base + ['invoice_date' => '2026-08-15']),
            'Rows from the same day and buyer must still combine into one invoice.'
        );
    }
}
