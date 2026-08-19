<?php

namespace Tests\Feature;

use App\Services\FbrService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * FBR HS-code UoM regression coverage.
 *
 * Cigarettes (HS 2402.2000) reject the generic unit with FBR error 0099.
 * These tests lock the live-reference correction, local pre-submit guard,
 * and non-blocking fallback behavior in place.
 */
class FbrUomRegressionTest extends TestCase
{
    private function company(): object
    {
        return (object) [
            'id' => 1316,
            'fbr_environment' => 'sandbox',
            // Looks like a raw FBR token, avoiding Crypt/database setup here.
            'fbr_sandbox_token' => str_repeat('a', 36),
            'fbr_production_token' => '',
        ];
    }

    private function referenceResponse(): array
    {
        return [
            ['description' => 'KG'],
            ['description' => 'Thousand Unit'],
        ];
    }

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    public function test_generic_cigarette_uom_resolves_to_thousand_unit_from_fbr_reference(): void
    {
        Http::fake([
            'https://gw.fbr.gov.pk/pdi/v2/HS_UOM*' => Http::response($this->referenceResponse(), 200),
        ]);

        $resolved = (new FbrService())->resolveUomForHsCode(
            '2402.2000',
            'Numbers, pieces, units',
            $this->company()
        );

        $this->assertSame('Thousand Unit', $resolved);
        Http::assertSent(function ($request) {
            return str_contains($request->url(), '/pdi/v2/HS_UOM')
                && $request['hs_code'] === '2402.2000'
                && $request['annexure_id'] === 3;
        });
    }

    public function test_wrong_cigarette_uom_returns_local_0099_with_valid_uoms_without_submission(): void
    {
        Http::fake([
            'https://gw.fbr.gov.pk/pdi/v2/HS_UOM*' => Http::response($this->referenceResponse(), 200),
        ]);

        $payload = [
            'sellerNTNCNIC' => '1234567',
            'invoiceType' => 'Sale Invoice',
            'invoiceDate' => '2026-08-19',
            'buyerBusinessName' => 'Test Buyer',
            'buyerRegistrationType' => 'Unregistered',
            'sellerProvince' => 'Punjab',
            'buyerProvince' => 'Punjab',
            'items' => [[
                'hsCode' => '2402.2000',
                'rate' => '18%',
                'saleType' => 'Goods at standard rate',
                'uoM' => 'Numbers, pieces, units',
                'valueSalesExcludingST' => 100,
                'salesTaxApplicable' => 18,
            ]],
        ];

        $errors = (new FbrService())->validatePayloadPreSubmission($payload, $this->company());

        $uomError = collect($errors)->firstWhere('code', '0099');
        $this->assertNotNull($uomError);
        $this->assertStringContainsString('Numbers, pieces, units', $uomError['message']);
        $this->assertStringContainsString('KG, Thousand Unit', $uomError['message']);

        Http::assertSentCount(1);
        Http::assertNotSent(function ($request) {
            return str_contains($request->url(), '/di_data/v1/di/postinvoicedata');
        });
    }

    public function test_unavailable_reference_api_is_non_blocking_and_uses_cigarette_fallback(): void
    {
        Http::fake([
            'https://gw.fbr.gov.pk/pdi/v2/HS_UOM*' => Http::response([], 503),
        ]);

        $resolved = (new FbrService())->resolveUomForHsCode(
            '2402.2000',
            'Numbers, pieces, units',
            $this->company()
        );

        $this->assertSame('Thousand Unit', $resolved);
        Http::assertSentCount(1);
    }
}