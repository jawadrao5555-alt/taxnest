<?php
namespace Tests\Feature;

use App\Services\FbrPosPlanComparisonService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PilotFiscalPublicClaimsTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_category_and_package_copy_does_not_promise_pilot_fiscal_or_unverified_device_access(): void
    {
        $this->get('/fbr-pos-landing')->assertOk()
            ->assertSee('School and Hospital local billing works with fiscal reporting OFF')
            ->assertSee('registration is not fiscal approval')
            ->assertSee('DEMO-NOT-A-FISCAL-NUMBER')
            ->assertDontSee('fiscal invoice number and QR on every receipt');
        $rows = FbrPosPlanComparisonService::INCLUDED_ROWS;
        $this->assertStringContainsString('School/Hospital reporting OFF', $rows['fbr_live']['label']);
        $this->assertStringContainsString('configured Agent and device verification', $rows['thermal']['label']);
        $this->get('/fbr-pos/register')->assertOk()
            ->assertSee('School / Education')->assertSee('Hospital / Clinic')
            ->assertSee('name="requested_plan"', false);
    }
}
