<?php

namespace Tests\Unit;

use App\Services\ScheduleEngine;
use PHPUnit\Framework\TestCase;

/**
 * Task 404: plain 'Services' (no FED) schedule type — FBR scenario SN019.
 * Rate follows the supplier province's services tax rate and is NOT forced
 * to a single value (unlike fed_services which FBR locks to 16%).
 */
class ServicesScheduleTypeTest extends TestCase
{
    public function test_map_sale_type_services(): void
    {
        $this->assertSame('Services', ScheduleEngine::mapSaleType('services'));
    }

    public function test_province_rates(): void
    {
        $this->assertSame(16.0, ScheduleEngine::servicesRateForProvince('Punjab'));
        $this->assertSame(15.0, ScheduleEngine::servicesRateForProvince('Sindh'));
        $this->assertSame(15.0, ScheduleEngine::servicesRateForProvince('Khyber Pakhtunkhwa'));
        $this->assertSame(15.0, ScheduleEngine::servicesRateForProvince('Balochistan'));
        $this->assertSame(15.0, ScheduleEngine::servicesRateForProvince('Islamabad'));
        // Unknown/missing province falls back to 16%
        $this->assertSame(16.0, ScheduleEngine::servicesRateForProvince(null));
        $this->assertSame(16.0, ScheduleEngine::servicesRateForProvince('Somewhere Else'));
        // Case-insensitive match
        $this->assertSame(15.0, ScheduleEngine::servicesRateForProvince('sindh'));
    }

    public function test_get_tax_rate_resolves_by_province(): void
    {
        $this->assertSame(16.0, ScheduleEngine::getTaxRate('services', 'Punjab'));
        $this->assertSame(15.0, ScheduleEngine::getTaxRate('services', 'Sindh'));
        $this->assertSame(15.0, ScheduleEngine::getTaxRate('services', 'Khyber Pakhtunkhwa'));
        $this->assertSame(16.0, ScheduleEngine::getTaxRate('services'));
        // Other schedule types ignore the province argument
        $this->assertSame(16.0, ScheduleEngine::getTaxRate('fed_services', 'Sindh'));
        $this->assertSame(10.0, ScheduleEngine::getTaxRate('reduced', 'Sindh'));
    }

    public function test_validate_items_accepts_provincial_rates(): void
    {
        foreach ([16, 15] as $rate) {
            $items = [['schedule_type' => 'services', 'tax_rate' => $rate, 'price' => 1000, 'quantity' => 1, 'tax' => 1000 * $rate / 100]];
            $this->assertSame([], ScheduleEngine::validateItems($items), "services at {$rate}% must be accepted");
        }
    }

    public function test_validate_items_does_not_force_16_unlike_fed_services(): void
    {
        $items = [['schedule_type' => 'services', 'tax_rate' => 15, 'price' => 100, 'quantity' => 1, 'tax' => 15]];
        $this->assertSame([], ScheduleEngine::validateItems($items));

        $fed = [['schedule_type' => 'fed_services', 'tax_rate' => 15, 'price' => 100, 'quantity' => 1, 'tax' => 15]];
        $this->assertNotEmpty(ScheduleEngine::validateItems($fed));
    }

    public function test_services_requires_no_sro_serial_mrp(): void
    {
        $config = ScheduleEngine::getScheduleConfig('services');
        $this->assertSame('Services', $config['label']);
        $this->assertNull($config['tax_rate']);
        $this->assertFalse($config['requires_sro']);
        $this->assertFalse($config['requires_serial']);
        $this->assertFalse($config['requires_mrp']);
    }

    public function test_validate_fbr_payload_accepts_services_at_15_and_16(): void
    {
        foreach (['15%', '16%'] as $rate) {
            $this->assertSame([], ScheduleEngine::validateFbrPayload($this->payloadWithRate($rate)), "Services at {$rate} must pass payload validation");
        }
    }

    private function payloadWithRate(string $rate): array
    {
        return [
            'invoiceType' => 'Sale Invoice',
            'invoiceDate' => '2026-08-10',
            'sellerNTNCNIC' => '1234567',
            'sellerBusinessName' => 'Services Co',
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
                'saleType' => 'Services',
            ]],
        ];
    }
}
