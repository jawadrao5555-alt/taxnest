<?php

namespace Tests\Feature;

use App\Http\Controllers\SaasAdmin\AdminSystemController;
use App\Models\AdminUser;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Verifies that the MySQL Connections row on /admin/system-control renders the
 * correct colour band at every boundary condition.
 *
 * Colour rules (blade @php block + Alpine getters, kept in sync):
 *   pct > 70            → red
 *   pct > 50 && <= 70   → amber
 *   pct <= 50           → emerald/green
 *   pct null (DB error) → grey "Could not read" state
 *
 * Boundary values under test: 49 %, 50 %, 70 %, 71 % + DB-error fallback.
 *
 * The row is an Alpine component (30 s live refresh), so every colour class
 * ALSO exists as a string literal inside its <script> block — bare
 * assertSee('bg-emerald-500') would match in every response. The template
 * therefore server-renders the INITIAL band classes via Blade into the class
 * attributes of elements tagged data-testid="mysql-row" / "mysql-dot", and
 * these tests assert the full `{class}" data-testid="..."` attribute strings,
 * which only exist for the band the server actually picked. The Alpine x-data
 * init args are asserted too, proving the client component is seeded with the
 * same state.
 *
 * Uses AdminSystemController::$testMysqlStatusOverride to inject fake
 * [threads, maxConn] pairs without touching the real database — the same
 * test-hook pattern as CheckMysqlConnectionHealth::$testStatusOverride.
 */
class AdminSystemMysqlColourTest extends TestCase
{
    // ──────────────────────────────── setup ──────────────────────────────────

    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropAllTables();

        Schema::create('admin_users', function (Blueprint $t) {
            $t->id();
            $t->string('name')->nullable();
            $t->string('email')->unique();
            $t->string('password');
            $t->string('role')->default('super_admin');
            $t->rememberToken();
            $t->timestamps();
        });

        Schema::create('system_controls', function (Blueprint $t) {
            $t->id();
            $t->string('key')->unique();
            $t->string('value')->default('enabled');
            $t->string('description')->nullable();
            $t->unsignedBigInteger('last_changed_by')->nullable();
            $t->timestamps();
        });

        Schema::create('system_settings', function (Blueprint $t) {
            $t->id();
            $t->string('key')->unique();
            $t->text('value');
            $t->string('description')->nullable();
            $t->timestamps();
        });

        Schema::create('jobs', function (Blueprint $t) {
            $t->id();
            $t->string('queue')->index();
            $t->longText('payload');
            $t->unsignedTinyInteger('attempts');
            $t->unsignedInteger('reserved_at')->nullable();
            $t->unsignedInteger('available_at');
            $t->unsignedInteger('created_at');
        });

        DB::table('admin_users')->insert([
            'name'       => 'Test Admin',
            'email'      => 'admin@test.local',
            'password'   => Hash::make('secret'),
            'role'       => 'super_admin',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        AdminSystemController::$testMysqlStatusOverride = null;
    }

    protected function tearDown(): void
    {
        AdminSystemController::$testMysqlStatusOverride = null;
        parent::tearDown();
    }

    // ──────────────────────────────── helpers ────────────────────────────────

    private function getSystemPage(): \Illuminate\Testing\TestResponse
    {
        return $this
            ->actingAs(AdminUser::first(), 'admin')
            ->get('/admin/system-control');
    }

    /** Server-rendered row border classes, anchored to the mysql-row testid. */
    private function rowWith(string $classes): string
    {
        return $classes . '" data-testid="mysql-row"';
    }

    /** Server-rendered dot class, anchored to the mysql-dot testid. */
    private function dotWith(string $class): string
    {
        return $class . '" data-testid="mysql-dot"';
    }

    // ──────────────────────────────── tests ──────────────────────────────────

    /**
     * 49 % — below the amber threshold → green band.
     */
    public function test_49_percent_renders_green(): void
    {
        AdminSystemController::$testMysqlStatusOverride = [49, 100];

        $response = $this->getSystemPage();

        $response->assertStatus(200);
        // Controller computed and passed the right value.
        $response->assertViewHas('mysqlPct', 49.0);
        // Server-rendered emerald band on row + dot.
        $response->assertSee($this->rowWith('border-emerald-800/50 bg-emerald-900/10'), false);
        $response->assertSee($this->dotWith('bg-emerald-500'), false);
        // Alpine component seeded with the same state.
        $response->assertSee('mysqlHealth(49, 100, 49)', false);
        // Not amber, not red, not grey.
        $response->assertDontSee($this->dotWith('bg-amber-400'), false);
        $response->assertDontSee($this->dotWith('bg-red-500'), false);
        $response->assertDontSee($this->dotWith('bg-gray-500'), false);
    }

    /**
     * 50 % — exactly at the green/amber boundary → still green (rule: <= 50).
     */
    public function test_50_percent_is_still_green(): void
    {
        AdminSystemController::$testMysqlStatusOverride = [50, 100];

        $response = $this->getSystemPage();

        $response->assertStatus(200);
        $response->assertViewHas('mysqlPct', 50.0);
        $response->assertSee($this->rowWith('border-emerald-800/50 bg-emerald-900/10'), false);
        $response->assertSee($this->dotWith('bg-emerald-500'), false);
        $response->assertSee('mysqlHealth(50, 100, 50)', false);
        $response->assertDontSee($this->dotWith('bg-amber-400'), false);
        $response->assertDontSee($this->dotWith('bg-red-500'), false);
        $response->assertDontSee($this->dotWith('bg-gray-500'), false);
    }

    /**
     * 70 % — exactly at the amber/red boundary → amber (rule: > 50 && <= 70).
     */
    public function test_70_percent_renders_amber(): void
    {
        AdminSystemController::$testMysqlStatusOverride = [70, 100];

        $response = $this->getSystemPage();

        $response->assertStatus(200);
        $response->assertViewHas('mysqlPct', 70.0);
        $response->assertSee($this->rowWith('border-amber-700/60 bg-amber-900/10'), false);
        $response->assertSee($this->dotWith('bg-amber-400'), false);
        $response->assertSee('mysqlHealth(70, 100, 70)', false);
        $response->assertDontSee($this->dotWith('bg-emerald-500'), false);
        $response->assertDontSee($this->dotWith('bg-red-500'), false);
        $response->assertDontSee($this->dotWith('bg-gray-500'), false);
    }

    /**
     * 71 % — just above the red threshold → red band.
     */
    public function test_71_percent_renders_red(): void
    {
        AdminSystemController::$testMysqlStatusOverride = [71, 100];

        $response = $this->getSystemPage();

        $response->assertStatus(200);
        $response->assertViewHas('mysqlPct', 71.0);
        $response->assertSee($this->rowWith('border-red-800/60 bg-red-900/15'), false);
        $response->assertSee($this->dotWith('bg-red-500'), false);
        $response->assertSee('mysqlHealth(71, 100, 71)', false);
        $response->assertDontSee($this->dotWith('bg-emerald-500'), false);
        $response->assertDontSee($this->dotWith('bg-amber-400'), false);
        $response->assertDontSee($this->dotWith('bg-gray-500'), false);
    }

    /**
     * DB failure → mysqlPct is null → grey band; Alpine seeded with null pct
     * so it stamps the "Could not read MySQL status" fallback template.
     */
    public function test_db_error_renders_grey_fallback(): void
    {
        AdminSystemController::$testMysqlStatusOverride = 'error';

        $response = $this->getSystemPage();

        $response->assertStatus(200);
        $response->assertViewHas('mysqlPct', null);
        // Server-rendered grey band on row + dot.
        $response->assertSee($this->rowWith('border-gray-700/50 bg-gray-900/20'), false);
        $response->assertSee($this->dotWith('bg-gray-500'), false);
        // Alpine seeded with null → its pct === null template branch renders
        // the "Could not read MySQL status" message client-side.
        $response->assertSee('mysqlHealth(0, 0, null)', false);
        $response->assertSee('Could not read MySQL status', false);
        // No colour band server-rendered.
        $response->assertDontSee($this->dotWith('bg-emerald-500'), false);
        $response->assertDontSee($this->dotWith('bg-amber-400'), false);
        $response->assertDontSee($this->dotWith('bg-red-500'), false);
    }
}
