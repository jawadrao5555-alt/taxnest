<?php

namespace Tests\Feature;

use App\Models\{Company, PricingPlan, Subscription, User};
use App\Services\PosFeatureService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PurchasedCompanionAccessTest extends TestCase
{
    use RefreshDatabase;

    public static function clients(): array
    {
        return [
            'PRA desktop purchased' => ['pos', true, 'TaxNestDesktop/1.13'],
            'PRA mobile excluded' => ['pos', false, 'Mozilla/5.0 (Linux; Android 14) TaxNestApp'],
            'FBR desktop excluded' => ['fbrpos', false, 'TaxNestDesktop/1.13'],
            'FBR mobile purchased' => ['fbrpos', true, 'Mozilla/5.0 (Linux; Android 14) TaxNestApp'],
        ];
    }

    #[DataProvider('clients')]
    public function test_real_pairing_gate_uses_purchased_access_and_preserves_existing_agents(
        string $product, bool $purchased, string $userAgent
    ): void {
        $company = Company::create(['name' => 'Fictional companion buyer', 'ntn' => 'COMPANION1',
            'product_type' => $product, 'status' => 'approved', 'company_status' => 'active',
            'pos_printer_settings' => ['receipt_printer' => 'Saved receipt printer']]);
        if ($product === 'fbrpos') {
            $company->forceFill(['fbr_pos_enabled' => true])->save();
        }
        $other = Company::create(['name' => 'Fictional isolated companion', 'ntn' => 'COMPANION2',
            'product_type' => $product, 'agent_api_key' => 'synthetic-other-agent', 'agent_enabled' => true]);
        $beforeOther = $other->fresh()->getAttributes();
        $owner = User::create(['name' => 'Fictional companion owner', 'email' => 'companion@example.test',
            'password' => bcrypt('SyntheticOnly123!'), 'company_id' => $company->id,
            'role' => 'company_admin', 'pos_role' => 'pos_admin', 'is_active' => true]);
        $plan = PricingPlan::create(['name' => 'Business', 'product_type' => $product, 'price' => 1000,
            'is_trial' => false, 'invoice_limit' => 100]);
        $plan->forceFill(['offline_enabled' => $purchased])->save();
        $sub = Subscription::create(['company_id' => $company->id, 'pricing_plan_id' => $plan->id,
            'active' => true, 'start_date' => now(), 'end_date' => now()->addYear(),
            'purchase_snapshot' => ['version' => 1, 'company_id' => $company->id,
                'plan' => ['id' => $plan->id, 'product_type' => $product,
                    'is_trial' => false, 'offline_enabled' => $purchased]]]);
        $snapshot = $sub->purchase_snapshot;
        $plan->forceFill(['offline_enabled' => !$purchased])->save();
        PosFeatureService::flushGateCaches();
        $prefix = $product === 'pos' ? '/pos' : '/fbr-pos';
        $this->actingAs($owner, $product)->withHeader('User-Agent', $userAgent);

        $preview = $this->getJson($prefix.'/desktop/agent-pairing');
        if ($purchased) {
            $preview->assertOk()->assertJsonMissingPath('api_key');
            $this->postJson($prefix.'/desktop/agent-pairing', [
                'nonce' => $preview->json('nonce'), 'company_id' => $company->id,
            ])->assertOk()->assertJsonPath('company_id', $company->id);
        } else {
            $preview->assertForbidden();
            $this->assertNull($company->fresh()->agent_api_key);
        }

        // Already-paired installations retain printing/recovery even when the
        // package excludes new pairing. Never rotate a working tenant key.
        $company->update(['agent_api_key' => 'synthetic-retained-agent', 'agent_enabled' => true]);
        $preview = $this->getJson($prefix.'/desktop/agent-pairing')->assertOk();
        $this->postJson($prefix.'/desktop/agent-pairing', [
            'nonce' => $preview->json('nonce'), 'company_id' => $company->id,
        ])->assertOk()->assertJsonPath('api_key', 'synthetic-retained-agent');
        $this->assertSame($snapshot, $sub->fresh()->purchase_snapshot);
        $this->assertSame($beforeOther, $other->fresh()->getAttributes());
        $this->assertSame('Saved receipt printer', $company->fresh()->pos_printer_settings['receipt_printer']);
        $this->assertSame(!$purchased, (bool) $plan->fresh()->offline_enabled);
    }
}
