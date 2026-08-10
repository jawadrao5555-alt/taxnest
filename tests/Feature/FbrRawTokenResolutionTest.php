<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Services\FbrService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * TASK: Stop FBR sandbox tests from silently failing when a company's token
 * is stored unencrypted (raw UUID in companies.fbr_sandbox_token).
 *
 * Locks the centralized DI token resolver (FbrService::resolveDiToken):
 *   - Encrypted tokens decrypt as before
 *   - A plausible RAW token (30–64 chars, no Crypt envelope) is used as-is
 *   - A corrupted/undecryptable blob yields '' (never sent as a bearer token)
 *     and flips fbr_connection_status to red
 */
class FbrRawTokenResolutionTest extends TestCase
{
    private FbrService $svc;

    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropAllTables();

        Schema::create('companies', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->string('fbr_environment')->nullable();
            $t->text('fbr_sandbox_token')->nullable();
            $t->text('fbr_production_token')->nullable();
            $t->text('fbr_pos_token')->nullable();
            $t->string('fbr_connection_status')->nullable();
            $t->softDeletes();
            $t->timestamps();
        });

        $this->svc = new FbrService();
    }

    private function makeCompany(array $attrs): Company
    {
        return Company::forceCreate(array_merge(['name' => 'Test Co'], $attrs));
    }

    private function resolve(Company $company, ?string $env = null): string
    {
        return $this->svc->resolveDiToken($company, $env);
    }

    private function posToken(Company $company): string
    {
        $rm = new \ReflectionMethod($this->svc, 'getFbrPosToken');
        $rm->setAccessible(true);
        return $rm->invoke($this->svc, $company);
    }

    public function test_encrypted_sandbox_token_still_decrypts(): void
    {
        $company = $this->makeCompany([
            'fbr_environment' => 'sandbox',
            'fbr_sandbox_token' => Crypt::encryptString('secret-sandbox-token-123'),
        ]);

        $this->assertSame('secret-sandbox-token-123', $this->resolve($company));
    }

    public function test_raw_uuid_sandbox_token_is_used_as_is(): void
    {
        $raw = 'e4a1b2c3-d4e5-6789-abcd-ef0123456789'; // 36-char UUID
        $company = $this->makeCompany([
            'fbr_environment' => 'sandbox',
            'fbr_sandbox_token' => $raw,
        ]);

        $this->assertSame($raw, $this->resolve($company));
        $this->assertNotSame('red', $company->fresh()->fbr_connection_status);
    }

    public function test_raw_production_token_is_used_as_is(): void
    {
        $raw = str_repeat('a1b2c3d4', 5); // 40 chars, no envelope
        $company = $this->makeCompany([
            'fbr_environment' => 'production',
            'fbr_production_token' => $raw,
        ]);

        $this->assertSame($raw, $this->resolve($company));
        // Explicit env argument also works (settings test endpoints use it)
        $this->assertSame($raw, $this->resolve($company, 'production'));
    }

    public function test_raw_token_with_whitespace_is_trimmed(): void
    {
        $raw = 'e4a1b2c3-d4e5-6789-abcd-ef0123456789';
        $company = $this->makeCompany([
            'fbr_environment' => 'sandbox',
            'fbr_sandbox_token' => "  {$raw}\n",
        ]);

        $this->assertSame($raw, $this->resolve($company));
    }

    public function test_corrupted_blob_yields_empty_and_flags_red(): void
    {
        // Looks like a Crypt payload (eyJ..., >64 chars) but is undecryptable.
        $corrupt = 'eyJ' . str_repeat('Zz9', 80);
        $company = $this->makeCompany([
            'fbr_environment' => 'sandbox',
            'fbr_sandbox_token' => $corrupt,
        ]);

        $this->assertSame('', $this->resolve($company));
        $this->assertSame('red', $company->fresh()->fbr_connection_status);
    }

    public function test_short_garbage_value_is_not_treated_as_raw_token(): void
    {
        // Too short to be a plausible bearer token → rejected, never sent.
        $company = $this->makeCompany([
            'fbr_environment' => 'sandbox',
            'fbr_sandbox_token' => 'short-junk',
        ]);

        $this->assertSame('', $this->resolve($company));
        $this->assertSame('red', $company->fresh()->fbr_connection_status);
    }

    public function test_empty_token_yields_empty_without_flagging(): void
    {
        $company = $this->makeCompany(['fbr_environment' => 'sandbox']);

        $this->assertSame('', $this->resolve($company));
        $this->assertNull($company->fresh()->fbr_connection_status);
    }

    public function test_encrypted_pos_token_still_decrypts(): void
    {
        $company = $this->makeCompany([
            'fbr_pos_token' => Crypt::encryptString('pos-token-xyz'),
        ]);

        $this->assertSame('pos-token-xyz', $this->posToken($company));
    }

    public function test_raw_pos_token_is_used_as_is(): void
    {
        $raw = 'f0e1d2c3-b4a5-6789-0123-456789abcdef';
        $company = $this->makeCompany(['fbr_pos_token' => $raw]);

        $this->assertSame($raw, $this->posToken($company));
    }

    public function test_corrupted_pos_blob_yields_empty(): void
    {
        $company = $this->makeCompany([
            'fbr_pos_token' => 'eyJ' . str_repeat('Xy7', 90),
        ]);

        $this->assertSame('', $this->posToken($company));
    }
}
