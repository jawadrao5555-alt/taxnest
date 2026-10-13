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

    public function test_explicit_converted_unit_and_case_alias_keep_their_meaning(): void
    {
        Http::fake(['https://gw.fbr.gov.pk/pdi/v2/HS_UOM*' => Http::response($this->referenceResponse(), 200)]);
        $service = new FbrService();
        $this->assertSame('Thousand Unit', $service->resolveUomForHsCode('2402.2000', 'thousand unit', $this->company()));
        $this->assertSame('KG', $service->resolveUomForHsCode('2402.2000', 'kg', $this->company()));
        $this->assertSame('Numbers, pieces, units', $service->resolveUomForHsCode('2402.2000', 'U', $this->company()));
    }

    public function test_reference_failure_does_not_relabel_liters_or_kilograms(): void
    {
        Http::fake(['*' => Http::response([], 503)]);
        $service = new FbrService();
        $this->assertSame('KG', $service->resolveUomForHsCode('2202.1000', 'KG', $this->company()));
        $this->assertSame('Liter', $service->resolveUomForHsCode('3101.0000', 'Liter', $this->company()));
    }

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    public function test_generic_cigarette_uom_is_not_silently_changed_to_thousand_unit(): void
    {
        Http::fake([
            'https://gw.fbr.gov.pk/pdi/v2/HS_UOM*' => Http::response($this->referenceResponse(), 200),
        ]);

        $resolved = (new FbrService())->resolveUomForHsCode(
            '2402.2000',
            'Numbers, pieces, units',
            $this->company()
        );

        $this->assertSame('Numbers, pieces, units', $resolved);
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

    public function test_unavailable_reference_api_preserves_the_original_quantity_dimension(): void
    {
        Http::fake([
            'https://gw.fbr.gov.pk/pdi/v2/HS_UOM*' => Http::response([], 503),
        ]);

        $resolved = (new FbrService())->resolveUomForHsCode(
            '2402.2000',
            'Numbers, pieces, units',
            $this->company()
        );

        $this->assertSame('Numbers, pieces, units', $resolved);
        Http::assertSentCount(1);
    }
}
