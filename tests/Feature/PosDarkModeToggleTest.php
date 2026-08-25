<?php

namespace Tests\Feature;

use App\Http\Controllers\PosController;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Owner video (25 Aug 2026) — "dark mode sale screen par on karo to dashboard
 * light, dashboard par on karo to sale screen light."
 *
 * Cause: the Ctrl+K command palette only toggled the `dark` class on <html>.
 * Nothing was persisted, while the layout paints <html class="dark"> from
 * users.dark_mode — so the very next page load reverted it.
 *
 * Locked here:
 *  1. The toggle PERSISTS to users.dark_mode (verified by a raw DB read, not
 *     the model instance — memory: eloquent-missing-attribute-null).
 *  2. It flips (no explicit value = invert current).
 *  3. An explicit dark=false turns it off.
 *  4. It is a PER-USER preference: one user's choice never touches another's.
 *  5. A cashier may set their own theme (personal preference, not a shop
 *     setting — no 403 like /pos/settings/* writes).
 *  6. Guest → 401, nothing written.
 *
 * Run:
 *   env -u DATABASE_URL -u DB_CONNECTION -u PGHOST -u PGPORT -u PGUSER \
 *     -u PGPASSWORD -u PGDATABASE APP_ENV=testing DB_CONNECTION=sqlite \
 *     DB_DATABASE=':memory:' php vendor/bin/phpunit \
 *     tests/Feature/PosDarkModeToggleTest.php
 */
class PosDarkModeToggleTest extends TestCase
{
    private int $companyId;

    protected function setUp(): void
    {
        parent::setUp();
        User::flushScopeColumnCache();
        Schema::dropAllTables();

        Schema::create('companies', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('product_type')->nullable();
            $table->string('status')->default('approved');
            $table->string('company_status')->default('approved');
            $table->boolean('is_internal_account')->default(false);
            $table->text('feature_flags')->nullable();
            $table->softDeletes();
            $table->timestamps();
        });

        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name')->nullable();
            $table->string('email')->nullable()->unique();
            $table->string('password')->nullable();
            $table->unsignedBigInteger('company_id')->nullable();
            $table->string('role')->nullable();
            $table->string('pos_role')->nullable();
            $table->boolean('is_active')->default(true);
            $table->boolean('dark_mode')->default(false);
            $table->string('language')->nullable();
            $table->rememberToken();
            $table->timestamps();
        });

        $this->companyId = (int) DB::table('companies')->insertGetId([
            'name' => 'Dark Mode Test Co', 'product_type' => 'pos',
            'status' => 'approved', 'company_status' => 'approved',
            'is_internal_account' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        app()->instance('currentCompanyId', null);
        app()->bind('currentCompanyId', fn () => $this->companyId);
    }

    private function makeUser(string $posRole = 'pos_admin', bool $dark = false): User
    {
        $id = DB::table('users')->insertGetId([
            'name' => $posRole, 'email' => $posRole . rand() . '@darkmode.test',
            'password' => Hash::make('Secret@12'), 'company_id' => $this->companyId,
            'role' => 'user', 'pos_role' => $posRole, 'is_active' => true,
            'dark_mode' => $dark, 'created_at' => now(), 'updated_at' => now(),
        ]);
        return User::find($id);
    }

    private function hitToggle(?User $user, array $payload = []): array
    {
        if ($user) {
            $this->actingAs($user, 'pos');
        }
        $res = (new PosController())->toggleDarkMode(Request::create('/pos/set-dark-mode', 'POST', $payload));
        return [$res->getStatusCode(), $res->getData(true)];
    }

    /** Raw column read — the model instance can lie, the row cannot. */
    private function storedDark(int $userId): bool
    {
        return (bool) DB::table('users')->where('id', $userId)->value('dark_mode');
    }

    public function test_toggle_persists_dark_mode_to_the_user_row(): void
    {
        $user = $this->makeUser('pos_admin', false);

        [$status, $data] = $this->hitToggle($user, ['dark' => true]);

        $this->assertSame(200, $status);
        $this->assertTrue($data['success']);
        $this->assertTrue($data['dark']);
        $this->assertTrue($this->storedDark($user->id), 'dark_mode must survive in the DB — that is what the next page reads');
    }

    public function test_toggle_without_a_value_inverts_the_current_setting(): void
    {
        $user = $this->makeUser('pos_admin', true);

        [, $data] = $this->hitToggle($user);

        $this->assertFalse($data['dark']);
        $this->assertFalse($this->storedDark($user->id));
    }

    public function test_explicit_false_turns_dark_mode_off(): void
    {
        $user = $this->makeUser('pos_admin', true);

        [, $data] = $this->hitToggle($user, ['dark' => false]);

        $this->assertFalse($data['dark']);
        $this->assertFalse($this->storedDark($user->id));
    }

    public function test_preference_is_per_user_and_does_not_leak_to_a_colleague(): void
    {
        $one = $this->makeUser('pos_admin', false);
        $two = $this->makeUser('pos_cashier', false);

        $this->hitToggle($one, ['dark' => true]);

        $this->assertTrue($this->storedDark($one->id));
        $this->assertFalse($this->storedDark($two->id), 'One user turning the lights off must not darken everyone');
    }

    public function test_cashier_may_set_their_own_theme(): void
    {
        $cashier = $this->makeUser('pos_cashier', false);

        [$status, $data] = $this->hitToggle($cashier, ['dark' => true]);

        $this->assertSame(200, $status, 'Theme is a personal preference, not a shop setting');
        $this->assertTrue($data['success']);
        $this->assertTrue($this->storedDark($cashier->id));
    }

    public function test_guest_gets_401_and_writes_nothing(): void
    {
        $user = $this->makeUser('pos_admin', false);

        [$status, $data] = $this->hitToggle(null, ['dark' => true]);

        $this->assertSame(401, $status);
        $this->assertFalse($data['success']);
        $this->assertFalse($this->storedDark($user->id));
    }
}
