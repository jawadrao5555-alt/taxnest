<?php

namespace Tests\Feature;

use App\Models\AdminUser;
use App\Models\Company;
use App\Services\PosFeatureService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class AdminCategoryExtrasTest extends TestCase
{
    private int $companyId;
    private AdminUser $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Schema::dropAllTables();
        $this->buildSchema();
        PosFeatureService::assumeExtrasColumn(true);
        $this->seedShop();
    }

    protected function tearDown(): void
    {
        PosFeatureService::assumeExtrasColumn(null);
        parent::tearDown();
    }

    public function test_admin_can_grant_and_revoke_an_extra_with_a_reason(): void
    {
        $this->actingAs($this->admin, 'admin');

        $this->post($this->url('/extras'), [
            'module' => 'inventory',
            'reason' => 'Uses retail stock alongside salon services',
        ])->assertRedirect();

        $company = $this->company();
        $this->assertTrue(PosFeatureService::moduleRelevant($company, 'inventory'));
        $this->get($this->url())
            ->assertOk()
            ->assertSee('Inventory')
            ->assertSee('Uses retail stock alongside salon services');

        $this->post($this->url('/extras/inventory/revoke'))->assertRedirect();

        $this->assertFalse(PosFeatureService::moduleRelevant($this->company(), 'inventory'));
        $this->get($this->url())
            ->assertOk()
            ->assertDontSee('Uses retail stock alongside salon services');
    }

    public function test_category_change_drops_an_extra_now_covered_by_the_profile(): void
    {
        PosFeatureService::grantExtra(
            $this->company(),
            'inventory',
            'admin',
            'Temporary stock exception',
            $this->admin->email
        );

        $this->actingAs($this->admin, 'admin');
        $this->put($this->url(), [
            'fs_present' => '1',
            'business_category' => 'workshop',
            'feature_flags' => ['inventory' => '1', 'service_jobs' => '1'],
        ])->assertRedirect()->assertSessionHasNoErrors();

        $company = $this->company();
        $this->assertSame('workshop', $company->business_category);
        $this->assertArrayNotHasKey('inventory', PosFeatureService::extraModules($company));
        $this->assertTrue(PosFeatureService::moduleRelevant($company, 'inventory'));
    }

    private function company(): Company
    {
        return Company::findOrFail($this->companyId);
    }

    private function url(string $suffix = ''): string
    {
        return '/admin/company/' . $this->companyId . '/pos-features' . $suffix;
    }

    private function seedShop(): void
    {
        $this->companyId = DB::table('companies')->insertGetId([
            'name' => 'Category Extras Shop',
            'product_type' => 'pos',
            'status' => 'approved',
            'company_status' => 'active',
            'business_category' => 'salon',
            'feature_flags' => json_encode(['service_jobs' => true]),
            'pos_module_extras' => null,
            'use_universal_pos' => true,
            'pos_ui_density' => 'standard',
            'is_internal_account' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->admin = AdminUser::create([
            'name' => 'Super Admin',
            'email' => 'super@category-extras.test',
            'password' => Hash::make('Admin@98765'),
            'role' => 'super_admin',
        ]);
    }

    private function buildSchema(): void
    {
        Schema::create('admin_users', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->string('email')->unique();
            $t->string('password');
            $t->string('role')->default('super_admin');
            $t->boolean('is_active')->default(true);
            $t->rememberToken();
            $t->timestamps();
        });

        Schema::create('companies', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->string('owner_name')->nullable();
            $t->string('ntn')->nullable();
            $t->string('product_type')->default('pos');
            $t->string('status')->default('approved');
            $t->string('company_status')->default('active');
            $t->boolean('is_internal_account')->default(false);
            $t->text('feature_flags')->nullable();
            $t->text('pos_module_extras')->nullable();
            $t->string('business_category')->nullable();
            $t->boolean('inventory_enabled')->default(false);
            $t->boolean('restaurant_mode')->default(false);
            $t->boolean('use_universal_pos')->default(false);
            $t->string('pos_ui_density')->nullable();
            $t->softDeletes();
            $t->timestamps();
        });

        Schema::create('audit_logs', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('company_id')->nullable();
            $t->unsignedBigInteger('user_id')->nullable();
            $t->string('action');
            $t->string('entity_type')->nullable();
            $t->unsignedBigInteger('entity_id')->nullable();
            $t->text('old_values')->nullable();
            $t->text('new_values')->nullable();
            $t->string('ip_address', 45)->nullable();
            $t->string('sha256_hash')->nullable();
            $t->timestamps();
        });

        Schema::create('security_logs', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('user_id')->nullable();
            $t->string('action');
            $t->string('ip_address', 45)->nullable();
            $t->text('user_agent')->nullable();
            $t->text('metadata')->nullable();
            $t->timestamps();
        });
    }
}