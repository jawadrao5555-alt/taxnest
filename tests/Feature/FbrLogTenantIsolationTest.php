<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\FbrLog;
use App\Models\Invoice;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * fbr_logs carries invoice_id but NO company_id. The authoritative tenant
 * relationship is fbr_logs.invoice_id → invoices.company_id, so every tenant
 * -facing read of a log MUST resolve through an Invoice that was itself
 * company-scoped. No tenant route takes a log id from the request; the two
 * surfaces that render log content to a tenant are:
 *
 *   1. GET /invoice/{invoice}        — the DI panel invoice page shows the last
 *                                      FBR error(s) for a failed invoice.
 *   2. GET /api/di/v1/invoices/status — the DI push API returns `fbr_errors`
 *                                      from the latest log of a failed invoice.
 *
 * These tests pin both: company B can never read company A's FBR log content,
 * company A can.
 *
 * Run:
 *   php vendor/bin/phpunit tests/Feature/FbrLogTenantIsolationTest.php --testdox
 */
class FbrLogTenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET_ERROR = 'SECRET-FBR-ERROR-0042-COMPANY-A-ONLY';

    private Company $companyA;
    private Company $companyB;
    private Invoice $invoiceA;
    private string $apiKeyA;
    private string $apiKeyB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->companyA = $this->company('A-100');
        $this->companyB = $this->company('B-200');

        $this->apiKeyA = "dik_{$this->companyA->id}_isolation_a";
        $this->apiKeyB = "dik_{$this->companyB->id}_isolation_b";
        $this->companyA->forceFill(['di_api_key_hash' => hash('sha256', $this->apiKeyA)])->save();
        $this->companyB->forceFill(['di_api_key_hash' => hash('sha256', $this->apiKeyB)])->save();

        $this->invoiceA = Invoice::create([
            'company_id' => $this->companyA->id,
            'invoice_number' => 'INV-A-1',
            'client_reference' => 'ref-a-1',
            'buyer_name' => 'Buyer A',
            'status' => 'failed',
            'fbr_status' => 'failed',
            'total_amount' => 100,
        ]);

        FbrLog::create([
            'invoice_id' => $this->invoiceA->id,
            'status' => 'failed',
            'request_payload' => json_encode(['x' => 1]),
            'response_payload' => json_encode(['errors' => [self::SECRET_ERROR]]),
        ]);
    }

    private function company(string $ntn): Company
    {
        return Company::create([
            'name' => "Co {$ntn}",
            'ntn' => $ntn,
            'product_type' => 'di',
            'status' => 'approved',
            'company_status' => 'active',
        ]);
    }

    /* ─────────────── 1. DI panel invoice page ─────────────── */

    public function test_company_b_user_cannot_read_company_a_fbr_log_through_the_invoice_page(): void
    {
        $adminB = User::factory()->create(['role' => 'company_admin', 'company_id' => $this->companyB->id, 'is_active' => true]);

        $response = $this->actingAs($adminB, 'web')->get("/invoice/{$this->invoiceA->id}");

        $this->assertContains($response->getStatusCode(), [302, 403, 404]);
        $this->assertStringNotContainsString(self::SECRET_ERROR, $response->getContent());
    }

    public function test_company_a_user_sees_its_own_fbr_log_on_the_invoice_page(): void
    {
        $adminA = User::factory()->create(['role' => 'company_admin', 'company_id' => $this->companyA->id, 'is_active' => true]);

        $this->actingAs($adminA, 'web')->get("/invoice/{$this->invoiceA->id}")
            ->assertOk()
            ->assertSee(self::SECRET_ERROR);
    }

    /* ─────────────── 2. DI push API status ─────────────── */

    public function test_company_b_api_key_cannot_read_company_a_fbr_errors(): void
    {
        foreach ([
            '/api/di/v1/invoices/status?client_reference=ref-a-1',
            '/api/di/v1/invoices/status?invoice_number=INV-A-1',
        ] as $url) {
            $response = $this->withHeader('Authorization', "Bearer {$this->apiKeyB}")->getJson($url);

            $response->assertStatus(404)->assertJsonPath('error', 'not_found');
            $this->assertStringNotContainsString(self::SECRET_ERROR, $response->getContent());
        }
    }

    public function test_company_a_api_key_reads_its_own_fbr_errors(): void
    {
        $this->withHeader('Authorization', "Bearer {$this->apiKeyA}")
            ->getJson('/api/di/v1/invoices/status?client_reference=ref-a-1')
            ->assertOk()
            ->assertJsonPath('invoice.invoice_status', 'failed')
            ->assertJsonPath('fbr_errors.0', self::SECRET_ERROR);
    }

    /* ─────────────── 3. no log-by-id tenant route exists ─────────────── */

    public function test_no_tenant_route_exposes_a_log_by_its_own_id(): void
    {
        $adminB = User::factory()->create(['role' => 'company_admin', 'company_id' => $this->companyB->id, 'is_active' => true]);
        $logId = FbrLog::where('invoice_id', $this->invoiceA->id)->value('id');

        foreach (["/fbr-logs/{$logId}", "/fbr-log/{$logId}", "/invoice/fbr-logs/{$logId}"] as $url) {
            $response = $this->actingAs($adminB, 'web')->get($url);
            $this->assertContains($response->getStatusCode(), [302, 403, 404], $url);
            $this->assertStringNotContainsString(self::SECRET_ERROR, $response->getContent(), $url);
        }
    }
}
