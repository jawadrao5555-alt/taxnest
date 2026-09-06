<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\AdminUser;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;

/**
 * SMOKE-LOCK — the remaining AdminCompanyController POSTs must never
 * silently fail ("form does nothing").
 *
 * Companion to AdminCompanyFormPostsSmokeTest (store/update/limits/overrides).
 * This locks:
 *   - POST   /admin/companies/{id}/change-type              (product_type flip)
 *   - POST   /admin/companies/{id}/archive-viewer           (create archive_viewer login)
 *   - PUT    /admin/companies/{id}/archive-viewer/{userId}  (rotate credentials)
 *   - POST   /admin/companies/{id}/local-viewer             (create local_viewer login)
 *   - PUT    /admin/companies/{id}/local-viewer/{userId}    (rotate credentials)
 *   - POST   /admin/bin/{id}/restore                        (soft-delete restore)
 *   - DELETE /admin/bin/{id}/destroy                        (force delete + orphan purge)
 * asserting BOTH the redirect and the resulting database state.
 */
class AdminCompanyTypeViewerBinPostsSmokeTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropAllTables();

        Schema::create('admin_users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password');
            $table->string('role')->default('super_admin');
            $table->rememberToken();
            $table->timestamps();
        });

        Schema::create('admin_audit_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('admin_id')->nullable();
            $table->string('action');
            $table->string('target_type')->nullable();
            $table->unsignedBigInteger('target_id')->nullable();
            $table->text('metadata')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->timestamps();
        });

        Schema::create('companies', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('owner_name')->nullable();
            $table->string('email')->nullable();
            $table->string('product_type')->nullable();
            $table->string('status')->default('approved');
            $table->string('company_status')->default('active');
            $table->string('deleted_reason')->nullable();
            $table->softDeletes();
            $table->timestamps();
        });

        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password');
            $table->unsignedBigInteger('company_id')->nullable();
            $table->string('role')->nullable();
            $table->string('pos_role')->nullable();
            $table->boolean('is_active')->default(true);
            $table->rememberToken();
            $table->timestamps();
        });

        // Orphan-purge tables covered by forceDelete()'s purge list. Only a
        // representative subset — the controller guards every table with
        // Schema::hasTable/hasColumn, so missing ones are skipped safely.
        Schema::create('pos_deals', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->string('name')->nullable();
            $table->timestamps();
        });
        Schema::create('pos_deal_items', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('deal_id');
            $table->string('name')->nullable();
            $table->timestamps();
        });
        Schema::create('pos_riders', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->string('name')->nullable();
            $table->timestamps();
        });
        // Ledger table deliberately EXCLUDED from the purge — must survive.
        Schema::create('registered_credentials', function (Blueprint $table) {
            $table->id();
            $table->string('credential_type', 20);
            $table->string('credential_value', 191);
            $table->unsignedBigInteger('company_id')->nullable();
            $table->timestamp('created_at')->nullable();
        });

        DB::table('admin_users')->insert([
            'name' => 'Smoke Admin',
            'email' => 'smoke-admin@taxnest.test',
            'password' => Hash::make('Smoke@12345'),
            'role' => 'super_admin',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function actingAsAdmin(): self
    {
        return $this->actingAs(AdminUser::first(), 'admin');
    }

    private function makeCompany(array $overrides = []): int
    {
        return DB::table('companies')->insertGetId(array_merge([
            'name' => 'Type Co',
            'owner_name' => 'Owner',
            'product_type' => 'pos',
            'status' => 'approved',
            'company_status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));
    }

    private function makeViewer(int $companyId, string $posRole, array $overrides = []): int
    {
        return DB::table('users')->insertGetId(array_merge([
            'name' => 'Viewer',
            'email' => $posRole . '-viewer@taxnest.test',
            'password' => Hash::make('OldPass@123'),
            'company_id' => $companyId,
            'role' => 'employee',
            'pos_role' => $posRole,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));
    }

    // ── Change product type ──────────────────────────────────────────────

    public function test_change_type_flips_product_type_and_audits(): void
    {
        $id = $this->makeCompany(['product_type' => 'di']);

        $response = $this->actingAsAdmin()
            ->from("/admin/companies/{$id}")
            ->post("/admin/companies/{$id}/change-type", ['product_type' => 'pos']);

        $response->assertRedirect("/admin/companies/{$id}");
        $response->assertSessionHas('success');
        $response->assertSessionMissing('errors');

        $this->assertSame('pos', DB::table('companies')->where('id', $id)->value('product_type'));
        $this->assertDatabaseHas('admin_audit_logs', [
            'action' => 'Company type changed',
            'target_id' => $id,
        ]);
    }

    public function test_change_type_invalid_value_bounces_and_leaves_type_unchanged(): void
    {
        $id = $this->makeCompany(['product_type' => 'di']);

        $response = $this->actingAsAdmin()
            ->from("/admin/companies/{$id}")
            ->post("/admin/companies/{$id}/change-type", ['product_type' => 'shop']);

        $response->assertRedirect("/admin/companies/{$id}");
        $response->assertSessionHasErrors(['product_type']);
        $this->assertSame('di', DB::table('companies')->where('id', $id)->value('product_type'));
    }

    // ── Archive Viewer store/update ──────────────────────────────────────

    public function test_archive_viewer_store_creates_read_only_login(): void
    {
        $id = $this->makeCompany();

        $response = $this->actingAsAdmin()
            ->from("/admin/companies/{$id}")
            ->post("/admin/companies/{$id}/archive-viewer", [
                'name' => 'Archive Eyes',
                'email' => 'archive-eyes@taxnest.test',
                'password' => 'Secret@123',
            ]);

        $response->assertRedirect("/admin/companies/{$id}");
        $response->assertSessionHas('success');
        $response->assertSessionMissing('errors');

        $user = DB::table('users')->where('email', 'archive-eyes@taxnest.test')->first();
        $this->assertNotNull($user, 'Archive viewer user must be created');
        $this->assertEquals($id, $user->company_id);
        $this->assertSame('employee', $user->role);
        $this->assertSame('archive_viewer', $user->pos_role);
        $this->assertEquals(1, (int) $user->is_active);
        $this->assertTrue(Hash::check('Secret@123', $user->password));
    }

    public function test_archive_viewer_store_invalid_data_bounces_without_creating_user(): void
    {
        $id = $this->makeCompany();

        $response = $this->actingAsAdmin()
            ->from("/admin/companies/{$id}")
            ->post("/admin/companies/{$id}/archive-viewer", [
                'name' => '',
                'email' => 'not-an-email',
                'password' => 'short',   // < 8
            ]);

        $response->assertRedirect("/admin/companies/{$id}");
        $response->assertSessionHasErrors(['name', 'email', 'password']);
        $this->assertSame(0, DB::table('users')->count());
    }

    public function test_archive_viewer_store_rejected_for_non_pos_company(): void
    {
        $id = $this->makeCompany(['product_type' => 'di']);

        $response = $this->actingAsAdmin()
            ->from("/admin/companies/{$id}")
            ->post("/admin/companies/{$id}/archive-viewer", [
                'name' => 'Archive Eyes',
                'email' => 'archive-eyes@taxnest.test',
                'password' => 'Secret@123',
            ]);

        $response->assertRedirect("/admin/companies/{$id}");
        $response->assertSessionHas('error');
        $this->assertSame(0, DB::table('users')->count());
    }

    public function test_archive_viewer_update_rotates_credentials(): void
    {
        $id = $this->makeCompany();
        $userId = $this->makeViewer($id, 'archive_viewer');

        $response = $this->actingAsAdmin()
            ->from("/admin/companies/{$id}")
            ->put("/admin/companies/{$id}/archive-viewer/{$userId}", [
                'name' => 'New Name',
                'email' => 'new-archive@taxnest.test',
                'password' => 'NewPass@123',
            ]);

        $response->assertRedirect("/admin/companies/{$id}");
        $response->assertSessionHas('success');
        $response->assertSessionMissing('errors');

        $user = DB::table('users')->where('id', $userId)->first();
        $this->assertSame('New Name', $user->name);
        $this->assertSame('new-archive@taxnest.test', $user->email);
        $this->assertTrue(Hash::check('NewPass@123', $user->password));
        $this->assertSame('archive_viewer', $user->pos_role, 'pos_role must never change on update');
    }

    public function test_archive_viewer_update_without_password_keeps_old_password(): void
    {
        $id = $this->makeCompany();
        $userId = $this->makeViewer($id, 'archive_viewer');

        $this->actingAsAdmin()
            ->put("/admin/companies/{$id}/archive-viewer/{$userId}", [
                'name' => 'Same Viewer',
                'email' => 'archive_viewer-viewer@taxnest.test',
            ])
            ->assertSessionHas('success')
            ->assertSessionMissing('errors');

        $this->assertTrue(Hash::check('OldPass@123', DB::table('users')->where('id', $userId)->value('password')));
    }

    public function test_archive_viewer_update_invalid_data_bounces_and_leaves_user_unchanged(): void
    {
        $id = $this->makeCompany();
        $userId = $this->makeViewer($id, 'archive_viewer');

        $response = $this->actingAsAdmin()
            ->from("/admin/companies/{$id}")
            ->put("/admin/companies/{$id}/archive-viewer/{$userId}", [
                'name' => '',
                'email' => 'bad',
                'password' => 'short',
            ]);

        $response->assertRedirect("/admin/companies/{$id}");
        $response->assertSessionHasErrors(['name', 'email', 'password']);
        $this->assertSame('archive_viewer-viewer@taxnest.test', DB::table('users')->where('id', $userId)->value('email'));
    }

    public function test_archive_viewer_update_404s_for_user_of_other_role_or_company(): void
    {
        $id = $this->makeCompany();
        $otherId = $this->makeCompany(['name' => 'Other Co']);
        $localViewer = $this->makeViewer($id, 'local_viewer', ['email' => 'lv@taxnest.test']);
        $foreignViewer = $this->makeViewer($otherId, 'archive_viewer', ['email' => 'fv@taxnest.test']);

        // Admin panel converts not-found into a dashboard redirect + error flash
        // (bootstrap/app.php panel-isolated NotFoundHttpException renderable).
        // Wrong pos_role under the right company.
        $this->actingAsAdmin()
            ->put("/admin/companies/{$id}/archive-viewer/{$localViewer}", [
                'name' => 'X', 'email' => 'x@taxnest.test',
            ])->assertRedirect('/admin/dashboard')->assertSessionHas('error');

        // Right pos_role under the wrong company.
        $this->actingAsAdmin()
            ->put("/admin/companies/{$id}/archive-viewer/{$foreignViewer}", [
                'name' => 'X', 'email' => 'x2@taxnest.test',
            ])->assertRedirect('/admin/dashboard')->assertSessionHas('error');

        $this->assertSame('lv@taxnest.test', DB::table('users')->where('id', $localViewer)->value('email'));
        $this->assertSame('fv@taxnest.test', DB::table('users')->where('id', $foreignViewer)->value('email'));
    }

    // ── Local Bills Viewer store/update ──────────────────────────────────

    public function test_local_viewer_store_creates_read_only_login(): void
    {
        $id = $this->makeCompany();

        $response = $this->actingAsAdmin()
            ->from("/admin/companies/{$id}")
            ->post("/admin/companies/{$id}/local-viewer", [
                'name' => 'Local Eyes',
                'email' => 'local-eyes@taxnest.test',
                'password' => 'Secret@123',
            ]);

        $response->assertRedirect("/admin/companies/{$id}");
        $response->assertSessionHas('success');
        $response->assertSessionMissing('errors');

        $user = DB::table('users')->where('email', 'local-eyes@taxnest.test')->first();
        $this->assertNotNull($user, 'Local viewer user must be created');
        $this->assertEquals($id, $user->company_id);
        $this->assertSame('employee', $user->role);
        $this->assertSame('local_viewer', $user->pos_role);
        $this->assertTrue(Hash::check('Secret@123', $user->password));
    }

    public function test_local_viewer_store_invalid_data_bounces_without_creating_user(): void
    {
        $id = $this->makeCompany();

        $response = $this->actingAsAdmin()
            ->from("/admin/companies/{$id}")
            ->post("/admin/companies/{$id}/local-viewer", [
                'name' => 'Local Eyes',
                'email' => 'not-an-email',
                'password' => '',
            ]);

        $response->assertRedirect("/admin/companies/{$id}");
        $response->assertSessionHasErrors(['email', 'password']);
        $this->assertSame(0, DB::table('users')->count());
    }

    public function test_local_viewer_store_rejected_for_non_pos_company(): void
    {
        $id = $this->makeCompany(['product_type' => 'fbrpos']);

        $this->actingAsAdmin()
            ->from("/admin/companies/{$id}")
            ->post("/admin/companies/{$id}/local-viewer", [
                'name' => 'Local Eyes',
                'email' => 'local-eyes@taxnest.test',
                'password' => 'Secret@123',
            ])
            ->assertRedirect("/admin/companies/{$id}")
            ->assertSessionHas('error');

        $this->assertSame(0, DB::table('users')->count());
    }

    public function test_local_viewer_update_rotates_credentials(): void
    {
        $id = $this->makeCompany();
        $userId = $this->makeViewer($id, 'local_viewer');

        $response = $this->actingAsAdmin()
            ->from("/admin/companies/{$id}")
            ->put("/admin/companies/{$id}/local-viewer/{$userId}", [
                'name' => 'New Local',
                'email' => 'new-local@taxnest.test',
                'password' => 'NewPass@123',
            ]);

        $response->assertRedirect("/admin/companies/{$id}");
        $response->assertSessionHas('success');
        $response->assertSessionMissing('errors');

        $user = DB::table('users')->where('id', $userId)->first();
        $this->assertSame('new-local@taxnest.test', $user->email);
        $this->assertTrue(Hash::check('NewPass@123', $user->password));
        $this->assertSame('local_viewer', $user->pos_role);
    }

    public function test_local_viewer_update_invalid_data_bounces_and_leaves_user_unchanged(): void
    {
        $id = $this->makeCompany();
        $userId = $this->makeViewer($id, 'local_viewer');

        $response = $this->actingAsAdmin()
            ->from("/admin/companies/{$id}")
            ->put("/admin/companies/{$id}/local-viewer/{$userId}", [
                'name' => '',
                'email' => 'bad',
            ]);

        $response->assertRedirect("/admin/companies/{$id}");
        $response->assertSessionHasErrors(['name', 'email']);
        $this->assertSame('local_viewer-viewer@taxnest.test', DB::table('users')->where('id', $userId)->value('email'));
    }

    // ── Viewer actions are super-admin only ──────────────────────────────

    public function test_viewer_posts_forbidden_for_non_super_admin(): void
    {
        DB::table('admin_users')->insert([
            'name' => 'Support', 'email' => 'support-admin@taxnest.test',
            'password' => Hash::make('Support@123'), 'role' => 'support',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $id = $this->makeCompany();
        $userId = $this->makeViewer($id, 'archive_viewer');

        $support = AdminUser::where('email', 'support-admin@taxnest.test')->first();
        $payload = ['name' => 'X', 'email' => 'x@taxnest.test', 'password' => 'Secret@123'];

        $this->actingAs($support, 'admin')->post("/admin/companies/{$id}/archive-viewer", $payload)->assertForbidden();
        $this->actingAs($support, 'admin')->put("/admin/companies/{$id}/archive-viewer/{$userId}", $payload)->assertForbidden();
        $this->actingAs($support, 'admin')->post("/admin/companies/{$id}/local-viewer", $payload)->assertForbidden();

        $this->assertSame(1, DB::table('users')->count(), 'No viewer rows may be created or changed');
    }

    // ── Bin: restore / force delete ──────────────────────────────────────

    public function test_restore_clears_deleted_at_and_deleted_reason(): void
    {
        $id = $this->makeCompany([
            'deleted_at' => now(),
            'deleted_reason' => 'Testing bin',
        ]);

        $response = $this->actingAsAdmin()
            ->from('/admin/bin')
            ->post("/admin/bin/{$id}/restore");

        $response->assertRedirect('/admin/bin');
        $response->assertSessionHas('success');

        $row = DB::table('companies')->where('id', $id)->first();
        $this->assertNull($row->deleted_at, 'restore must clear deleted_at');
        $this->assertNull($row->deleted_reason, 'restore must clear deleted_reason');
    }

    public function test_restore_of_company_not_in_bin_flashes_error_not_silently(): void
    {
        $id = $this->makeCompany();

        $this->actingAsAdmin()->post("/admin/bin/{$id}/restore")
            ->assertRedirect('/admin/dashboard')->assertSessionHas('error');

        $this->assertNull(DB::table('companies')->where('id', $id)->value('deleted_at'));
    }

    public function test_force_delete_purges_company_and_orphan_tables_but_keeps_ledger(): void
    {
        // Task 1585: past the 7-day bin hold, so the purge itself is allowed.
        $id = $this->makeCompany(['deleted_at' => now()->subDays(\App\Models\Company::BIN_HOLD_DAYS + 1)]);
        $otherId = $this->makeCompany(['name' => 'Survivor Co']);

        $dealId = DB::table('pos_deals')->insertGetId(['company_id' => $id, 'name' => 'Deal', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('pos_deal_items')->insert(['deal_id' => $dealId, 'name' => 'Item', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('pos_riders')->insert(['company_id' => $id, 'name' => 'Rider', 'created_at' => now(), 'updated_at' => now()]);
        // Other company's rows must survive.
        $otherDeal = DB::table('pos_deals')->insertGetId(['company_id' => $otherId, 'name' => 'Keep Deal', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('pos_deal_items')->insert(['deal_id' => $otherDeal, 'name' => 'Keep Item', 'created_at' => now(), 'updated_at' => now()]);
        // Ledger row is exempt from the purge.
        DB::table('registered_credentials')->insert([
            'credential_type' => 'email', 'credential_value' => 'ledger@taxnest.test',
            'company_id' => $id, 'created_at' => now(),
        ]);

        $response = $this->actingAsAdmin()
            ->from('/admin/bin')
            ->delete("/admin/bin/{$id}/destroy");

        $response->assertRedirect(route('saas.admin.companies.bin'));
        $response->assertSessionHas('success');

        $this->assertSame(0, DB::table('companies')->where('id', $id)->count(), 'Company row must be hard-deleted');
        $this->assertSame(0, DB::table('pos_deals')->where('company_id', $id)->count());
        $this->assertSame(0, DB::table('pos_deal_items')->where('deal_id', $dealId)->count());
        $this->assertSame(0, DB::table('pos_riders')->where('company_id', $id)->count());

        // Other company untouched; anti-reuse ledger survives.
        $this->assertSame(1, DB::table('pos_deals')->where('company_id', $otherId)->count());
        $this->assertSame(1, DB::table('pos_deal_items')->where('deal_id', $otherDeal)->count());
        $this->assertSame(1, DB::table('registered_credentials')->where('company_id', $id)->count());
    }

    public function test_force_delete_of_company_not_in_bin_flashes_error_not_silently(): void
    {
        $id = $this->makeCompany();

        $this->actingAsAdmin()->delete("/admin/bin/{$id}/destroy")
            ->assertRedirect('/admin/dashboard')->assertSessionHas('error');

        $this->assertSame(1, DB::table('companies')->where('id', $id)->count());
    }

    // ── Task 1585: bin holding period before a permanent delete ──────────

    public function test_force_delete_inside_hold_is_refused_and_audited(): void
    {
        $id = $this->makeCompany(['name' => 'Fresh Bin Co', 'deleted_at' => now()->subDay()]);

        $this->actingAsAdmin()->from('/admin/bin')
            ->delete("/admin/bin/{$id}/destroy")
            ->assertRedirect('/admin/bin')
            ->assertSessionHas('error');

        $this->assertSame(1, DB::table('companies')->where('id', $id)->count(),
            'a company inside the bin hold must survive the purge attempt');
        $this->assertSame(1, DB::table('admin_audit_logs')
            ->where('action', 'Permanent delete refused (bin hold)')->where('target_id', $id)->count(),
            'the refusal must be audited');
    }

    public function test_wrong_typed_name_does_not_override_the_hold(): void
    {
        $id = $this->makeCompany(['name' => 'Fresh Bin Co', 'deleted_at' => now()->subDay()]);

        $this->actingAsAdmin()->from('/admin/bin')
            ->delete("/admin/bin/{$id}/destroy", ['confirm_name' => 'fresh bin co'])
            ->assertSessionHas('error');

        $this->assertSame(1, DB::table('companies')->where('id', $id)->count(),
            'the override name must match EXACTLY');
    }

    public function test_super_admin_overrides_the_hold_with_the_exact_name(): void
    {
        $id = $this->makeCompany(['name' => 'Fresh Bin Co', 'deleted_at' => now()->subDay()]);

        $this->actingAsAdmin()->from('/admin/bin')
            ->delete("/admin/bin/{$id}/destroy", ['confirm_name' => 'Fresh Bin Co'])
            ->assertSessionHas('success');

        $this->assertSame(0, DB::table('companies')->where('id', $id)->count());
        $this->assertSame(1, DB::table('admin_audit_logs')
            ->where('action', 'Permanent delete override (bin hold bypassed)')->where('target_id', $id)->count(),
            'the override must be audited');
    }

    public function test_non_super_admin_cannot_override_the_hold(): void
    {
        $id = $this->makeCompany(['name' => 'Fresh Bin Co', 'deleted_at' => now()->subDay()]);
        $staff = AdminUser::create([
            'name' => 'Staff', 'email' => 'staff.hold@taxnest.test',
            'password' => Hash::make('Secret@12345'), 'role' => 'admin',
        ]);

        $this->actingAs($staff, 'admin')->from('/admin/bin')
            ->delete("/admin/bin/{$id}/destroy", ['confirm_name' => 'Fresh Bin Co'])
            ->assertSessionHas('error');

        $this->assertSame(1, DB::table('companies')->where('id', $id)->count());
    }

    // ── Guests never reach these POSTs ───────────────────────────────────

    public function test_guests_are_redirected_from_all_covered_posts(): void
    {
        $id = $this->makeCompany(['product_type' => 'di']);
        $binId = $this->makeCompany(['name' => 'Binned', 'deleted_at' => now()]);
        $userId = $this->makeViewer($id, 'archive_viewer');

        $this->post("/admin/companies/{$id}/change-type", ['product_type' => 'pos'])->assertRedirect('/admin/login');
        $this->post("/admin/companies/{$id}/archive-viewer", [])->assertRedirect('/admin/login');
        $this->put("/admin/companies/{$id}/archive-viewer/{$userId}", [])->assertRedirect('/admin/login');
        $this->post("/admin/companies/{$id}/local-viewer", [])->assertRedirect('/admin/login');
        $this->post("/admin/bin/{$binId}/restore")->assertRedirect('/admin/login');
        $this->delete("/admin/bin/{$binId}/destroy")->assertRedirect('/admin/login');

        $this->assertSame('di', DB::table('companies')->where('id', $id)->value('product_type'));
        $this->assertNotNull(DB::table('companies')->where('id', $binId)->value('deleted_at'));
        $this->assertSame(1, DB::table('users')->count());
    }
}
