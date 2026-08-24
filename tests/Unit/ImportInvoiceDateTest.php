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

    /**
     * normalizeDate deliberately does NOT reject future dates — the import path
     * needs to tell "unreadable" apart from "in the future" so it can show the
     * right error. Any caller that persists a date without going through row
     * validation (the AI-photo path) must therefore add its own future check.
     */
    public function test_normalize_date_does_not_itself_block_future_dates(): void
    {
        $future = (new \DateTime('+3 days'))->format('Y-m-d');
        $this->assertSame($future, InvoiceImportService::normalizeDate($future));
    }

    /**
     * The legacy CSV fallback lists invoice_date in its template so shops get
     * the column, but must never demand it — every file a shop already has
     * predates the column and has to keep importing.
     */
    public function test_csv_template_offers_invoice_date_without_requiring_it(): void
    {
        $reflection = new \ReflectionClass(\App\Http\Controllers\CsvImportController::class);
        $template = $reflection->getConstant('TEMPLATE_COLUMNS');
        $optional = $reflection->getConstant('OPTIONAL_TEMPLATE_COLUMNS');

        $this->assertContains('invoice_date', $template, 'CSV template must offer the date column.');
        $this->assertContains('invoice_date', $optional, 'An existing CSV without the column must still import.');
    }

    /**
     * The batch review screen must be able to show and export the date, or a
     * shop cannot see (let alone fix) a wrong one before submitting to FBR.
     */
    public function test_review_screen_treats_invoice_date_as_an_editable_header_field(): void
    {
        $this->assertContains(
            'invoice_date',
            \App\Services\BulkDraftReviewService::HEADER_FIELDS,
            'invoice_date must be an editable header field on the review screen.'
        );
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
