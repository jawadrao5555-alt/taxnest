<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\PosAccessService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * POS Team Custom Access — safety invariants (Task #120).
 *
 * Custom Access (Task #111) must NEVER, even when badly configured:
 *  (a) lock the sale screen / invoice APIs (billing must always work),
 *  (b) restrict the owner (company_admin / pos_admin),
 *  (c) grant confined roles (waiter/kitchen/rider/delivery/viewers) extra pages,
 *  (d) crash on a corrupt stored payload,
 *  (e) quietly take a grant AWAY when the feature list grows — a key added to
 *      PosAccessService::FEATURES must arrive with a backfill migration
 *      (Task 1391); see the "FEATURES growth guard" section at the bottom.
 *
 * Run with:
 *   APP_ENV=testing DB_CONNECTION=sqlite DB_DATABASE=:memory: \
 *     php artisan test --filter=PosCustomAccessInvariantsTest
 */
class PosCustomAccessInvariantsTest extends TestCase
{
    // ════════════════════════════════════════════════════════════════════
    // featureForPath — billing/sale paths must ALWAYS be unmapped (null)
    // ════════════════════════════════════════════════════════════════════

    public function test_sale_screen_and_invoice_paths_are_always_unmapped(): void
    {
        $alwaysOpen = [
            'pos/invoice/create',
            'pos/v2/invoice/create',
            'pos/invoice/store',
            '/pos/invoice/create',           // leading slash variant
            'pos/api/products',              // sale-screen data APIs
            'pos/api/print-jobs',
            'pos/logout',
            'pos/login',
            'pos/settings/theme',            // per-device display pref stays open
            'pos/set-language',
            'pos/my-profile',
            'pos/whats-new',
            'pos/grid-prefs/toggle',
        ];
        foreach ($alwaysOpen as $path) {
            $this->assertNull(
                PosAccessService::featureForPath($path),
                "Path [$path] must map to NO feature (always reachable) — billing must never break"
            );
        }
    }

    public function test_rider_settle_maps_to_deliveries_not_riders(): void
    {
        $this->assertSame('deliveries', PosAccessService::featureForPath('pos/riders/7/settle'));
        $this->assertSame('deliveries', PosAccessService::featureForPath('/pos/riders/123/settle'));
        // Rider CRUD stays under the 'riders' feature.
        $this->assertSame('riders', PosAccessService::featureForPath('pos/riders'));
        $this->assertSame('riders', PosAccessService::featureForPath('pos/riders/7/edit'));
    }

    public function test_known_admin_paths_map_to_expected_features(): void
    {
        $this->assertSame('orders', PosAccessService::featureForPath('pos/transactions'));
        $this->assertSame('orders', PosAccessService::featureForPath('pos/transaction/55'));
        $this->assertSame('reports', PosAccessService::featureForPath('pos/reports'));
        $this->assertSame('day_close', PosAccessService::featureForPath('pos/day-close'));
        $this->assertSame('customize', PosAccessService::featureForPath('pos/settings/local-billing'));
        $this->assertSame('team', PosAccessService::featureForPath('pos/team'));
    }

    // ════════════════════════════════════════════════════════════════════
    // customSet — owner & confined roles are NEVER restricted
    // ════════════════════════════════════════════════════════════════════

    private function makeUser(array $attrs): User
    {
        $u = new User();
        $u->forceFill($attrs);

        return $u;
    }

    public function test_company_admin_can_never_be_restricted(): void
    {
        // Even a company_admin whose pos_role somehow says "cashier" with a
        // stored restriction set must get NULL (no restriction).
        $owner = $this->makeUser([
            'role' => 'company_admin',
            'pos_role' => 'pos_cashier',
            'pos_custom_access' => json_encode(['reports']),
        ]);
        $this->assertNull(PosAccessService::customSet($owner), 'company_admin must never be restricted');

        // Normal owner shape (pos_admin role) is not customizable either.
        $owner2 = $this->makeUser([
            'role' => 'company_admin',
            'pos_role' => 'pos_admin',
            'pos_custom_access' => json_encode(['reports']),
        ]);
        $this->assertNull(PosAccessService::customSet($owner2));
    }

    public function test_confined_roles_never_get_a_custom_set(): void
    {
        foreach (['pos_admin', 'pos_kitchen', 'pos_waiter', 'pos_rider', 'pos_delivery', 'archive_viewer', 'local_viewer', null] as $role) {
            $u = $this->makeUser([
                'role' => 'user',
                'pos_role' => $role,
                'pos_custom_access' => json_encode(PosAccessService::FEATURES),
            ]);
            $this->assertNull(
                PosAccessService::customSet($u),
                'Role [' . ($role ?? 'NULL') . '] must ignore stored grants — confinement supersedes custom access'
            );
        }
    }

    public function test_null_user_and_missing_set_return_null(): void
    {
        $this->assertNull(PosAccessService::customSet(null));

        $cashier = $this->makeUser(['role' => 'user', 'pos_role' => 'pos_cashier']);
        $this->assertNull(PosAccessService::customSet($cashier), 'No stored set → role default (null)');

        $cashier->forceFill(['pos_custom_access' => '']);
        $this->assertNull(PosAccessService::customSet($cashier), 'Empty string → role default (null)');
    }

    public function test_corrupt_json_falls_back_to_role_default_without_crashing(): void
    {
        foreach (['{not json', '"just-a-string"', '123', 'null', 'true'] as $corrupt) {
            $u = $this->makeUser([
                'role' => 'user',
                'pos_role' => 'pos_cashier',
                'pos_custom_access' => $corrupt,
            ]);
            $this->assertNull(
                PosAccessService::customSet($u),
                "Corrupt payload [$corrupt] must yield NULL (role default), never crash"
            );
        }
    }

    public function test_unknown_feature_keys_are_stripped(): void
    {
        $u = $this->makeUser([
            'role' => 'user',
            'pos_role' => 'pos_cashier',
            'pos_custom_access' => json_encode(['reports', 'hack_everything', 'admin']),
        ]);
        $this->assertSame(['reports'], PosAccessService::customSet($u));
    }

    public function test_customizable_roles_get_their_set(): void
    {
        foreach (['pos_cashier', 'pos_manager'] as $role) {
            $u = $this->makeUser([
                'role' => 'user',
                'pos_role' => $role,
                'pos_custom_access' => json_encode(['reports', 'orders']),
            ]);
            $set = PosAccessService::customSet($u);
            $this->assertIsArray($set);
            $this->assertEqualsCanonicalizing(['orders', 'reports'], $set);
        }
    }

    public function test_custom_allows_verdicts(): void
    {
        $u = $this->makeUser([
            'role' => 'user',
            'pos_role' => 'pos_cashier',
            'pos_custom_access' => json_encode(['reports']),
        ]);
        $this->assertTrue(PosAccessService::customAllows($u, 'reports'));
        $this->assertFalse(PosAccessService::customAllows($u, 'orders'));
        $this->assertNull(PosAccessService::customAllows(null, 'reports'));
    }

    // ════════════════════════════════════════════════════════════════════
    // HTTP-level: cashier with ["reports"] → /pos/reports 200,
    //             /pos/transactions redirected away (never a lockout loop)
    // ════════════════════════════════════════════════════════════════════

    private function buildHttpSchema(): array
    {
        Schema::dropAllTables();

        Schema::create('companies', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            $table->string('ntn')->nullable();
            $table->string('product_type')->nullable();
            $table->string('status')->default('approved');
            $table->string('company_status')->default('approved');
            $table->boolean('restaurant_mode')->default(false);
            $table->boolean('pos_setup_completed')->default(true);
            $table->string('confidential_pin')->nullable();
            $table->string('default_language')->nullable();
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
            $table->text('pos_custom_access')->nullable();
            $table->boolean('is_active')->default(true);
            $table->boolean('dark_mode')->default(false);
            $table->string('language')->nullable();
            $table->unsignedBigInteger('default_branch_id')->nullable();
            $table->rememberToken();
            $table->timestamps();
        });

        Schema::create('branches', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->string('name');
            $table->boolean('is_head_office')->default(false);
            $table->boolean('is_active')->default(true);
            $table->softDeletes();
            $table->timestamps();
        });

        Schema::create('branch_user', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('branch_id');
            $table->unsignedBigInteger('user_id');
            $table->timestamps();
        });

        Schema::create('pos_user_sessions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->timestamp('login_at')->nullable();
            $table->timestamp('logout_at')->nullable();
            $table->timestamp('last_activity_at')->nullable();
            $table->timestamps();
        });

        Schema::create('pos_transactions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('created_by')->nullable();
            $table->string('status')->default('completed');
            $table->string('payment_method')->nullable();
            $table->string('invoice_mode')->nullable();
            $table->string('pra_status')->nullable();
            $table->string('pra_invoice_number')->nullable();
            $table->date('business_date')->nullable();
            $table->decimal('total_amount', 12, 2)->default(0);
            $table->decimal('tax_amount', 12, 2)->default(0);
            $table->boolean('is_archived')->default(false);
            $table->timestamps();
        });

        Schema::create('pos_transaction_items', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('transaction_id');
            $table->string('item_name')->nullable();
            $table->decimal('quantity', 12, 2)->default(0);
            $table->decimal('subtotal', 12, 2)->default(0);
            $table->timestamps();
        });

        Schema::create('subscriptions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('pricing_plan_id')->nullable();
            $table->string('status')->default('active');
            $table->boolean('is_active')->default(true);
            $table->boolean('active')->default(true);
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();
            $table->timestamps();
        });

        Schema::create('pricing_plans', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->nullable();
            $table->string('product_type')->nullable();
            $table->decimal('price', 12, 2)->default(0);
            $table->text('features')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        $companyId = DB::table('companies')->insertGetId([
            'name' => 'Custom Access Co',
            'product_type' => 'pos',
            'status' => 'approved',
            'company_status' => 'approved',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Custom Access is plan-gated (Unlimited-only). The lightweight
        // pricing_plans schema here has no custom_access_enabled column, so
        // the gate fails open — but an ACTIVE subscription must exist.
        $planId = DB::table('pricing_plans')->insertGetId([
            'name' => 'Test Plan',
            'product_type' => 'pos',
            'price' => 0,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('subscriptions')->insert([
            'company_id' => $companyId,
            'pricing_plan_id' => $planId,
            'status' => 'active',
            'is_active' => true,
            'active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $cashierId = DB::table('users')->insertGetId([
            'name' => 'Reports Cashier',
            'email' => 'cashier@customaccess.test',
            'password' => Hash::make('Cashier@12345'),
            'company_id' => $companyId,
            'role' => 'user',
            'pos_role' => 'pos_cashier',
            'pos_custom_access' => json_encode(['reports']),
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [$companyId, $cashierId];
    }

    public function test_http_cashier_with_reports_only_can_open_reports_but_not_transactions(): void
    {
        [, $cashierId] = $this->buildHttpSchema();
        $cashier = User::find($cashierId);

        // Blocked page: /pos/transactions maps to 'orders' (unticked) →
        // redirect AWAY (to the always-open sale screen — never a loop).
        $blocked = $this->actingAs($cashier, 'pos')->get('/pos/transactions');
        $blocked->assertRedirect('/pos/invoice/create');
        $blocked->assertSessionHas('error');

        // Allowed page: /pos/reports maps to 'reports' (ticked) → 200.
        $allowed = $this->actingAs($cashier, 'pos')->get('/pos/reports');
        $allowed->assertStatus(200);
    }

    public function test_http_owner_with_stored_restrictions_is_never_locked_out(): void
    {
        [$companyId] = $this->buildHttpSchema();

        // Pathological data: owner row carrying a restriction set. It must be
        // ignored — the owner can still open ANY admin page.
        $ownerId = DB::table('users')->insertGetId([
            'name' => 'Owner',
            'email' => 'owner@customaccess.test',
            'password' => Hash::make('Owner@12345'),
            'company_id' => $companyId,
            'role' => 'company_admin',
            'pos_role' => 'pos_admin',
            'pos_custom_access' => json_encode(['reports']), // bad data
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $owner = User::find($ownerId);

        $resp = $this->actingAs($owner, 'pos')->get('/pos/transactions');
        $resp->assertStatus(200);
    }

    // ════════════════════════════════════════════════════════════════════
    // FEATURES growth guard (Task 1391) — a newly added permission must not
    // silently switch itself OFF for shops that already saved a set
    // ════════════════════════════════════════════════════════════════════

    /**
     * Feature keys that need NO backfill migration:
     *  - everything that already existed when this guard was written, plus
     *  - any later key that is deliberately meant to start UNTICKED on
     *    existing sets — add it here WITH a one-line reason, so that stays a
     *    decision somebody made instead of an accident a shop discovers.
     */
    private const NO_BACKFILL_REQUIRED = [
        'dashboard',
        'orders',
        'products',
        'customers',
        'tables',
        'kitchen',
        'deliveries',
        'riders',
        'reports',
        'tax_reports',
        'day_close',
        'order_cancel',
        'returns',
        'inventory',
        'customize',
        'team',
    ];

    public function test_every_new_feature_key_ships_with_a_working_backfill_migration(): void
    {
        $candidates = $this->customAccessMigrationFiles();
        $unbackfilled = [];

        foreach (array_diff(PosAccessService::FEATURES, self::NO_BACKFILL_REQUIRED) as $feature) {
            $covered = false;
            foreach ($candidates as $file) {
                if ($this->migrationBackfills($file, $feature)) {
                    $covered = true;
                    break;
                }
            }
            if (!$covered) {
                $unbackfilled[] = $feature;
            }
        }

        Schema::dropIfExists('users'); // leave no probe table behind

        $this->assertSame([], array_values($unbackfilled), sprintf(
            "New Custom Access feature key(s) [%s] have no working backfill migration.\n\n"
            . "PosAccessService::customSet() intersects the stored JSON with FEATURES, so a key added to that list is\n"
            . "ABSENT from every set a shop saved before the deploy: a staff member who could do the job yesterday\n"
            . "quietly loses the button today, with no announcement.\n\n"
            . "Convention — copy database/migrations/2026_09_04_000000_backfill_kot_reprint_custom_access.php and APPEND\n"
            . "the new key to every non-null users.pos_custom_access set (additive + idempotent; NULL sets stay NULL so\n"
            . "they keep following the role default, corrupt payloads are left alone). This guard RUNS the migration and\n"
            . "checks that behaviour, so a migration that merely mentions the key does not satisfy it.\n\n"
            . "Deliberately want the key to start unticked on existing sets? Add it to\n"
            . "PosCustomAccessInvariantsTest::NO_BACKFILL_REQUIRED with a one-line reason.\n\n"
            . 'Migrations checked: %s',
            implode(', ', $unbackfilled),
            $candidates ? implode(', ', array_map('basename', $candidates)) : '(none touch pos_custom_access)'
        ));
    }

    public function test_backfill_exemption_list_stays_in_sync_with_the_feature_list(): void
    {
        $this->assertSame(
            [],
            array_values(array_diff(self::NO_BACKFILL_REQUIRED, PosAccessService::FEATURES)),
            'PosCustomAccessInvariantsTest::NO_BACKFILL_REQUIRED still lists keys that PosAccessService::FEATURES no '
            . 'longer has — drop them so the exemption list keeps describing the real tick-box set.'
        );
    }

    /** Migration files that touch users.pos_custom_access (backfill candidates). */
    private function customAccessMigrationFiles(): array
    {
        return array_values(array_filter(
            glob(database_path('migrations/*.php')) ?: [],
            fn ($file) => str_contains((string) file_get_contents($file), 'pos_custom_access')
        ));
    }

    /**
     * TRUE when running $file against a table of already-saved sets appends
     * $feature to them. Behaviour, not text: an unrelated migration (or one
     * that only names the key) simply returns false.
     */
    private function migrationBackfills(string $file, string $feature): bool
    {
        // Migration files are required at most once per process (a named-class
        // migration would fatal on a second require); up() is idempotent.
        static $loaded = [];
        $migration = $loaded[$file] ??= require $file;
        if (!is_object($migration) || !method_exists($migration, 'up')) {
            return false;
        }

        Schema::dropIfExists('users');
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->text('pos_custom_access')->nullable();
        });
        DB::table('users')->insert([
            ['id' => 1, 'pos_custom_access' => json_encode(['reports', 'orders'])], // saved set
            ['id' => 2, 'pos_custom_access' => null],                               // no set = role default
            ['id' => 3, 'pos_custom_access' => json_encode([$feature, 'reports'])], // already ticked
            ['id' => 4, 'pos_custom_access' => 'not-json{'],                        // corrupt payload
        ]);

        try {
            $migration->up();
        } catch (\Throwable $e) {
            return false; // unrelated migration (wants other tables/columns)
        }

        $stored = fn (int $id) => DB::table('users')->where('id', $id)->value('pos_custom_access');
        $existing = json_decode((string) $stored(1), true);
        $already = json_decode((string) $stored(3), true);

        return is_array($existing)
            && in_array($feature, $existing, true)                  // appended...
            && array_diff(['reports', 'orders'], $existing) === []  // ...without dropping the old grants
            && $stored(2) === null                                  // no set stays "role default"
            && is_array($already)
            && count(array_keys($already, $feature, true)) === 1    // idempotent — no duplicate key
            && $stored(4) === 'not-json{';                          // corrupt payload left alone
    }
}
