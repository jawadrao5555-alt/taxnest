<?php

namespace Tests\Feature;

use App\Models\AdminUser;
use App\Models\Company;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * The admin per-company POS-features page must not silently wipe a shop's
 * modules (Task 1393).
 *
 * AdminController::updatePosFeatures rebuilt the ENTIRE feature_flags map from
 * checkbox presence (looping PosFeatureService::ALL_FLAGS, writing false for
 * anything the request did not carry) and force-wrote use_universal_pos = true
 * on every save. Unchecked checkboxes send nothing, so an OUTDATED copy of that
 * page — an admin tab left open across a deploy, a replayed POST — was
 * indistinguishable from an admin who deliberately unticked every module. And
 * the blast radius is larger than the shop-facing pages: this endpoint writes
 * ANOTHER company's modules, attributed to the admin in the audit log.
 *
 * Same class of hole the shop pages already closed (PosSettingsStaleFormGuardTest,
 * marker fs_present). This locks the admin page to the same rule: a POST missing
 * the feature block leaves the stored feature_flags (and use_universal_pos)
 * untouched, while a freshly rendered form (carrying fs_present) can still turn
 * modules off.
 *
 * Run:
 *   env -u DATABASE_URL -u DB_CONNECTION -u PGHOST -u PGPORT -u PGUSER \
 *     -u PGPASSWORD -u PGDATABASE APP_ENV=testing DB_CONNECTION=sqlite \
 *     DB_DATABASE=':memory:' CACHE_STORE=array php vendor/bin/phpunit \
 *     tests/Feature/AdminPosFeaturesStaleFormGuardTest.php --testdox
 */
class AdminPosFeaturesStaleFormGuardTest extends TestCase
{
    private int $companyId;
    private AdminUser $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropAllTables();
        $this->buildSchema();
        $this->seedShop();
    }

    private function actAsAdmin(): void
    {
        $this->actingAs($this->admin, 'admin');
    }

    private function company(): Company
    {
        return Company::find($this->companyId);
    }

    private function url(): string
    {
        return '/admin/company/' . $this->companyId . '/pos-features';
    }

    // ── the guard ───────────────────────────────────────────────────────────

    /**
     * A POST carrying no feature block at all (stale tab / replay) must NOT
     * switch every module off, and must not flip use_universal_pos.
     */
    public function test_stale_admin_post_does_not_wipe_the_feature_flag_map(): void
    {
        DB::table('companies')->where('id', $this->companyId)->update([
            'feature_flags'     => json_encode(['kitchen' => true, 'inventory' => true, 'delivery' => true]),
            'use_universal_pos' => false,
            'pos_ui_density'    => 'premium',
        ]);

        $this->actAsAdmin();
        // Outdated copy of the page: business_category only, no feature_flags,
        // no fs_present marker, no use_universal_pos / pos_ui_density.
        $this->put($this->url(), [
            '_token'            => csrf_token(),
            'business_category' => 'restaurant',
        ])->assertRedirect();

        $company = $this->company();
        $flags   = $company->feature_flags ?? [];
        $this->assertTrue((bool) ($flags['kitchen'] ?? false),
            'A form that never carried the feature map must leave kitchen alone');
        $this->assertTrue((bool) ($flags['inventory'] ?? false));
        $this->assertTrue((bool) ($flags['delivery'] ?? false));
        $this->assertFalse((bool) $company->use_universal_pos,
            'use_universal_pos must not be force-flipped on by a marker-less POST');
        $this->assertSame('premium', $company->pos_ui_density,
            'The density radio, absent on an old form, must be left untouched');
    }

    /**
     * The freshly rendered admin form carries fs_present (and its hidden
     * use_universal_pos=1), so it can still turn every module off.
     */
    public function test_fresh_admin_form_can_still_turn_modules_off(): void
    {
        DB::table('companies')->where('id', $this->companyId)->update([
            'feature_flags'     => json_encode(['kitchen' => true, 'inventory' => true]),
            'use_universal_pos' => true,
        ]);

        $this->actAsAdmin();
        $this->put($this->url(), [
            '_token'            => csrf_token(),
            'fs_present'        => '1',
            'use_universal_pos' => '1',
            'business_category' => 'restaurant',
            // feature_flags absent = every module unticked
        ])->assertRedirect();

        $company = $this->company();
        $flags   = $company->feature_flags ?? [];
        $this->assertFalse((bool) ($flags['kitchen'] ?? true),
            'Unticking a module on a freshly rendered admin form must persist');
        $this->assertFalse((bool) ($flags['inventory'] ?? true));
        $this->assertTrue((bool) $company->use_universal_pos,
            'The hidden use_universal_pos=1 keeps the universal screen on across a real save');
    }

    /**
     * Legacy / scripted POST: the block's own field (feature_flags) is proof
     * enough that the block was submitted, even without the marker.
     */
    public function test_legacy_admin_post_carrying_feature_flags_still_rewrites_the_map(): void
    {
        DB::table('companies')->where('id', $this->companyId)->update([
            'feature_flags' => json_encode(['kitchen' => true, 'inventory' => true]),
        ]);

        $this->actAsAdmin();
        $this->put($this->url(), [
            '_token'            => csrf_token(),
            'business_category' => 'restaurant',
            'feature_flags'     => ['inventory' => '1'],
            // kitchen absent = unticked; inventory ticked
        ])->assertRedirect();

        $flags = $this->company()->feature_flags ?? [];
        $this->assertFalse((bool) ($flags['kitchen'] ?? true),
            'A POST that DOES carry feature_flags still rewrites the map, marker or not');
        $this->assertTrue((bool) ($flags['inventory'] ?? false));
    }

    // ── category is super-admin-only (Task 1559) ────────────────────────────

    /**
     * The business category picks the shop's REGULATOR, so only a super admin
     * may change it. A non-super admin's POST that carries one is ignored the
     * same silent way the shop's own handler ignores it: stored category kept,
     * no validation error, and the rest of that admin's feature save lands.
     */
    public function test_non_super_admin_cannot_change_the_business_category(): void
    {
        DB::table('companies')->where('id', $this->companyId)->update([
            'business_category' => 'salon',
            'feature_flags'     => json_encode(['kitchen' => true, 'inventory' => true]),
        ]);

        $support = AdminUser::create([
            'name'     => 'Support Admin',
            'email'    => 'support@adminguard.test',
            'password' => Hash::make('Admin@98765'),
            'role'     => 'support',
        ]);

        $this->actingAs($support, 'admin');
        $this->put($this->url(), [
            '_token'            => csrf_token(),
            'fs_present'        => '1',
            'business_category' => 'gym',
            'feature_flags'     => ['inventory' => '1'],
        ])->assertRedirect()->assertSessionHasNoErrors();

        $company = $this->company();
        $this->assertSame('salon', $company->business_category,
            'A non-super admin must not be able to re-file the shop under another category');

        $flags = $company->feature_flags ?? [];
        $this->assertTrue((bool) ($flags['inventory'] ?? false),
            'The rest of the non-super admin feature save must still succeed');
        $this->assertFalse((bool) ($flags['kitchen'] ?? true),
            'Unticked modules on that same save must still persist');
    }

    /** A super admin still changes the preset exactly as before. */
    public function test_super_admin_can_still_change_the_business_category(): void
    {
        DB::table('companies')->where('id', $this->companyId)->update([
            'business_category' => 'salon',
        ]);

        $this->actAsAdmin();
        $this->put($this->url(), [
            '_token'            => csrf_token(),
            'fs_present'        => '1',
            'business_category' => 'gym',
            'feature_flags'     => ['inventory' => '1'],
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame('gym', $this->company()->business_category);
    }

    // ── helpers ───────────────────────────────────────────────────────────

    private function seedShop(): void
    {
        $this->companyId = DB::table('companies')->insertGetId([
            'name'                => 'Admin Guard Shop',
            'product_type'        => 'pos',
            'status'              => 'approved',
            'company_status'      => 'active',
            'feature_flags'       => json_encode(['kitchen' => true]),
            'use_universal_pos'   => true,
            'pos_ui_density'      => 'standard',
            'is_internal_account' => true,
            'created_at'          => now(),
            'updated_at'          => now(),
        ]);

        $this->admin = AdminUser::create([
            'name'     => 'Super Admin',
            'email'    => 'super@adminguard.test',
            'password' => Hash::make('Admin@98765'),
            'role'     => 'super_admin',
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
            $t->string('business_category')->nullable();
            $t->boolean('inventory_enabled')->default(false);
            $t->boolean('restaurant_mode')->default(false);
            $t->boolean('use_universal_pos')->default(false);
            $t->string('pos_ui_density')->nullable();
            $t->softDeletes();
            $t->timestamps();
        });

        // Side-effect tables written by the audit + security log services.
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
