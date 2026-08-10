<?php

namespace Tests\Unit;

use App\Services\ScheduleEngine;
use PHPUnit\Framework\TestCase;

/**
 * Task 403: FBR only accepts a 16% tax rate for 'Services (FED in ST Mode)' (SN018).
 * Confirmed Aug 2026: 16% passes sandbox, 13% and 19.5% both rejected.
 */
class FedServicesRateGuardTest extends TestCase
{
    public function test_map_sale_type_fed_services(): void
    {
        $this->assertSame('Services (FED in ST Mode)', ScheduleEngine::mapSaleType('fed_services'));
    }

    public function test_default_tax_rate_is_16(): void
    {
        $this->assertSame(16.0, ScheduleEngine::getTaxRate('fed_services'));
    }

    public function test_validate_items_rejects_non_16_rate(): void
    {
        $items = [['schedule_type' => 'fed_services', 'tax_rate' => 13, 'price' => 100, 'quantity' => 1]];
        $errors = ScheduleEngine::validateItems($items);
        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('16%', $errors[0]);
    }

    public function test_validate_items_accepts_16_rate(): void
    {
        $items = [['schedule_type' => 'fed_services', 'tax_rate' => 16, 'price' => 100, 'quantity' => 1]];
        $this->assertSame([], ScheduleEngine::validateItems($items));
    }

    public function test_validate_fbr_payload_rejects_wrong_rate(): void
    {
        $payload = $this->payloadWithRate('13%');
        $errors = ScheduleEngine::validateFbrPayload($payload);
        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('16%', $errors[0]);
    }

    public function test_validate_fbr_payload_accepts_16_rate(): void
    {
        $this->assertSame([], ScheduleEngine::validateFbrPayload($this->payloadWithRate('16%')));
    }

    private function payloadWithRate(string $rate): array
    {
        return [
            'invoiceType' => 'Sale Invoice',
            'invoiceDate' => '2026-08-10',
            'sellerNTNCNIC' => '1234567',
            'sellerBusinessName' => 'Al Haq Enterprises',
            'sellerProvince' => 'Punjab',
            'buyerProvince' => 'Punjab',
            'items' => [[
                'hsCode' => '9815.9000',
                'productDescription' => 'Consultancy services',
                'rate' => $rate,
                'uoM' => 'Numbers, pieces, units',
                'quantity' => 1,
                'valueSalesExcludingST' => 1000,
                'salesTaxApplicable' => 160,
                'saleType' => 'Services (FED in ST Mode)',
            ]],
        ];
    }
}
