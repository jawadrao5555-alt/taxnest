<?php

namespace Tests\Unit;

use App\Services\ScheduleEngine;
use PHPUnit\Framework\TestCase;

class ThirdScheduleHeadingRecommendationTest extends TestCase
{
    public function test_cigarette_subheading_defaults_to_third_schedule_in_all_hs_formats(): void
    {
        foreach (['2402.2000', '24022000', '24022010'] as $hsCode) {
            $recommendation = ScheduleEngine::lookupByHsCode($hsCode);

            $this->assertNotNull($recommendation);
            $this->assertTrue($recommendation['found']);
            $this->assertSame('3rd_schedule', $recommendation['schedule_type']);
            $this->assertSame(18.0, $recommendation['tax_rate']);
            $this->assertTrue($recommendation['requires_mrp']);
            $this->assertTrue($recommendation['third_schedule_recommended']);
        }
    }

    public function test_cigarette_heading_overrides_a_stale_standard_master_recommendation(): void
    {
        $resolved = ScheduleEngine::applyThirdScheduleRecommendation([
            'found' => true,
            'schedule_type' => 'standard',
            'tax_rate' => 18.0,
            'requires_mrp' => false,
            'default_uom' => 'Thousand Unit',
        ], '2402.2000');

        $this->assertSame('3rd_schedule', $resolved['schedule_type']);
        $this->assertSame(18.0, $resolved['tax_rate']);
        $this->assertTrue($resolved['requires_mrp']);
        $this->assertSame('Thousand Unit', $resolved['default_uom']);
    }

    public function test_cigar_subheading_keeps_its_standard_master_mapping(): void
    {
        $resolved = ScheduleEngine::applyThirdScheduleRecommendation([
            'found' => true,
            'schedule_type' => 'standard',
            'tax_rate' => 18.0,
            'requires_mrp' => false,
        ], '24021000');

        $this->assertSame('standard', $resolved['schedule_type']);
        $this->assertSame(18.0, $resolved['tax_rate']);
        $this->assertFalse($resolved['requires_mrp']);
        $this->assertArrayNotHasKey('third_schedule_recommended', $resolved);
    }
}