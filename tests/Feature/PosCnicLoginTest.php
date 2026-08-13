<?php

namespace Tests\Feature;

use App\Services\LoginIdentifierResolver;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

/**
 * Owner CNIC self-serve login (Task 579).
 *
 * Owners can now set the company CNIC from the POS/FBR Business Profile
 * pages (previously saas-admin only). This locks the whole promise:
 *
 *   1. CNIC stored plain-digits → login works dashed AND plain (both panels).
 *   2. LEGACY dashed-stored CNIC (old admin-set rows) still logs in — the
 *      lookup digit-compares via REPLACE.
 *   3. Same CNIC on a POS and an FBR company (legacy dupes) → each panel
 *      logs into ITS OWN company, never the other product's row.
 *   4. cnicRules() = shared write-path truth: 13 digits, dash-tolerant,
 *      globally unique (dashed-stored dupes detected too).
 *   5. normalizeCnic() stores plain digits, empty clears to NULL.
 *
 * Pattern: minimal schema + HTTP POST, same as PosUsernameLoginTest.
 */
class PosCnicLoginTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropAllTables();

        Schema::create('companies', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('ntn')->nullable();
            $table->string('cnic')->nullable();
            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            $table->string('product_type')->nullable();
            $table->boolean('fbr_pos_enabled')->default(false);
            $table->boolean('restaurant_mode')->default(false);
            $table->string('company_status')->default('approved');
            $table->softDeletes();
            $table->timestamps();
        });

        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('phone')->nullable();
            $table->string('username', 100)->nullable()->unique();
            $table->string('password');
            $table->unsignedBigInteger('company_id')->nullable();
            $table->string('role')->nullable();
            $table->string('pos_role')->nullable();
            $table->boolean('is_active')->default(true);
            $table->boolean('dark_mode')->default(false);
            $table->rememberToken();
            $table->timestamps();
        });

        Schema::create('admin_users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password');
            $table->string('role')->default('super_admin');
            $table->rememberToken();
            $table->timestamps();
        });

        // Side-effect table (SecurityLogService writes during login flow)
        Schema::create('security_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('action');
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->text('metadata')->nullable();
            $table->timestamp('created_at')->nullable();
        });
    }

    private function seedOwner(string $productType, ?string $cnic, string $email, string $password = 'Owner@12345'): int
    {
        $companyId = DB::table('companies')->insertGetId([
            'name' => strtoupper($productType) . ' Co',
            'product_type' => $productType,
            'cnic' => $cnic,
            'fbr_pos_enabled' => $productType === 'fbrpos',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('users')->insert([
            'name' => 'Owner', 'email' => $email,
            'password' => Hash::make($password),
            'company_id' => $companyId, 'role' => 'company_admin', 'pos_role' => 'pos_admin',
            'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return $companyId;
    }

    /** 1a. Plain-digit CNIC (the normalized storage form) logs into POS. */
    public function test_pos_cnic_login_plain(): void
    {
        $this->seedOwner('pos', '3520212345671', 'posowner@taxnest.test');

        $response = $this->post('/pos/login', [
            'login' => '3520212345671',
            'password' => 'Owner@12345',
        ]);

        $response->assertStatus(302);
        $this->assertTrue(auth('pos')->check(), 'POS guard must authenticate via plain CNIC');
        $this->assertSame('posowner@taxnest.test', auth('pos')->user()->email);
    }

    /** 1b. Dashed CNIC input matches the plain-digit stored value. */
    public function test_pos_cnic_login_dashed(): void
    {
        $this->seedOwner('pos', '3520212345671', 'posowner@taxnest.test');

        $this->post('/pos/login', [
            'login' => '35202-1234567-1',
            'password' => 'Owner@12345',
        ])->assertStatus(302);

        $this->assertTrue(auth('pos')->check(), 'POS guard must authenticate via dashed CNIC');
    }

    /** 1c. FBR POS panel: dashed CNIC login works the same way. */
    public function test_fbr_cnic_login_dashed(): void
    {
        $this->seedOwner('fbrpos', '3620298765432', 'fbrowner@taxnest.test');

        $this->post('/fbr-pos/login', [
            'login' => '36202-9876543-2',
            'password' => 'Owner@12345',
        ])->assertStatus(302);

        $this->assertTrue(auth('fbrpos')->check(), 'FBR POS guard must authenticate via dashed CNIC');
        $this->assertSame('fbrowner@taxnest.test', auth('fbrpos')->user()->email);
    }

    /** 2. LEGACY dashed-STORED CNIC (old admin-set rows) still resolves. */
    public function test_legacy_dashed_stored_cnic_still_logs_in(): void
    {
        $this->seedOwner('pos', '35202-1234567-1', 'legacy@taxnest.test');

        // Plain-digit input → REPLACE digit-compare finds the dashed row.
        $this->post('/pos/login', [
            'login' => '3520212345671',
            'password' => 'Owner@12345',
        ])->assertStatus(302);

        $this->assertTrue(auth('pos')->check(), 'Dashed-stored legacy CNIC must match plain input');
        $this->assertSame('legacy@taxnest.test', auth('pos')->user()->email);
    }

    /**
     * 3. Same CNIC on a POS company AND an FBR company (legacy dupes):
     *    each panel must log into ITS OWN product's company.
     */
    public function test_duplicate_cnic_across_panels_resolves_panel_first(): void
    {
        // Seed FBR company FIRST so a naive ->first() would pick the wrong row
        // for the POS panel.
        $this->seedOwner('fbrpos', '3410112345675', 'fbrdupe@taxnest.test');
        $this->seedOwner('pos', '3410112345675', 'posdupe@taxnest.test');

        $this->post('/pos/login', [
            'login' => '3410112345675',
            'password' => 'Owner@12345',
        ])->assertStatus(302);
        $this->assertTrue(auth('pos')->check(), 'POS panel must resolve the POS company');
        $this->assertSame('posdupe@taxnest.test', auth('pos')->user()->email);

        auth('pos')->logout();

        $this->post('/fbr-pos/login', [
            'login' => '34101-1234567-5',
            'password' => 'Owner@12345',
        ])->assertStatus(302);
        $this->assertTrue(auth('fbrpos')->check(), 'FBR panel must resolve the FBR company');
        $this->assertSame('fbrdupe@taxnest.test', auth('fbrpos')->user()->email);
    }

    /** 4a. Write-path rules: a CNIC already on ANOTHER company is rejected. */
    public function test_duplicate_cnic_rejected_on_save(): void
    {
        $existingId = $this->seedOwner('pos', '3520212345671', 'first@taxnest.test');

        // Another company trying to claim the same CNIC — dashed input too.
        foreach (['3520212345671', '35202-1234567-1'] as $attempt) {
            $v = Validator::make(
                ['cnic' => $attempt],
                ['cnic' => LoginIdentifierResolver::cnicRules()],
                LoginIdentifierResolver::cnicMessages()
            );
            $this->assertTrue($v->fails(), "Duplicate CNIC [$attempt] must be rejected");
        }

        // The OWNING company may re-save its own CNIC (edit form round-trip).
        $own = Validator::make(
            ['cnic' => '35202-1234567-1'],
            ['cnic' => LoginIdentifierResolver::cnicRules($existingId)],
            LoginIdentifierResolver::cnicMessages()
        );
        $this->assertFalse($own->fails(), 'Own company must be exempt from the dupe check');
    }

    /** 4b. Dashed-STORED legacy dupe is detected via the digit-compare. */
    public function test_dashed_stored_duplicate_detected(): void
    {
        $this->seedOwner('pos', '35202-1234567-1', 'dashedstore@taxnest.test');

        $v = Validator::make(
            ['cnic' => '3520212345671'],
            ['cnic' => LoginIdentifierResolver::cnicRules()],
            LoginIdentifierResolver::cnicMessages()
        );
        $this->assertTrue($v->fails(), 'Plain input must collide with dashed-stored CNIC');
    }

    /** 4c. Format guard: must be exactly 13 digits, digits/dashes/spaces only. */
    public function test_cnic_format_validation(): void
    {
        foreach (['123456789012', '12345678901234', '35202-ABCDEFG-1'] as $bad) {
            $v = Validator::make(
                ['cnic' => $bad],
                ['cnic' => LoginIdentifierResolver::cnicRules()],
                LoginIdentifierResolver::cnicMessages()
            );
            $this->assertTrue($v->fails(), "Malformed CNIC [$bad] must be rejected");
        }

        $good = Validator::make(
            ['cnic' => '35202-1234567-1'],
            ['cnic' => LoginIdentifierResolver::cnicRules()],
            LoginIdentifierResolver::cnicMessages()
        );
        $this->assertFalse($good->fails(), 'Well-formed dashed CNIC must pass');
    }

    /** 5. Normalizer: dashed → plain digits, empty → NULL (clear). */
    public function test_normalize_cnic(): void
    {
        $this->assertSame('3520212345671', LoginIdentifierResolver::normalizeCnic('35202-1234567-1'));
        $this->assertSame('3520212345671', LoginIdentifierResolver::normalizeCnic('3520212345671'));
        $this->assertNull(LoginIdentifierResolver::normalizeCnic(''));
        $this->assertNull(LoginIdentifierResolver::normalizeCnic(null));
    }

    /** 6. Regression: email login stays untouched by the panel-aware lookup. */
    public function test_email_login_regression(): void
    {
        $this->seedOwner('pos', '3520212345671', 'emailreg@taxnest.test');

        $this->post('/pos/login', [
            'login' => 'emailreg@taxnest.test',
            'password' => 'Owner@12345',
        ])->assertStatus(302);

        $this->assertTrue(auth('pos')->check(), 'Email login must keep working');
    }
}
