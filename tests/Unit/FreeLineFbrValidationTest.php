<?php

namespace Tests\Unit;

use App\Services\FbrService;
use App\Services\ScheduleEngine;
use PHPUnit\Framework\TestCase;

class FreeLineFbrValidationTest extends TestCase
{
    public function test_schedule_validation_rejects_zero_value_lines_for_every_sale_type(): void
    {
        foreach (['standard', 'exempt', 'zero_rated'] as $scheduleType) {
            $errors = ScheduleEngine::validateItems([[
                'schedule_type' => $scheduleType,
                'price' => 0,
                'quantity' => 1,
                'tax_rate' => 0,
            ]]);

            $zeroValueErrors = array_values(array_filter(
                $errors,
                fn (string $error) => str_contains($error, '0300')
            ));
            $this->assertCount(1, $zeroValueErrors, "{$scheduleType} zero-value line must get a 0300 error");
        }
    }

    public function test_schedule_validation_rejects_values_that_round_to_zero(): void
    {
        $errors = ScheduleEngine::validateItems([[
            'schedule_type' => 'standard',
            'price' => 0.004,
            'quantity' => 1,
            'tax_rate' => 18,
        ]]);

        $this->assertStringContainsString('0300', implode(' ', $errors));
    }

    public function test_fbr_pre_submission_rejects_zero_value_lines_for_every_sale_type(): void
    {
        foreach (['Goods at Standard Rate (default)', 'Exempt Goods', 'Goods at zero-rate'] as $saleType) {
            $errors = (new FbrService())->validatePayloadPreSubmission(
                $this->payloadWithSaleType($saleType, 0)
            );

            $zeroValueErrors = array_values(array_filter($errors, fn (array $error) => $error['code'] === '0300'));
            $this->assertCount(1, $zeroValueErrors, "{$saleType} zero-value line must get a 0300 error");
            $this->assertStringContainsString('FBR rejects free/bonus lines', $zeroValueErrors[0]['message']);
        }
    }

    private function payloadWithSaleType(string $saleType, float $value): array
    {
        return [
            'sellerNTNCNIC' => '1234567',
            'invoiceType' => 'Sale Invoice',
            'invoiceDate' => '2026-08-19',
            'buyerBusinessName' => 'Test Buyer',
            'buyerRegistrationType' => 'Unregistered',
            'sellerProvince' => 'Punjab',
            'buyerProvince' => 'Punjab',
            'items' => [[
                'hsCode' => '33049900',
                'rate' => '18%',
                'saleType' => $saleType,
                'valueSalesExcludingST' => $value,
                'salesTaxApplicable' => 0,
                'uoM' => 'Numbers, pieces, units',
            ]],
        ];
    }
}