<?php

namespace Tests\Unit;

use App\Services\FbrService;
use PHPUnit\Framework\TestCase;

class DiSandboxBuyerRoleTest extends TestCase
{
    private function invoice(?string $role, string $registration, string $ntn, string $schedule): object
    {
        return (object) [
            'company' => (object) ['fbr_environment' => 'sandbox'],
            'document_type' => 'Sale Invoice',
            'sandbox_buyer_role' => $role,
            'buyer_registration_type' => $registration,
            'buyer_ntn' => $ntn,
            'items' => collect([(object) ['schedule_type' => $schedule, 'hs_code' => '0101.2100']]),
        ];
    }

    public function test_business_and_retail_choices_are_independent_of_buyer_ntn_and_registration(): void
    {
        $service = new FbrService();
        foreach ([
            ['business', 'Registered', '0000001', 'standard', 'SN001'],
            ['business', 'Unregistered', '', 'standard', 'SN002'],
            ['retail_consumer', 'Unregistered', '0000001', 'standard', 'SN026'],
            ['retail_consumer', 'Registered', '0000001', 'standard', 'SN026'],
            ['business', 'Unregistered', '', '3rd_schedule', 'SN008'],
            ['retail_consumer', 'Registered', '0000001', '3rd_schedule', 'SN027'],
            [null, 'Unregistered', '', 'standard', 'SN026'],
            [null, 'Unregistered', '0000001', 'standard', 'SN002'],
            [null, 'Registered', '0000001', '3rd_schedule', 'SN008'],
            [null, 'Unregistered', '0000001', '3rd_schedule', 'SN027'],
            ['retail_consumer', 'Unregistered', '', 'reduced', 'SN028'],
        ] as [$role, $registration, $ntn, $schedule, $expected]) {
            $this->assertSame($expected, $service->sandboxScenarioId($this->invoice($role, $registration, $ntn, $schedule)));
        }
    }

    public function test_production_and_existing_overrides_keep_their_behaviour(): void
    {
        $invoice = $this->invoice('business', 'Unregistered', '', 'standard');
        $invoice->fbr_scenario_id = 'SN026';
        $service = new FbrService();
        $this->assertSame('SN026', $service->sandboxScenarioId($invoice));

        $invoice->company->fbr_environment = 'production';
        $this->assertNull($service->sandboxScenarioId($invoice));
    }
}
