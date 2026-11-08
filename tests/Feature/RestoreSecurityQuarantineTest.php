<?php

namespace Tests\Feature;

use App\Models\AdminUser;
use App\Models\Company;
use App\Models\User;
use App\Services\OptionalLocalBillArchive;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class RestoreSecurityQuarantineTest extends TestCase
{
    use RefreshDatabase;

    private string $keyRoot;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(str_repeat('r', 32))]);
        $this->keyRoot = sys_get_temp_dir().'/taxnest-restore-quarantine-'.bin2hex(random_bytes(8));
        config(['local_bill_archive.key_root' => $this->keyRoot]);
    }

    protected function tearDown(): void
    {
        try {
            if (isset($this->keyRoot) && is_dir($this->keyRoot) && !is_link($this->keyRoot)) {
                File::deleteDirectory($this->keyRoot);
            }
        } finally {
            parent::tearDown();
        }
    }

    public function test_confirmed_restore_quarantine_revokes_resurrected_access_and_preserves_business_and_agent_state(): void
    {
        $company = Company::create([
            'name' => 'Fictional restored company', 'product_type' => 'di',
            'ntn' => 'RST'.random_int(1000000, 9999999),
        ]);
        $company->forceFill(['agent_api_key' => 'tnk_fictional_restore_pairing', 'agent_enabled' => true])->saveQuietly();
        $companyCount = DB::table('companies')->count();
        $owner = User::create([
            'name' => 'Fictional restored owner', 'email' => 'restored-owner@example.invalid',
            'password' => Hash::make('SyntheticRestore123!'), 'company_id' => $company->id,
            'role' => 'company_admin',
        ]);
        $owner->forceFill(['session_generation' => '11111111-1111-4111-8111-111111111111'])->saveQuietly();
        $admin = AdminUser::create([
            'name' => 'Fictional restored admin', 'email' => 'restored-admin@example.invalid',
            'password' => Hash::make('SyntheticAdmin123!'), 'role' => 'super_admin',
        ]);
        $companyBefore = (array) DB::table('companies')->where('id', $company->id)->first();

        DB::table('sessions')->insert([
            'id' => Str::random(40), 'user_id' => $owner->id, 'ip_address' => '127.0.0.1',
            'user_agent' => 'fictional restored browser', 'payload' => 'synthetic', 'last_activity' => now()->timestamp,
        ]);
        DB::table('password_reset_tokens')->insert([
            'email' => $owner->email, 'token' => 'synthetic-token', 'created_at' => now(),
        ]);
        DB::table('password_reset_otps')->insert([
            'email' => $owner->email, 'product_type' => 'di', 'otp' => '123456',
            'token' => str_repeat('a', 64), 'expires_at' => now()->addHour(), 'used' => false,
            'reset_grant_hash' => str_repeat('b', 64), 'created_at' => now(), 'updated_at' => now(),
        ]);

        $clientId = str_repeat('c', 32);
        $grantId = (string) Str::uuid();
        DB::table('reporting_oauth_clients')->insert([
            'id' => $clientId, 'name' => 'Fictional restored reader',
            'redirect_uris' => json_encode(['https://reader.invalid/callback']), 'created_at' => now(),
        ]);
        DB::table('reporting_oauth_codes')->insert([
            'hash' => str_repeat('d', 64), 'client_id' => $clientId, 'actor_id' => $admin->id,
            'redirect_uri' => 'https://reader.invalid/callback', 'challenge' => str_repeat('e', 43),
            'resource' => 'https://taxnest.invalid/api/reporting/mcp', 'scope' => 'reporting:read',
            'company_ids' => json_encode([$company->id]), 'expires_at' => now()->addHour(), 'created_at' => now(),
        ]);
        DB::table('reporting_oauth_grants')->insert([
            'id' => $grantId, 'client_id' => $clientId, 'actor_id' => $admin->id,
            'resource' => 'https://taxnest.invalid/api/reporting/mcp', 'scope' => 'reporting:read',
            'company_ids' => json_encode([$company->id]), 'expires_at' => now()->addDay(), 'created_at' => now(),
        ]);
        DB::table('reporting_oauth_access_tokens')->insert([
            'hash' => str_repeat('f', 64), 'grant_id' => $grantId, 'expires_at' => now()->addHour(), 'created_at' => now(),
        ]);
        DB::table('reporting_oauth_refresh_tokens')->insert([
            'hash' => str_repeat('1', 64), 'grant_id' => $grantId, 'expires_at' => now()->addDay(), 'created_at' => now(),
        ]);
        DB::table('consultant_client_links')->insert([
            'consultant_user_id' => $owner->id, 'company_id' => $company->id, 'status' => 'active',
            'initiated_by' => 'client', 'approved_by_user_id' => $owner->id, 'approved_at' => now(),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('consultant_invites')->insert([
            'company_id' => $company->id, 'code' => 'RST'.strtoupper(Str::random(12)),
            'created_by_user_id' => $owner->id, 'expires_at' => now()->addDay(),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->actingAs($owner);
        app(OptionalLocalBillArchive::class)->grant($company->id, $owner, 30, now()->addDays(7));
        $this->assertNotEmpty(glob($this->keyRoot.'/*') ?: []);

        $this->artisan('security:restore-quarantine', ['--restore-id' => 'synthetic-restore-001'])
            ->assertExitCode(1);
        $this->assertSame('11111111-1111-4111-8111-111111111111', $owner->fresh()->session_generation);

        $this->artisan('security:restore-quarantine', [
            '--restore-id' => 'synthetic-restore-001',
            '--confirm' => 'Quarantine restored access',
        ])->assertExitCode(0);

        $generation = $owner->fresh()->session_generation;
        $this->assertTrue(Str::isUuid($generation));
        $this->assertNotSame('11111111-1111-4111-8111-111111111111', $generation);
        $this->assertSame(0, DB::table('sessions')->count());
        $this->assertSame(0, DB::table('password_reset_tokens')->count());
        $reset = DB::table('password_reset_otps')->sole();
        $this->assertTrue((bool) $reset->used);
        $this->assertNull($reset->reset_grant_hash);
        $this->assertNotNull($reset->reset_completed_at);
        $this->assertNotNull(DB::table('reporting_oauth_codes')->value('used_at'));
        $this->assertNotNull(DB::table('reporting_oauth_grants')->value('revoked_at'));
        $this->assertSame(0, DB::table('reporting_oauth_access_tokens')->count());
        $this->assertSame(0, DB::table('reporting_oauth_refresh_tokens')->count());
        $this->assertDatabaseHas('consultant_client_links', [
            'company_id' => $company->id, 'status' => 'revoked', 'revoked_by' => 'restore_quarantine',
        ]);
        $this->assertNotNull(DB::table('consultant_invites')->value('revoked_at'));
        $this->assertFalse((bool) DB::table('support_bill_archive_prefs')->where('company_id', $company->id)->value('enabled'));
        $this->assertDatabaseHas('support_bill_archive_consents', [
            'company_id' => $company->id, 'action' => 'restore_quarantine',
        ]);
        $this->assertSame([], glob($this->keyRoot.'/*') ?: []);
        $this->assertSame($companyBefore, (array) DB::table('companies')->where('id', $company->id)->first(),
            'restore quarantine must not rewrite company settings, subscription, business or Agent state');

        $evidence = DB::table('restore_security_quarantines')->sole();
        $this->assertSame('completed', $evidence->status);
        $this->assertSame(hash('sha256', 'synthetic-restore-001'), $evidence->restore_reference_hash);
        $this->assertNotNull($evidence->completed_at);
        $this->assertSame($companyCount, (int) $evidence->agent_pairing_rows);
        $this->assertSame(64, strlen((string) $evidence->agent_pairing_sha256));

        // Same restore identity is idempotent and cannot rotate sessions twice.
        $this->artisan('security:restore-quarantine', [
            '--restore-id' => 'synthetic-restore-001',
            '--confirm' => 'Quarantine restored access',
        ])->assertExitCode(0);
        $this->assertSame($generation, $owner->fresh()->session_generation);
        $this->assertSame(1, DB::table('restore_security_quarantines')->count());
    }
}
