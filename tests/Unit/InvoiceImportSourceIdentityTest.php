<?php

namespace Tests\Unit;

use App\Services\InvoiceImportSourceIdentity;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class InvoiceImportSourceIdentityTest extends TestCase
{
    public function test_reuploads_of_same_source_invoice_have_same_key(): void
    {
        $service = new InvoiceImportSourceIdentity();
        $one = $service->fingerprint(9, 'CCI Voyage', ' 7170-1 ', '2026-10-10');
        $two = $service->fingerprint(9, 'cci voyage', '7170-1', '2026-10-10');
        $this->assertSame($one, $two);
    }

    public function test_tenants_dates_and_source_invoices_are_isolated(): void
    {
        $service = new InvoiceImportSourceIdentity();
        $base = $service->fingerprint(9, 'cci', '101', '2026-10-10');
        $this->assertNotSame($base, $service->fingerprint(10, 'cci', '101', '2026-10-10'));
        $this->assertNotSame($base, $service->fingerprint(9, 'cci', '102', '2026-10-10'));
        $this->assertNotSame($base, $service->fingerprint(9, 'cci', '101', '2026-10-11'));
    }

    public function test_missing_source_invoice_never_falls_back_to_buyer_only(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new InvoiceImportSourceIdentity())->fingerprint(9, 'cci', '', '2026-10-10');
    }

    public function test_invalid_supply_date_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new InvoiceImportSourceIdentity())->fingerprint(9, 'cci', '101', '2026-02-30');
    }
}
