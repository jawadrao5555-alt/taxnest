<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class FbrCentralReconciliationMainCompatibilityTest extends TestCase
{
    public function test_unrelated_current_main_routes_survive_reconciliation_integration(): void
    {
        foreach ([
            'pos.hotel.dashboard',
            'pos.hotel.housekeeping',
            'pos.api.kot-print-attention',
            'pos.api.kot-local-core-down',
            'pos.service-work-orders.index',
            'pos.service-work-orders.create',
            'saas.admin.deployment-approval.review',
        ] as $routeName) {
            $this->assertTrue(Route::has($routeName), "Missing current-main route [{$routeName}].");
        }
    }

    public function test_agent_diagnostics_and_submission_evidence_contracts_survive(): void
    {
        $controller = file_get_contents(app_path('Http/Controllers/AgentController.php'));

        $this->assertIsString($controller);
        $this->assertStringContainsString('syncHeartbeatDiagnostics($company, $request)', $controller);
        $this->assertStringContainsString('FbrPosSubmissionEvidenceService', $controller);
        $this->assertStringContainsString("'verification_pending'", $controller);
        $this->assertStringContainsString('recordFbrCallbackDiagnostic($request, $company, $txn)', $controller);
        $this->assertStringContainsString('->lockForUpdate()', $controller);
    }

    public function test_desktop_agent_keeps_release_safety_and_additive_fbr_diagnostics(): void
    {
        $agent = file_get_contents(base_path('pra-agent/src/agent.js'));

        $this->assertIsString($agent);
        $this->assertStringContainsString("require('./heartbeat-guard')", $agent);
        $this->assertStringContainsString('agent_version:', $agent);
        $this->assertStringContainsString('requested_environment:', $agent);
        $this->assertStringContainsString('client_environment:', $agent);
        $this->assertStringContainsString('callbackDiagnostics(', $agent);
    }

    public function test_receipt_and_kot_translation_contracts_survive_in_every_pos_locale(): void
    {
        $requiredKeys = [
            'delivery_receipt_default_invalid',
            'offline_provisional_receipt',
            'offline_final_number_pending',
            'kot_printer_stale_warning',
            'kot_action_required_title',
            'kot_action_required_body',
            'kot_action_required_reprint',
            'kot_reprint_banner',
            'fbr_verified',
            'fbr_central_verification_unknown',
        ];

        foreach (['en', 'rur', 'ur'] as $locale) {
            $translations = require lang_path("{$locale}/pos.php");

            foreach ($requiredKeys as $key) {
                $this->assertArrayHasKey($key, $translations, "{$locale} is missing [{$key}].");
                $this->assertNotSame('', trim((string) $translations[$key]), "{$locale}.{$key} is blank.");
            }
        }
    }
}