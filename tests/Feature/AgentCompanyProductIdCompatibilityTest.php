<?php

namespace Tests\Feature;

use App\Http\Middleware\CheckCompanyApproval;
use App\Http\Middleware\FbrPosAuth;
use App\Http\Middleware\PosAuth;
use App\Models\Company;
use App\Models\PosAgentDevice;
use App\Models\User;
use App\Support\IdentityScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AgentCompanyProductIdCompatibilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_backfill_keeps_existing_desktop_pairing_and_registered_counters_on_their_numeric_ids(): void
    {
        $this->withoutMiddleware([PosAuth::class, FbrPosAuth::class, CheckCompanyApproval::class]);

        $installations = [];
        foreach (['pos' => 'P', 'fbrpos' => 'F'] as $panel => $prefix) {
            $key = 'fixture-agent-key-'.$panel;
            $deviceUid = 'existing-counter-'.$panel;
            // Raw fixture rows look like production companies created before
            // the display ID allocator was installed.
            $id = DB::table('companies')->insertGetId([
                'name' => 'Pairing fixture '.$panel,
                'ntn' => 'PAIR-'.$panel,
                'product_type' => $panel,
                'status' => 'approved',
                'company_status' => 'active',
                'agent_enabled' => true,
                'agent_api_key' => $key,
                'agent_api_key_hash' => hash('sha256', $key),
            ]);
            DB::table('companies')->where('id', $id)->update(['account_code' => IdentityScope::accountCodeFor($panel, $id)]);
            $company = Company::findOrFail($id);
            $this->assertNull($company->product_company_id);
            $owner = User::factory()->create([
                'company_id' => $company->id,
                'role' => 'company_admin',
                'pos_role' => 'pos_admin',
                'is_active' => true,
            ]);
            PosAgentDevice::create([
                'company_id' => $company->id,
                'device_uid' => $deviceUid,
                'receipt_printer' => 'Existing printer '.$panel,
            ]);

            $this->actingAs($owner, $panel);
            $path = route($panel.'.desktop.pairing');
            $preview = $this->getJson($path)->assertOk()
                ->assertJsonPath('company_id', $company->id)
                ->assertJsonPath('credential_digest', hash('sha256', $key));
            $installations[$panel] = [$prefix, $company, $owner, $path, $preview->json('nonce'), $key, $deviceUid];
        }

        (require database_path('migrations/2027_01_01_000030_create_company_product_ids.php'))->up();

        foreach ($installations as $panel => [$prefix, $company, $owner, $path, $nonce, $key, $deviceUid]) {
            $backfilled = $company->fresh();
            $this->assertMatchesRegularExpression('/^'.$prefix.'[0-9]{2,}$/', $backfilled->product_company_id);
            $this->assertSame($company->account_code, $backfilled->account_code);

            // A confirmation started before deployment still uses its original
            // company identity and the previously saved credential.
            $this->actingAs($owner, $panel)->postJson($path, ['nonce' => $nonce, 'company_id' => $company->id])
                ->assertOk()->assertJsonPath('company_id', $company->id)->assertJsonPath('api_key', $key);

            $preview = $this->getJson($path)->assertOk()
                ->assertJsonPath('company_id', $company->id)
                ->assertJsonPath('credential_digest', hash('sha256', $key));
            $this->postJson($path, ['nonce' => $preview->json('nonce'), 'company_id' => $backfilled->product_company_id])
                ->assertUnprocessable()->assertJsonValidationErrors('company_id');

            $this->getJson('/api/agent/realtime-auth?device_uid='.$deviceUid, ['Authorization' => 'Bearer '.$key])
                ->assertOk()->assertExactJson([
                    'ok' => true,
                    'company_id' => (string) $company->id,
                    'device_uid' => $deviceUid,
                ]);
            $this->assertDatabaseHas('pos_agent_devices', [
                'company_id' => $company->id,
                'device_uid' => $deviceUid,
                'receipt_printer' => 'Existing printer '.$panel,
            ]);
        }
    }
}
