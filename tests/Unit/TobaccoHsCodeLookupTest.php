<?php

namespace Tests\Unit;

use App\Services\ScheduleEngine;
use PHPUnit\Framework\TestCase;

/**
 * A tobacco distributor buys and sells under two very different headings, and
 * getting them mixed up changes the tax base:
 *
 *   2402.2000 — cigarettes. Third Schedule: tax is 18% of MRP, so an MRP is
 *               mandatory. Covered by THIRD_SCHEDULE_HS_PREFIXES.
 *   2404.9100 — nicotine products for oral use (pouches). Standard rate: tax
 *               is 18% of the sale price and there is no MRP.
 *
 * Both readings are taken from the company's own FBR Annex-A purchase record.
 * The risk this pins down is the near-miss: 2404.9100 sits one digit away from
 * the cigarette heading, and treating it as Third Schedule would demand an MRP
 * that does not exist and file the wrong tax base.
 */
class TobaccoHsCodeLookupTest extends TestCase
{
    public function test_oral_nicotine_heading_resolves_to_standard_rate_without_mrp(): void
    {
        foreach (['2404.9100', '24049100'] as $hsCode) {
            $result = ScheduleEngine::lookupByHsCode($hsCode);

            $this->assertNotNull($result, "HS {$hsCode} must be known to the lookup.");
            $this->assertTrue($result['found']);
            $this->assertSame('standard', $result['schedule_type'], "HS {$hsCode} is standard rate, not Third Schedule.");
            $this->assertSame(18, $result['tax_rate']);
            $this->assertFalse($result['requires_mrp'], 'A standard-rate line must not demand an MRP.');
            $this->assertSame('2404.9100', $result['pct_code']);
        }
    }

    public function test_oral_nicotine_heading_is_not_swept_up_by_the_cigarette_prefix(): void
    {
        $this->assertNull(
            ScheduleEngine::thirdScheduleRecommendation('24049100'),
            '2404.9100 must not inherit the 2402.20 Third Schedule recommendation.'
        );

        $this->assertNotNull(
            ScheduleEngine::thirdScheduleRecommendation('24022000'),
            'Cigarettes must still be recommended as Third Schedule.'
        );
    }
}
