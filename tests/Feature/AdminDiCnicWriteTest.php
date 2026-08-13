<?php

namespace Tests\Feature;

use App\Models\AdminUser;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * DUPLICATE-CNIC LOCKDOWN — admin + DI write paths (Task 580).
 *
 * Task 579 put the owner-facing CNIC writes (POS/FBR Business Profile,
 * registration) on the shared LoginIdentifierResolver::cnicRules() truth.
 * Two older paths still bypassed it:
 *
 *   1. saas-admin company store/update (AdminCompanyController)
 *   2. DI company settings (CompanySettingsController::updateProfile)
 *
 * This locks both over real HTTP:
 *   - dupe CNIC (plain AND dashed input, dashed-STORED legacy rows too) → rejected
 *   - own company re-saving its own CNIC → allowed (edit round-trip)
 *   - valid dashed CNIC → stored as plain digits
 *   - malformed CNIC (≠13 digits) → rejected
 */
class AdminDiCnicWriteTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropAllTables();
        $this->buildSchema();

        DB::table('admin_users')->insert([
            'name' => 'Super', 'email' => 'super@taxnest.test',
            'password' => Hash::make('Super@12345'), 'role' => 'super_admin',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    // ── fixtures ─────────────────────────────────────────────────────────

    private function actingAsAdmin(): self
    {
        return $this->actingAs(AdminUser::first(), 'admin');
    }

    private function makeCompany(array $overrides = []): int
    {
        return DB::table('companies')->insertGetId(array_merge([
            'name' => 'Co ' . uniqid(),
            'owner_name' => 'Owner',
            'product_type' => 'di',
            'status' => 'approved',
            'company_status' => 'active',
            'created_at' => now(), 'updated_at' => now(),
        ], $overrides));
    }

    private function makeDiAdmin(int $companyId, string $email): User
    {
        return User::create([
            'company_id' => $companyId,
            'name' => 'DI Admin',
            'email' => $email,
            'password' => Hash::make('secret'),
            'role' => 'company_admin',
            'is_active' => true,
        ]);
    }

    private function adminUpdatePayload(array $overrides = []): array
    {
        return array_merge(['name' => 'Edited Co'], $overrides);
    }

    private function diProfilePayload(array $overrides = []): array
    {
        return array_merge(['name' => 'DI Co'], $overrides);
    }

    // ── saas-admin: update ───────────────────────────────────────────────

    public function test_admin_update_rejects_duplicate_cnic(): void
    {
        $this->makeCompany(['cnic' => '3520212345671']);           // owner of the CNIC
        $victim = $this->makeCompany(['cnic' => null]);

        foreach (['3520212345671', '35202-1234567-1'] as $attempt) {
            $this->actingAsAdmin()
                ->from("/admin/companies/{$victim}/edit")
                ->put("/admin/companies/{$victim}", $this->adminUpdatePayload(['cnic' => $attempt]))
                ->assertRedirect("/admin/companies/{$victim}/edit")
                ->assertSessionHasErrors(['cnic']);
        }

        $this->assertNull(DB::table('companies')->find($victim)->cnic, 'dupe CNIC must never be stored');
    }

    public function test_admin_update_detects_dashed_stored_legacy_dupe(): void
    {
        $this->makeCompany(['cnic' => '35202-1234567-1']);          // legacy dashed row
        $victim = $this->makeCompany();

        $this->actingAsAdmin()
            ->from("/admin/companies/{$victim}/edit")
            ->put("/admin/companies/{$victim}", $this->adminUpdatePayload(['cnic' => '3520212345671']))
            ->assertSessionHasErrors(['cnic']);
    }

    public function test_admin_update_own_cnic_resave_allowed_and_normalized(): void
    {
        $id = $this->makeCompany(['cnic' => '3520212345671']);

        $this->actingAsAdmin()
            ->put("/admin/companies/{$id}", $this->adminUpdatePayload(['cnic' => '35202-1234567-1']))
            ->assertSessionMissing('errors')
            ->assertSessionHas('success');

        $this->assertSame('3520212345671', DB::table('companies')->find($id)->cnic);
    }

    public function test_admin_update_saves_dashed_cnic_as_plain_digits(): void
    {
        $id = $this->makeCompany();

        $this->actingAsAdmin()
            ->put("/admin/companies/{$id}", $this->adminUpdatePayload(['cnic' => '36101-7654321-9']))
            ->assertSessionMissing('errors');

        $this->assertSame('3610176543219', DB::table('companies')->find($id)->cnic);
    }

    public function test_admin_update_rejects_malformed_cnic(): void
    {
        $id = $this->makeCompany();

        foreach (['123456789012', '12345678901234', '35202-ABCDEFG-1'] as $bad) {
            $this->actingAsAdmin()
                ->from("/admin/companies/{$id}/edit")
                ->put("/admin/companies/{$id}", $this->adminUpdatePayload(['cnic' => $bad]))
                ->assertSessionHasErrors(['cnic']);
        }
        $this->assertNull(DB::table('companies')->find($id)->cnic);
    }

    // ── saas-admin: store ────────────────────────────────────────────────

    public function test_admin_store_rejects_duplicate_cnic(): void
    {
        $this->makeCompany(['cnic' => '3520212345671']);

        $this->actingAsAdmin()
            ->from('/admin/companies/create')
            ->post('/admin/companies', [
                'name' => 'New Co', 'owner_name' => 'O', 'product_type' => 'di',
                'email' => 'newco@taxnest.test', 'status' => 'approved',
                'cnic' => '35202-1234567-1',
                'admin_name' => 'A', 'admin_email' => 'newco-admin@taxnest.test',
                'admin_password' => 'Secret@123',
            ])
            ->assertSessionHasErrors(['cnic']);

        $this->assertNull(DB::table('companies')->where('name', 'New Co')->first());
    }

    public function test_admin_store_normalizes_cnic_to_digits(): void
    {
        $this->actingAsAdmin()
            ->post('/admin/companies', [
                'name' => 'Fresh Co', 'owner_name' => 'O', 'product_type' => 'di',
                'email' => 'fresh@taxnest.test', 'status' => 'approved',
                'cnic' => '37405-1112223-4',
                'admin_name' => 'A', 'admin_email' => 'fresh-admin@taxnest.test',
                'admin_password' => 'Secret@123',
            ])
            ->assertSessionMissing('errors');

        $this->assertSame('3740511122234', DB::table('companies')->where('name', 'Fresh Co')->value('cnic'));
    }

    // ── DI panel: /company/profile ───────────────────────────────────────

    public function test_di_profile_rejects_duplicate_cnic(): void
    {
        $this->makeCompany(['cnic' => '3520212345671']);            // CNIC owner
        $mine = $this->makeCompany();
        $user = $this->makeDiAdmin($mine, 'diadmin@taxnest.test');

        foreach (['3520212345671', '35202-1234567-1'] as $attempt) {
            $this->actingAs($user)
                ->from('/company/profile')
                ->put('/company/profile', $this->diProfilePayload(['cnic' => $attempt]))
                ->assertRedirect('/company/profile')
                ->assertSessionHasErrors(['cnic']);
        }

        $this->assertNull(DB::table('companies')->find($mine)->cnic, 'DI dupe CNIC must never be stored');
    }

    public function test_di_profile_own_resave_allowed_dashed_saved_as_digits(): void
    {
        $mine = $this->makeCompany(['cnic' => '3610198765432']);
        $user = $this->makeDiAdmin($mine, 'diadmin2@taxnest.test');

        $this->actingAs($user)
            ->put('/company/profile', $this->diProfilePayload(['cnic' => '36101-9876543-2']))
            ->assertSessionMissing('errors')
            ->assertRedirect('/company/profile');

        $this->assertSame('3610198765432', DB::table('companies')->find($mine)->cnic);
    }

    public function test_di_profile_rejects_malformed_cnic(): void
    {
        $mine = $this->makeCompany();
        $user = $this->makeDiAdmin($mine, 'diadmin3@taxnest.test');

        $this->actingAs($user)
            ->from('/company/profile')
            ->put('/company/profile', $this->diProfilePayload(['cnic' => '12345']))
            ->assertSessionHasErrors(['cnic']);

        $this->assertNull(DB::table('companies')->find($mine)->cnic);
    }

    public function test_di_profile_empty_cnic_clears_to_null(): void
    {
        $mine = $this->makeCompany(['cnic' => '3610198765432']);
        $user = $this->makeDiAdmin($mine, 'diadmin4@taxnest.test');

        $this->actingAs($user)
            ->put('/company/profile', $this->diProfilePayload(['cnic' => '']))
            ->assertSessionMissing('errors');

        $this->assertNull(DB::table('companies')->find($mine)->cnic);
    }

    // ── schema (minimal for admin.auth + DI web group middleware) ───────

    private function buildSchema(): void
    {
        Schema::create('admin_users', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->string('email')->unique();
            $t->string('password');
            $t->string('role')->default('super_admin');
            $t->rememberToken();
            $t->timestamps();
        });

        Schema::create('admin_audit_logs', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('admin_id')->nullable();
            $t->string('action');
            $t->string('target_type')->nullable();
            $t->unsignedBigInteger('target_id')->nullable();
            $t->text('metadata')->nullable();
            $t->string('ip_address', 45)->nullable();
            $t->timestamps();
        });

        Schema::create('companies', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->string('owner_name')->nullable();
            $t->string('email')->nullable();
            $t->string('phone')->nullable();
            $t->string('mobile')->nullable();
            $t->string('ntn')->nullable();
            $t->string('cnic')->nullable();
            $t->string('registration_no')->nullable();
            $t->string('address')->nullable();
            $t->string('city')->nullable();
            $t->string('province')->nullable();
            $t->string('business_activity')->nullable();
            $t->string('website')->nullable();
            $t->string('product_type')->nullable();
            $t->string('status')->default('approved');
            $t->string('company_status')->default('active');
            $t->unsignedBigInteger('franchise_id')->nullable();
            $t->decimal('standard_tax_rate', 5, 2)->nullable();
            $t->string('invoice_number_prefix')->nullable();
            $t->string('fbr_environment')->nullable();
            $t->string('fbr_registration_no')->nullable();
            $t->string('fbr_business_name')->nullable();
            $t->boolean('fbr_pos_enabled')->default(false);
            $t->string('fbr_pos_environment')->nullable();
            $t->string('fbr_pos_id')->nullable();
            $t->boolean('fbr_reporting_enabled')->default(false);
            $t->boolean('pra_reporting_enabled')->default(false);
            $t->string('pra_environment')->nullable();
            $t->string('pra_pos_id')->nullable();
            $t->boolean('restaurant_mode')->default(false);
            $t->boolean('onboarding_completed')->default(true);
            $t->boolean('is_internal_account')->default(false);
            $t->softDeletes();
            $t->timestamps();
        });

        Schema::create('users', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->string('email')->unique();
            $t->string('phone')->nullable();
            $t->string('username')->nullable();
            $t->string('password');
            $t->unsignedBigInteger('company_id')->nullable();
            $t->string('role')->nullable();
            $t->string('pos_role')->nullable();
            $t->boolean('is_active')->default(true);
            $t->boolean('dark_mode')->default(false);
            $t->rememberToken();
            $t->timestamps();
        });

        Schema::create('franchises', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->string('email')->unique();
            $t->string('status')->default('active');
            $t->timestamps();
        });

        Schema::create('pricing_plans', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->string('product_type')->default('di');
            $t->decimal('price', 12, 2)->default(0);
            $t->boolean('is_trial')->default(false);
            $t->timestamps();
        });

        Schema::create('subscriptions', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('company_id');
            $t->unsignedBigInteger('pricing_plan_id')->nullable();
            $t->boolean('active')->default(true);
            $t->date('start_date')->nullable();
            $t->date('end_date')->nullable();
            $t->timestamp('trial_ends_at')->nullable();
            $t->string('override_type')->default('none');
            $t->timestamp('override_until')->nullable();
            $t->timestamp('override_granted_at')->nullable();
            $t->integer('free_invoice_limit')->nullable();
            $t->timestamps();
        });

        Schema::create('registered_credentials', function (Blueprint $t) {
            $t->id();
            $t->string('credential_type', 20);
            $t->string('credential_value', 191);
            $t->string('product_type', 20)->nullable();
            $t->unsignedBigInteger('company_id')->nullable();
            $t->timestamp('created_at')->nullable();
            $t->unique(['credential_type', 'credential_value']);
        });

        Schema::create('security_logs', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('user_id')->nullable();
            $t->string('action');
            $t->string('ip_address', 45)->nullable();
            $t->text('user_agent')->nullable();
            $t->text('metadata')->nullable();
            $t->timestamp('created_at')->nullable();
        });
    }
}
