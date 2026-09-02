<?php

namespace Tests\Feature;

use App\Models\AdminUser;
use App\Models\User;
use App\Services\LocalViewerService;
use App\Services\ViewablePasswordService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * TASK 665 — Local Bills viewer accounts, self-service by the shop OWNER.
 *
 * The read-only local_viewer login used to be super-admin-only; the company
 * owner can now create and manage it himself from /pos/local-bills. Two things
 * must never regress:
 *
 *   1. OWNER-ONLY. The gate is the base role `company_admin`, NOT isPosAdmin()
 *      — that helper counts pos_manager as an admin, and PosAuth deliberately
 *      lets every POS admin (managers included) into the pos/local-bills
 *      prefix. So the manager REACHES these routes and must be stopped by the
 *      controller itself; hiding the Blade section is not the gate.
 *      (Same class of hole as the settings-POST cashier-gate trap.)
 *   2. The owner-viewable password copy stays in step across BOTH panels.
 *      These rows are written from the owner's portal AND from the SaaS admin
 *      company page; a SaaS-side write that skips the copy leaves the owner
 *      reading a password that no longer works.
 */
class PosLocalViewerSelfServiceTest extends TestCase
{
    private int $companyA;
    private int $companyB;
    private User $ownerA;
    private User $managerA;
    private User $cashierA;
    private User $ownerB;
    private AdminUser $superAdmin;

    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropAllTables();

        Schema::create('companies', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('ntn')->nullable();
            $table->string('email')->nullable();
            $table->string('product_type')->nullable();
            $table->string('company_status')->default('active');
            $table->string('status')->default('approved');
            $table->boolean('restaurant_mode')->default(false);
            $table->softDeletes();
            $table->timestamps();
        });

        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('phone')->nullable();
            $table->string('password');
            $table->text('pos_team_password_enc')->nullable();
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
            $table->boolean('is_active')->default(true);
            $table->rememberToken();
            $table->timestamps();
        });

        // Side-effect tables written during these flows.
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id')->nullable();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('action');
            $table->string('entity_type')->nullable();
            $table->unsignedBigInteger('entity_id')->nullable();
            $table->text('old_values')->nullable();
            $table->text('new_values')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('sha256_hash')->nullable();
            $table->timestamps();
        });
        Schema::create('admin_audit_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('admin_id')->nullable();
            $table->string('action');
            $table->string('target_type')->nullable();
            $table->unsignedBigInteger('target_id')->nullable();
            $table->text('metadata')->nullable();
            $table->timestamps();
        });

        // Portal index reads bills + the business day.
        Schema::create('pos_transactions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->string('invoice_number')->nullable();
            $table->string('invoice_mode')->nullable();
            $table->string('pra_status')->nullable();
            $table->string('pra_invoice_number')->nullable();
            $table->string('status')->nullable();
            $table->string('customer_name')->nullable();
            $table->string('customer_phone')->nullable();
            $table->decimal('subtotal', 12, 2)->default(0);
            $table->decimal('discount_amount', 12, 2)->default(0);
            $table->decimal('tax_amount', 12, 2)->default(0);
            $table->decimal('total_amount', 12, 2)->default(0);
            $table->string('payment_method')->nullable();
            $table->date('business_date')->nullable();
            $table->boolean('is_archived')->default(false);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
        });
        Schema::create('pos_transaction_items', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('transaction_id');
            $table->timestamps();
        });
        Schema::create('pos_day_close_reports', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->date('report_date');
            $table->timestamps();
        });

        $this->seedFixtures();
    }

    private function seedFixtures(): void
    {
        $this->companyA = DB::table('companies')->insertGetId([
            'name' => 'Shop A', 'product_type' => 'pos',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->companyB = DB::table('companies')->insertGetId([
            'name' => 'Shop B', 'product_type' => 'pos',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->ownerA = $this->makeUser('Owner A', 'owner-a@t.test', $this->companyA, 'company_admin', 'pos_admin');
        // A manager is a POS ADMIN by isPosAdmin() — that is precisely why the
        // gate must not use it.
        $this->managerA = $this->makeUser('Manager A', 'manager-a@t.test', $this->companyA, 'employee', 'pos_manager');
        $this->cashierA = $this->makeUser('Cashier A', 'cashier-a@t.test', $this->companyA, 'employee', 'pos_cashier');
        $this->ownerB = $this->makeUser('Owner B', 'owner-b@t.test', $this->companyB, 'company_admin', 'pos_admin');

        $this->superAdmin = AdminUser::create([
            'name' => 'Super Admin', 'email' => 'super@t.test',
            'password' => Hash::make('Admin@98765'), 'role' => 'super_admin',
        ]);
    }

    private function makeUser(string $name, string $email, int $companyId, string $role, string $posRole): User
    {
        return User::create([
            'name' => $name, 'email' => $email, 'password' => Hash::make('Secret@12345'),
            'company_id' => $companyId, 'role' => $role, 'pos_role' => $posRole, 'is_active' => true,
        ]);
    }

    /** Create a viewer straight in the DB (starting point for the guard tests). */
    private function seedViewer(int $companyId, string $email = 'viewer@t.test', string $password = 'Viewer@12345'): User
    {
        return User::create(ViewablePasswordService::apply([
            'name' => 'Viewer', 'email' => $email, 'password' => Hash::make($password),
            'company_id' => $companyId, 'role' => 'employee', 'pos_role' => 'local_viewer', 'is_active' => true,
        ], $password));
    }

    // ══════════════════════════════════════════════════════════════════
    // Owner CRUD
    // ══════════════════════════════════════════════════════════════════

    public function test_owner_creates_a_quota_free_read_only_viewer_with_a_password_he_can_re_read(): void
    {
        $this->actingAs($this->ownerA, 'pos')
            ->post('/pos/local-bills/viewers', [
                'name' => 'Bills Viewer',
                'email' => 'bills-viewer@t.test',
                'password' => 'Portal@12345',
            ])
            ->assertRedirect();

        $viewer = User::where('email', 'bills-viewer@t.test')->first();
        $this->assertNotNull($viewer, 'owner could not create the viewer account');
        // Same row shape as the SaaS flow — both panels manage the same accounts.
        $this->assertSame('employee', $viewer->role);
        $this->assertSame('local_viewer', $viewer->pos_role);
        $this->assertSame($this->companyA, (int) $viewer->company_id);
        $this->assertTrue((bool) $viewer->is_active);
        $this->assertTrue(Hash::check('Portal@12345', $viewer->password));
        // Owner-viewable copy.
        $this->assertSame('Portal@12345', ViewablePasswordService::reveal($viewer->pos_team_password_enc));
        // Company-side audit trail.
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'pos_local_viewer_created',
            'entity_id' => $viewer->id,
            'company_id' => $this->companyA,
            'user_id' => $this->ownerA->id,
        ]);
        // Never a team seat: the plan quota counts managers + cashiers only.
        $this->assertSame(0, User::where('company_id', $this->companyA)
            ->whereIn('pos_role', ['pos_manager', 'pos_cashier'])
            ->where('id', $viewer->id)->count());
    }

    public function test_owner_can_rename_reset_disable_and_remove_the_account(): void
    {
        $viewer = $this->seedViewer($this->companyA);

        // Rename + password reset — the viewable copy must follow.
        $this->actingAs($this->ownerA, 'pos')
            ->put("/pos/local-bills/viewers/{$viewer->id}", [
                'name' => 'Renamed Viewer',
                'email' => 'viewer@t.test',
                'password' => 'Reset@98765',
            ])
            ->assertRedirect();
        $viewer->refresh();
        $this->assertSame('Renamed Viewer', $viewer->name);
        $this->assertTrue(Hash::check('Reset@98765', $viewer->password));
        $this->assertSame('Reset@98765', ViewablePasswordService::reveal($viewer->pos_team_password_enc));

        // Disable / re-enable.
        $this->actingAs($this->ownerA, 'pos')->post("/pos/local-bills/viewers/{$viewer->id}/toggle")->assertRedirect();
        $this->assertFalse((bool) $viewer->fresh()->is_active);
        $this->actingAs($this->ownerA, 'pos')->post("/pos/local-bills/viewers/{$viewer->id}/toggle")->assertRedirect();
        $this->assertTrue((bool) $viewer->fresh()->is_active);

        // Remove.
        $this->actingAs($this->ownerA, 'pos')->delete("/pos/local-bills/viewers/{$viewer->id}")->assertRedirect();
        $this->assertNull(User::find($viewer->id));
        $this->assertDatabaseHas('audit_logs', ['action' => 'pos_local_viewer_deleted', 'entity_id' => $viewer->id]);
    }

    public function test_a_shop_cannot_keep_more_than_two_viewer_accounts(): void
    {
        $this->seedViewer($this->companyA, 'v1@t.test');
        $this->seedViewer($this->companyA, 'v2@t.test');

        $this->actingAs($this->ownerA, 'pos')
            ->post('/pos/local-bills/viewers', [
                'name' => 'Third', 'email' => 'v3@t.test', 'password' => 'Portal@12345',
            ])
            ->assertRedirect();

        $this->assertNull(User::where('email', 'v3@t.test')->first(), 'cap of 2 viewer accounts was not enforced');
        $this->assertSame(2, User::where('company_id', $this->companyA)->where('pos_role', 'local_viewer')->count());
    }

    public function test_both_panels_share_one_cap_so_support_cannot_push_a_shop_past_it(): void
    {
        // One account from each panel — together they fill the shop's limit.
        $this->actingAs($this->ownerA, 'pos')
            ->post('/pos/local-bills/viewers', ['name' => 'By Owner', 'email' => 'by-owner@t.test', 'password' => 'Portal@12345'])
            ->assertRedirect();
        $this->actingAs($this->superAdmin, 'admin')
            ->post("/admin/companies/{$this->companyA}/local-viewer", ['name' => 'By Support', 'email' => 'by-support@t.test', 'password' => 'Support@12345'])
            ->assertRedirect();
        $this->assertSame(2, LocalViewerService::countFor($this->companyA));

        // Neither panel may add a third.
        $this->actingAs($this->superAdmin, 'admin')
            ->post("/admin/companies/{$this->companyA}/local-viewer", ['name' => 'Third', 'email' => 'third-admin@t.test', 'password' => 'Support@12345'])
            ->assertRedirect()
            ->assertSessionHas('error');
        $this->actingAs($this->ownerA, 'pos')
            ->post('/pos/local-bills/viewers', ['name' => 'Third', 'email' => 'third-owner@t.test', 'password' => 'Portal@12345'])
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertNull(User::where('email', 'third-admin@t.test')->first(), 'SaaS admin walked around the company-wide cap');
        $this->assertNull(User::where('email', 'third-owner@t.test')->first());
        $this->assertSame(2, LocalViewerService::countFor($this->companyA));

        // Freeing a slot from one panel opens it for the other.
        $byOwner = User::where('email', 'by-owner@t.test')->first();
        $this->actingAs($this->ownerA, 'pos')->delete("/pos/local-bills/viewers/{$byOwner->id}")->assertRedirect();
        $this->actingAs($this->superAdmin, 'admin')
            ->post("/admin/companies/{$this->companyA}/local-viewer", ['name' => 'Third', 'email' => 'third-admin@t.test', 'password' => 'Support@12345'])
            ->assertRedirect();
        $this->assertNotNull(User::where('email', 'third-admin@t.test')->first());
        $this->assertSame(2, LocalViewerService::countFor($this->companyA));
    }

    public function test_a_burst_of_creates_can_never_land_a_third_account(): void
    {
        // The cap is counted INSIDE the create transaction (behind a company-row
        // lock), not by the callers — so a double-submit or two panels firing at
        // once still ends at the limit, and a second company is unaffected.
        for ($i = 0; $i < 5; $i++) {
            LocalViewerService::create($this->companyA, "Burst {$i}", "burst-{$i}@t.test", 'Portal@12345');
        }
        LocalViewerService::create($this->companyB, 'Other Shop', 'other-shop@t.test', 'Portal@12345');

        $this->assertSame(2, LocalViewerService::countFor($this->companyA));
        $this->assertSame(1, LocalViewerService::countFor($this->companyB));
    }

    // ══════════════════════════════════════════════════════════════════
    // Owner-only gate
    // ══════════════════════════════════════════════════════════════════

    public function test_manager_reaches_the_portal_but_is_forbidden_from_every_viewer_endpoint(): void
    {
        $viewer = $this->seedViewer($this->companyA);

        // The manager may VIEW the portal (owner rule Jul 2026) — so the routes
        // are genuinely reachable for him and the controller is the only guard.
        $this->actingAs($this->managerA, 'pos')->get('/pos/local-bills')->assertOk();

        foreach ($this->guardedCalls($viewer->id) as $label => $call) {
            [$verb, $url, $payload] = $call;
            $this->actingAs($this->managerA, 'pos')->call($verb, $url, $payload)
                ->assertForbidden();
            $this->assertTrue(true, $label);
        }

        // Nothing happened.
        $this->assertNull(User::where('email', 'sneaky@t.test')->first(), 'manager created a viewer account');
        $this->assertTrue((bool) $viewer->fresh()->is_active, 'manager toggled a viewer account');
        $this->assertNotNull(User::find($viewer->id), 'manager deleted a viewer account');
        $this->assertSame('Viewer', $viewer->fresh()->name, 'manager edited a viewer account');
    }

    public function test_the_viewer_account_itself_cannot_manage_viewer_accounts(): void
    {
        $viewer = $this->seedViewer($this->companyA);

        foreach ($this->guardedCalls($viewer->id) as $call) {
            [$verb, $url, $payload] = $call;
            $this->actingAs($viewer, 'pos')->call($verb, $url, $payload)->assertForbidden();
        }
        $this->assertNotNull(User::find($viewer->id));
    }

    public function test_cashier_still_sees_nothing_of_the_portal(): void
    {
        $viewer = $this->seedViewer($this->companyA);

        // PosAuth 404s cashiers on the whole prefix — unchanged by this feature.
        $this->assertPanelNotFound($this->actingAs($this->cashierA, 'pos')->get('/pos/local-bills'));
        $this->assertPanelNotFound(
            $this->actingAs($this->cashierA, 'pos')
                ->post('/pos/local-bills/viewers', ['name' => 'X', 'email' => 'sneaky@t.test', 'password' => 'Portal@12345'])
        );
        $this->assertNull(User::where('email', 'sneaky@t.test')->first());
        $this->assertNotNull(User::find($viewer->id));
    }

    public function test_one_shops_owner_cannot_touch_another_shops_viewer_account(): void
    {
        $viewer = $this->seedViewer($this->companyA);

        // Owner B is a legitimate owner — the company scoping, not the role
        // gate, has to stop him.
        foreach ($this->guardedCalls($viewer->id) as $call) {
            [$verb, $url, $payload] = $call;
            if ($verb === 'POST' && $url === '/pos/local-bills/viewers') {
                continue; // creation is scoped to his OWN company by definition
            }
            $this->assertPanelNotFound($this->actingAs($this->ownerB, 'pos')->call($verb, $url, $payload));
        }

        $viewer->refresh();
        $this->assertSame('Viewer', $viewer->name);
        $this->assertTrue((bool) $viewer->is_active);
        $this->assertSame($this->companyA, (int) $viewer->company_id);
    }

    /**
     * "Not found" for a POS URL: the app's exception handler keeps each panel
     * isolated, so a 404 inside /pos/* comes back as a redirect to the POS
     * dashboard carrying the error (only API/JSON callers see a bare 404).
     * Either shape is a refusal — what must never happen is a 2xx/302-with-
     * success, and the callers assert the row is untouched as well.
     */
    private function assertPanelNotFound(\Illuminate\Testing\TestResponse $response): void
    {
        if ($response->getStatusCode() === 404) {
            return;
        }
        $response->assertRedirect('/pos/dashboard');
        $response->assertSessionHas('error');
    }

    /** @return array<string, array{0:string,1:string,2:array}> */
    private function guardedCalls(int $viewerId): array
    {
        return [
            'create' => ['POST', '/pos/local-bills/viewers', ['name' => 'X', 'email' => 'sneaky@t.test', 'password' => 'Portal@12345']],
            'update' => ['PUT', "/pos/local-bills/viewers/{$viewerId}", ['name' => 'Hacked', 'email' => 'viewer@t.test']],
            'toggle' => ['POST', "/pos/local-bills/viewers/{$viewerId}/toggle", []],
            'delete' => ['DELETE', "/pos/local-bills/viewers/{$viewerId}", []],
        ];
    }

    // ══════════════════════════════════════════════════════════════════
    // Both panels, one account
    // ══════════════════════════════════════════════════════════════════

    public function test_saas_admin_created_account_and_its_password_changes_reach_the_owners_section(): void
    {
        // Support creates the account from the SaaS admin company page…
        $this->actingAs($this->superAdmin, 'admin')
            ->post("/admin/companies/{$this->companyA}/local-viewer", [
                'name' => 'Support Made', 'email' => 'support-made@t.test', 'password' => 'Support@12345',
            ])
            ->assertRedirect();

        $viewer = User::where('email', 'support-made@t.test')->first();
        $this->assertNotNull($viewer, 'SaaS admin could not create the viewer account');
        $this->assertSame('Support@12345', ViewablePasswordService::reveal($viewer->pos_team_password_enc));

        // …and later resets its password from the same page.
        $this->actingAs($this->superAdmin, 'admin')
            ->put("/admin/companies/{$this->companyA}/local-viewer/{$viewer->id}", [
                'name' => 'Support Made', 'email' => 'support-made@t.test', 'password' => 'Support@99999',
            ])
            ->assertRedirect();
        $viewer->refresh();
        $this->assertTrue(Hash::check('Support@99999', $viewer->password));
        $this->assertSame(
            'Support@99999',
            ViewablePasswordService::reveal($viewer->pos_team_password_enc),
            'the owner would still be shown the OLD password after a SaaS-side reset'
        );

        // The owner sees the account — with the working password — in his section.
        $response = $this->actingAs($this->ownerA, 'pos')->get('/pos/local-bills');
        $response->assertOk();
        $response->assertViewHas('canManageViewers', true);
        $response->assertViewHas('viewerPasswords', function ($passwords) use ($viewer) {
            return ($passwords[$viewer->id] ?? null) === 'Support@99999';
        });
        $response->assertSee('support-made@t.test');

        // …and the owner can manage it (it is one shared list, not two).
        $this->actingAs($this->ownerA, 'pos')->post("/pos/local-bills/viewers/{$viewer->id}/toggle")->assertRedirect();
        $this->assertFalse((bool) $viewer->fresh()->is_active);
    }

    public function test_the_owners_section_stays_invisible_to_the_manager(): void
    {
        $this->seedViewer($this->companyA);

        $managerView = $this->actingAs($this->managerA, 'pos')->get('/pos/local-bills');
        $managerView->assertOk();
        $managerView->assertViewHas('canManageViewers', false);
        $managerView->assertDontSee('viewer@t.test');

        $ownerView = $this->actingAs($this->ownerA, 'pos')->get('/pos/local-bills');
        $ownerView->assertViewHas('canManageViewers', true);
        $ownerView->assertSee('viewer@t.test');
    }

    public function test_local_portal_includes_completed_reporting_off_finals_but_never_pra_submitted_or_offline_rows(): void
    {
        $today = now()->toDateString();
        $insert = function (string $number, array $attrs = []) use ($today) {
            return DB::table('pos_transactions')->insertGetId(array_merge([
                'company_id' => $this->companyA,
                'invoice_number' => $number,
                'status' => 'completed',
                'business_date' => $today,
                'total_amount' => 100,
                'created_at' => now(), 'updated_at' => now(),
            ], $attrs));
        };

        $local = $insert('L-SERIES', ['invoice_mode' => 'local', 'pra_status' => 'local']);
        $praModeFinal = $insert('REPORTING-OFF-PRA', [
            'invoice_mode' => 'pra', 'pra_status' => null, 'pra_invoice_number' => null,
        ]);
        $nullModeFinal = $insert('REPORTING-OFF-NULL', [
            'invoice_mode' => null, 'pra_status' => null, 'pra_invoice_number' => null,
        ]);
        // Archive visibility is deliberate for this audit portal.
        $archived = $insert('ARCHIVED-LOCAL', ['invoice_mode' => 'local', 'pra_status' => 'local', 'is_archived' => true]);
        $insert('SUBMITTED-NOT-LOCAL', ['invoice_mode' => 'pra', 'pra_status' => 'submitted', 'pra_invoice_number' => 'PRA-1']);
        $insert('OFFLINE-NOT-LOCAL', ['invoice_mode' => 'pra', 'pra_status' => 'offline']);
        $insert('NOT-COMPLETED', ['invoice_mode' => 'local', 'pra_status' => 'local', 'status' => 'draft']);
        DB::table('pos_transactions')->insert([
            'company_id' => $this->companyB, 'invoice_number' => 'OTHER-COMPANY',
            'invoice_mode' => 'local', 'pra_status' => 'local', 'status' => 'completed',
            'business_date' => $today, 'total_amount' => 100,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $response = $this->actingAs($this->ownerA, 'pos')->get('/pos/local-bills');
        $response->assertOk();
        $response->assertViewHas('bills', function ($bills) use ($local, $praModeFinal, $nullModeFinal, $archived) {
            return collect($bills->items())->pluck('id')->sort()->values()->all()
                === collect([$local, $praModeFinal, $nullModeFinal, $archived])->sort()->values()->all();
        });
        $response->assertViewHas('stats', fn ($stats) => $stats['total'] === 4 && (float) $stats['sum'] === 400.0);
        $response->assertDontSee('SUBMITTED-NOT-LOCAL')
            ->assertDontSee('OFFLINE-NOT-LOCAL')
            ->assertDontSee('OTHER-COMPANY');
    }
}
