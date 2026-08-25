<?php

namespace Tests\Feature;

use App\Http\Controllers\PosController;
use App\Models\Company;
use App\Models\User;
use App\Services\PosAccessService;
use App\Services\PosFeatureService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * BAKED PERMISSIONS MUST REFRESH AN OFFLINE SALE SCREEN — Task 1390.
 *
 * /pos/invoice/create is served CACHE-FIRST out of the service worker's
 * SALE_CACHE, and the mobile WebView shell loads that very same cached copy.
 * A browser can therefore hold a screen that was rendered BEFORE the owner
 * removed a cashier's kitchen-ticket permission. The ONLY thing that makes
 * such a copy reload itself is PosController::posBootFingerprint(): the baked
 * verdict is hashed into its 'set' key, /pos/api/boot-check serves the live
 * hash, and a mismatch re-fetches the screen.
 *
 * Drop a verdict from that hash and nothing fails loudly — the Reprint /
 * Re-send / Last Add-on buttons simply stay on screen for a whole offline
 * session and only blow up with a server 403 when a cashier finally clicks
 * one. This class is the alarm for that silent regression:
 *
 *   1. Flipping the COMPANY switch (companies.kot_reprint_enabled) changes the
 *      fingerprint.
 *   2. Flipping ONE CASHIER's Custom Access tick changes it too — deliberately
 *      written so the reprint verdict is the only thing that can move
 *      (users.updated_at, which the fingerprint ALSO hashes, is left
 *      untouched), so this assertion fails the moment the verdict stops
 *      contributing.
 *   3. THE PATTERN: the baked verdicts are DISCOVERED from the controller
 *      source, so the next permission baked into the sale screen is covered
 *      automatically — it must be hashed into the fingerprint AND given a flip
 *      case here, or this class fails.
 *
 * Sibling: PosBootFingerprintStabilityTest guards the opposite direction (no
 * FALSE staleness → no reload loop). Server-side enforcement of the reprint
 * verdict lives in PosKotReprintPermissionTest.
 *
 * Run:
 *   php vendor/bin/phpunit tests/Feature/PosBakedPermissionFingerprintTest.php --testdox
 */
class PosBakedPermissionFingerprintTest extends TestCase
{
    /** Custom Access set the cashier starts with (kot_reprint ticked, order_cancel not). */
    private const BASE_ACCESS = ['orders', 'kot_reprint'];

    private int $companyId;
    private int $userId;

    /**
     * Per-user permission verdicts BAKED into the sale screen, each mapped to
     * something that flips it: a Custom Access feature key (ticked on
     * /pos/team) or a closure for verdicts that are not tick-driven.
     *
     * ADDING A NEW BAKED PERMISSION? Hash its verdict inside
     * posBootFingerprint() and add one row here. The coverage test below reads
     * the baked verdicts straight out of PosController and fails until both
     * are done.
     */
    private function bakedVerdictFlips(): array
    {
        return [
            // Task 1379 — kitchen-ticket Reprint / Re-send / Last Add-on.
            'kotReprintAllowed'  => 'kot_reprint',
            // Last Add-on rides the SAME staff tick but has its own company
            // master, so a shop can block the dangerous whole-order Re-send
            // and still let the counter print just the newly added items.
            // Flip the column, not the tick, or this proves nothing new.
            'kotLastAddonAllowed' => fn () => DB::table('companies')
                ->where('id', $this->companyId)
                ->update(['kot_last_addon_enabled' => false]),
            // Task 643 — restaurant Order Cancel.
            'orderCancelAllowed' => 'order_cancel',
        ];
    }

    protected function setUp(): void
    {
        parent::setUp();

        // planAllows()/restaurantAllowed() cache per company id STATICALLY and
        // ids restart at 1 after dropAllTables — flush or an earlier class's
        // warm cache decides this one's verdicts.
        PosFeatureService::flushGateCaches();

        Schema::dropAllTables();

        Schema::create('companies', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('company_status')->default('approved');
            $table->string('status')->nullable();
            $table->string('pos_theme')->nullable();
            $table->boolean('pra_reporting_enabled')->default(false);
            $table->decimal('pos_tax_rate_cash', 8, 2)->nullable();
            $table->decimal('pos_tax_rate_card', 8, 2)->nullable();
            $table->text('pos_printer_settings')->nullable();
            // The company-level switches behind the baked verdicts.
            $table->boolean('kot_reprint_enabled')->default(true);       // Task 1379 master switch
            $table->boolean('kot_last_addon_enabled')->default(true);
            $table->boolean('pos_cashier_order_cancel')->default(false); // Task 643
            // Internal account → planAllows() passes → Custom Access sets are live.
            $table->boolean('is_internal_account')->default(false);
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
            $table->boolean('pra_reporting_enabled')->nullable();
            $table->timestamps();
        });

        // Tables the fingerprint's catalog revision aggregates over. Left
        // EMPTY: no deals means the date component stays out of 'cat', so two
        // fingerprints taken in the same test are only allowed to differ
        // because of what the test itself changed.
        foreach (['pos_products', 'pos_services', 'pos_deals'] as $t) {
            Schema::create($t, function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('company_id');
                $table->timestamps();
            });
        }

        Schema::create('pos_tax_rules', function (Blueprint $table) {
            $table->id();
            $table->string('payment_method');
            $table->decimal('tax_rate', 8, 2)->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        // The plan gates short-circuit on is_internal_account, but the lookups
        // must not explode if that ever stops being true.
        Schema::create('subscriptions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('pricing_plan_id')->nullable();
            $table->boolean('active')->default(true);
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();
            $table->timestamp('trial_ends_at')->nullable();
            $table->string('override_type')->default('none');
            $table->timestamp('override_until')->nullable();
            $table->timestamp('override_granted_at')->nullable();
            $table->timestamps();
        });
        Schema::create('pricing_plans', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('product_type')->default('pos');
            $table->boolean('is_trial')->default(false);
            $table->timestamps();
        });

        $this->companyId = DB::table('companies')->insertGetId([
            'name'                => 'Boot Fingerprint Karahi House',
            'is_internal_account' => true,
            'kot_reprint_enabled' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->userId = DB::table('users')->insertGetId([
            'name'              => 'Cashier',
            'email'             => 'cashier@baked-fp.pk',
            'password'          => 'not-used',
            'company_id'        => $this->companyId,
            'role'              => 'user',
            'pos_role'          => 'pos_cashier',
            'pos_custom_access' => json_encode(self::BASE_ACCESS),
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    /** The real boot fingerprint, computed through the private controller method. */
    private function fingerprint(): array
    {
        PosFeatureService::flushGateCaches();
        $m = new \ReflectionMethod(PosController::class, 'posBootFingerprint');
        $m->setAccessible(true);

        return $m->invoke(
            app(PosController::class),
            Company::findOrFail($this->companyId), // fresh rows: the fingerprint
            User::findOrFail($this->userId)        // must see what the DB now holds
        );
    }

    /** Live verdict for a PosAccessService method, e.g. kotReprintAllowed. */
    private function verdict(string $method): bool
    {
        PosFeatureService::flushGateCaches();

        return (bool) call_user_func(
            [PosAccessService::class, $method],
            User::findOrFail($this->userId),
            Company::findOrFail($this->companyId)
        );
    }

    /**
     * Tick / untick one Custom Access feature for the cashier.
     *
     * Written at DB level ON PURPOSE: the fingerprint also hashes
     * users.updated_at, and an Eloquent save would bump it — this test would
     * then keep passing through THAT hash even after the permission verdict
     * was dropped from the fingerprint, which is exactly the regression it
     * exists to catch.
     */
    private function toggleTick(string $feature): void
    {
        $raw = (string) DB::table('users')->where('id', $this->userId)->value('pos_custom_access');
        $set = json_decode($raw, true) ?: [];
        $set = in_array($feature, $set, true)
            ? array_values(array_diff($set, [$feature]))
            : array_values(array_merge($set, [$feature]));

        DB::table('users')->where('id', $this->userId)->update([
            'pos_custom_access' => json_encode($set),
        ]);
    }

    /** Apply a bakedVerdictFlips() entry (feature key or closure). */
    private function applyFlip($flip): void
    {
        is_callable($flip) ? $flip() : $this->toggleTick($flip);
    }

    private function userUpdatedAt(): ?string
    {
        return DB::table('users')->where('id', $this->userId)->value('updated_at');
    }

    /** Source of one PosController method, for the wiring scan. */
    private function methodSource(string $method): string
    {
        $r = new \ReflectionMethod(PosController::class, $method);
        $lines = file($r->getFileName());

        return implode('', array_slice($lines, $r->getStartLine() - 1, $r->getEndLine() - $r->getStartLine() + 1));
    }

    /** Source of whichever controller method renders the PRA sale screen. */
    private function saleScreenSource(): string
    {
        foreach ((new \ReflectionClass(PosController::class))->getMethods() as $m) {
            if ($m->getDeclaringClass()->getName() !== PosController::class) {
                continue;
            }
            $src = $this->methodSource($m->getName());
            if (str_contains($src, "view('pos.universal'")) {
                return $src;
            }
        }

        $this->fail("No PosController method renders view('pos.universal') any more — point this test at the sale screen's new render path.");
    }

    // ── 1. the company switch refreshes cached screens ───────────────────────

    public function test_company_kot_reprint_switch_refreshes_cached_sale_screens(): void
    {
        $before = $this->fingerprint();
        $this->assertTrue($this->verdict('kotReprintAllowed'), 'baseline: the shop may reprint');

        // Owner flips "Allow KOT Reprint" OFF in Customize — a master switch
        // that blocks the owner too.
        DB::table('companies')->where('id', $this->companyId)->update(['kot_reprint_enabled' => false]);

        $this->assertFalse($this->verdict('kotReprintAllowed'), 'the company switch must really withdraw reprinting');
        $this->assertNotSame(
            $before['set'],
            $this->fingerprint()['set'],
            'Turning the company KOT-reprint switch off no longer changes the sale-screen boot fingerprint — an offline / cache-first screen (browser or mobile shell) would keep offering Reprint, Re-send and Last Add-on until the next real boot.'
        );
    }

    // ── 2. one cashier's tick refreshes cached screens ───────────────────────

    /**
     * THE ISOLATING PROOF. Only the cashier's kot_reprint tick moves here:
     * the company row is untouched (so posConfigRev() cannot carry the test),
     * users.updated_at is asserted unchanged, and no other hashed verdict
     * reacts to this tick (order_cancel stays unticked either way). So the
     * fingerprint can only change through kotReprintAllowed() itself — this
     * test fails the moment that verdict leaves posBootFingerprint().
     */
    public function test_cashier_kot_reprint_tick_refreshes_cached_sale_screens(): void
    {
        $before = $this->fingerprint();
        $stamp = $this->userUpdatedAt();
        $this->assertTrue($this->verdict('kotReprintAllowed'), 'baseline: the tick is on');
        $this->assertFalse($this->verdict('orderCancelAllowed'), 'the other baked verdict must stay put across this flip');

        // Owner unticks "KOT Reprint" for this one staff member on /pos/team.
        $this->toggleTick('kot_reprint');

        $this->assertFalse($this->verdict('kotReprintAllowed'), 'the tick must really withdraw the permission');
        $this->assertFalse($this->verdict('orderCancelAllowed'), 'the other baked verdict must stay put across this flip');
        $this->assertSame(
            $stamp,
            $this->userUpdatedAt(),
            'users.updated_at moved — the fingerprint hashes it, so this test would pass through that hash instead of proving the reprint verdict is wired in.'
        );

        $this->assertNotSame(
            $before['set'],
            $this->fingerprint()['set'],
            'The kitchen-ticket reprint verdict no longer contributes to the sale-screen boot fingerprint — a cashier whose tick was just removed would keep a cached screen full of live-looking Reprint / Re-send / Last Add-on buttons for the whole offline session, failing only with a server error on click.'
        );
    }

    // ── 3. the pattern: every baked permission, present and future ───────────

    public function test_every_baked_permission_flip_refreshes_cached_sale_screens(): void
    {
        foreach ($this->bakedVerdictFlips() as $verdict => $flip) {
            $before = $this->fingerprint();
            $was = $this->verdict($verdict);

            $this->applyFlip($flip);

            $this->assertNotSame($was, $this->verdict($verdict),
                "The flip registered for {$verdict} does not actually change the verdict, so this case proves nothing — fix the flip in bakedVerdictFlips().");
            $this->assertNotSame($before['set'], $this->fingerprint()['set'],
                "{$verdict}() is baked into the sale screen but changing it no longer moves the boot fingerprint — a cache-first / offline screen would keep the old controls. Hash it inside posBootFingerprint().");

            $this->applyFlip($flip); // restore, so the cases stay independent
        }
    }

    /**
     * Discovers the permission verdicts the controller BAKES into the sale
     * screen and demands that each one is (a) hashed into the boot fingerprint
     * and (b) exercised by a flip case above. This is what makes the NEXT baked
     * permission covered by the same pattern — nobody has to remember.
     */
    public function test_every_baked_permission_verdict_is_wired_into_the_boot_fingerprint(): void
    {
        $screen = $this->saleScreenSource();
        $fingerprint = $this->methodSource('posBootFingerprint');

        // What actually reaches the Blade view.
        preg_match('/view\(\'pos\.universal\',\s*compact\((.*?)\)\)/s', $screen, $viewMatch);
        $viewData = $viewMatch[1] ?? '';
        $this->assertNotSame('', $viewData,
            "Could not read the sale screen's view data — this test can no longer tell which permissions are baked in; update the pattern it looks for.");

        // Any variable assigned from a PosAccessService *Allowed() verdict…
        preg_match_all(
            '/\$(\w+)\s*=\s*(?:\\\\?App\\\\Services\\\\)?PosAccessService::(\w+Allowed)\s*\(/',
            $screen,
            $hits,
            PREG_SET_ORDER
        );

        $baked = [];
        foreach ($hits as [, $var, $verdict]) {
            if (str_contains($viewData, "'" . $var . "'")) { // …and handed to the screen
                $baked[$verdict] = $var;
            }
        }

        $this->assertArrayHasKey('kotReprintAllowed', $baked,
            'The kitchen-ticket reprint verdict is no longer baked into the sale screen by this controller — if the bake moved, move this guard with it.');

        foreach ($baked as $verdict => $var) {
            $this->assertStringContainsString("PosAccessService::{$verdict}(", $fingerprint,
                "\${$var} is baked into the sale screen but posBootFingerprint() does not hash {$verdict}(). A cache-first / offline copy of the screen (browser or mobile shell) would keep showing controls the server already refuses. Add the verdict to the 'set' hash.");

            $this->assertArrayHasKey($verdict, $this->bakedVerdictFlips(),
                "\${$var} is a newly baked permission. Add a '{$verdict}' row to bakedVerdictFlips() so a real flip is proven to refresh cached sale screens, not just present in the hash.");
        }
    }
}
