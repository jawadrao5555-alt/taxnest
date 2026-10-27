<?php

namespace Tests\Unit;

use App\Models\Company;
use App\Models\PricingPlan;
use App\Services\PlanCompanyCompatibility;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PlanCompanyCompatibilityTest extends TestCase
{
    public static function products(): iterable
    {
        foreach (['di', 'pos', 'fbrpos', 'erps'] as $companyType) {
            foreach (['di', 'pos', 'fbrpos', 'erps'] as $planType) {
                yield "{$companyType} / {$planType}" => [$companyType, $planType, $companyType === $planType];
            }
        }
        yield 'legacy healthcare company' => ['health', 'erps', true];
        yield 'legacy healthcare plan' => ['erps', 'health', true];
        yield 'legacy DI company' => [null, 'di', true];
        yield 'legacy DI plan' => ['di', null, true];
        yield 'legacy DI is not PRA' => [null, 'pos', false];
        yield 'unknown product is not assignable' => ['unknown', 'unknown', false];
    }

    #[DataProvider('products')]
    public function test_product_compatibility_preserves_aliases(?string $companyType, ?string $planType, bool $expected): void
    {
        $company = new Company(['product_type' => $companyType]);
        $plan = new PricingPlan(['product_type' => $planType]);
        $this->assertSame($expected, PlanCompanyCompatibility::matches($company, $plan));
    }

    public function test_a_missing_plan_is_refused_with_a_field_error(): void
    {
        $this->expectException(ValidationException::class);
        PlanCompanyCompatibility::assertMatches(new Company(['product_type' => 'pos']), null);
    }

    public function test_unknown_erp_vertical_is_not_silently_treated_as_healthcare(): void
    {
        $company = new Company(['product_type' => 'erps', 'erps_vertical' => 'unregistered_vertical']);
        $plan = new PricingPlan(['product_type' => 'health']);
        $this->assertFalse(PlanCompanyCompatibility::matches($company, $plan));
    }
}
