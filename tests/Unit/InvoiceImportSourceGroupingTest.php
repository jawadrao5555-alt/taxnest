<?php

namespace Tests\Unit;

use App\Services\InvoiceImportService;
use PHPUnit\Framework\TestCase;

class InvoiceImportSourceGroupingTest extends TestCase
{
    public function test_two_different_source_invoices_for_one_buyer_same_day_are_not_merged(): void
    {
        $service = new InvoiceImportService();
        $base = [
            'buyer_name' => 'Test Outlet',
            'buyer_ntn' => '',
            'document_type' => 'Sale Invoice',
            'invoice_date' => '2026-10-10',
            '_branch_id' => '',
        ];

        $this->assertNotSame(
            $service->groupKey($base + ['source_invoice_number' => 'CCI-100']),
            $service->groupKey($base + ['source_invoice_number' => 'CCI-101'])
        );
    }

    public function test_same_source_invoice_keeps_multiple_items_together(): void
    {
        $service = new InvoiceImportService();
        $base = [
            'buyer_name' => 'Test Outlet',
            'invoice_date' => '2026-10-10',
            'source_invoice_number' => 'CCI-100',
        ];

        $this->assertSame($service->groupKey($base + ['description' => 'Coke']), $service->groupKey($base + ['description' => 'Sprite']));
    }
}
