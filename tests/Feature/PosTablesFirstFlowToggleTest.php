<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Http\Controllers\RestaurantTableController;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * TABLES-FIRST FLOW TOGGLE (Task 779, owner video note 15 Aug 2026).
 *
 * companies.tables_first_flow: default ON (owner decision 16 Aug 2026 —
 * migration 2026_08_28_130000 set MySQL DEFAULT 1 and back-filled all rows).
 * New signups inherit the column default — no registration path writes an
 * explicit 0. Restaurant shops that prefer the old small-picker can flip OFF
 * at Table Setup.
 * Invariants locked here:
 *   1. Default is ON (new column defaults to 1) — all new signups start with
 *      the big Tables screen flow active.
 *   2. Admin/manager can flip ON and OFF; only the company row changes.
 *   3. Cashier gets 403 and no write (settings POST keeps the cashier guard).
 *   4. Flipping the flag changes posConfigRev — the offline boot fingerprint
 *      must refresh cached sale screens, or a shop that turns the flow ON/OFF
 *      keeps the stale behavior on cashier machines.
 *
 * Pattern: sqlite :memory: + minimal Schema::create, controller invoked
 * directly (same as PosCashReceivedToggleTest).
 */
class PosTablesFirstFlowToggleTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropAllTables();

        Schema::create('companies', function (Blueprint $table) {
            $table->id();
            $table->string('name')->nullable();
            $table->boolean('tables_first_flow')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id')->nullable();
            $table->string('name')->nullable();
            $table->string('pos_role')->nullable();
            $table->text('pos_custom_access')->nullable();
            $table->timestamps();
        });

        DB::table('companies')->insert(['id' => 1, 'name' => 'Test Cafe']);
        app()->instance('currentCompanyId', 1);
    }

    private function makeUser(string $role): User
    {
        $user = User::forceCreate(['company_id' => 1, 'name' => $role, 'pos_role' => $role]);
        Auth::guard('pos')->setUser($user);
        return $user;
    }

    private function callToggle(bool $enabled)
    {
        $request = Request::create('/pos/restaurant/tables-first-flow', 'POST', ['tables_first_flow' => $enabled ? '1' : '0']);
        $request->setLaravelSession(app('session.store'));
        return app(RestaurantTableController::class)->updateTablesFirstFlow($request);
    }

    public function test_default_is_on(): void
    {
        // Owner decision 16 Aug 2026: column DEFAULT changed to 1 via
        // migration 2026_08_28_130000_enable_tables_first_flow_for_all_companies.
        // New signups inherit this default — no registration path writes 0.
        $this->assertSame(1, (int) DB::table('companies')->where('id', 1)->value('tables_first_flow'));
    }

    public function test_admin_can_flip_on_and_off(): void
    {
        $this->makeUser('pos_admin');

        $res = $this->callToggle(true);
        $this->assertSame(302, $res->getStatusCode());
        $this->assertSame(1, (int) DB::table('companies')->where('id', 1)->value('tables_first_flow'));

        $res = $this->callToggle(false);
        $this->assertSame(302, $res->getStatusCode());
        $this->assertSame(0, (int) DB::table('companies')->where('id', 1)->value('tables_first_flow'));
    }

    public function test_manager_can_flip(): void
    {
        $this->makeUser('pos_manager');
        $this->callToggle(true);
        $this->assertSame(1, (int) DB::table('companies')->where('id', 1)->value('tables_first_flow'));
    }

    public function test_cashier_gets_403_and_no_write(): void
    {
        // Default is now 1; attempt to flip it OFF — must be blocked and stay 1.
        $this->makeUser('pos_cashier');
        try {
            $this->callToggle(false);
            $this->fail('Expected 403 HttpException for cashier');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }
        $this->assertSame(1, (int) DB::table('companies')->where('id', 1)->value('tables_first_flow'));
    }

    public function test_boot_fingerprint_changes_when_flag_flips(): void
    {
        // posConfigRev drives the offline boot fingerprint — flipping the flag
        // MUST change it or SW-cached sale screens keep the old flow baked in.
        $company = new \App\Models\Company();
        $company->setRawAttributes(['tables_first_flow' => 0], true);
        $off = $company->posConfigRev();
        $company->setRawAttributes(['tables_first_flow' => 1], true);
        $on = $company->posConfigRev();
        $this->assertNotSame($off, $on);
    }
}
