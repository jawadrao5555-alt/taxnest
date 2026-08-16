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
 * TABLE CLICK = DIRECT OPEN TOGGLE (Task 781, owner video note 15 Aug 2026).
 *
 * companies.table_click_direct_open: default OFF — occupied-table clicks keep
 * today's board action popup. A restaurant shop that wants table clicks to
 * load the order STRAIGHT into cart edit mode (popup skipped, actions moved
 * into the payment panel) flips it ON at Table Setup.
 * Invariants locked here:
 *   1. Default is OFF (new column defaults to 0) — no shop changes behavior
 *      until it opts in.
 *   2. Admin/manager can flip ON and OFF; only the company row changes.
 *   3. Cashier gets 403 and no write (settings POST keeps the cashier guard).
 *   4. Flipping the flag changes posConfigRev — the offline boot fingerprint
 *      must refresh cached sale screens, or a shop that turns direct-open ON
 *      keeps the popup flow baked into cashier machines.
 *
 * Pattern: sqlite :memory: + minimal Schema::create, controller invoked
 * directly (same as PosTablesFirstFlowToggleTest).
 */
class PosTableClickDirectOpenToggleTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropAllTables();

        Schema::create('companies', function (Blueprint $table) {
            $table->id();
            $table->string('name')->nullable();
            $table->boolean('table_click_direct_open')->default(false);
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
        $request = Request::create('/pos/restaurant/table-direct-open', 'POST', ['table_click_direct_open' => $enabled ? '1' : '0']);
        $request->setLaravelSession(app('session.store'));
        return app(RestaurantTableController::class)->updateTableClickDirectOpen($request);
    }

    public function test_default_is_off(): void
    {
        $this->assertSame(0, (int) DB::table('companies')->where('id', 1)->value('table_click_direct_open'));
    }

    public function test_admin_can_flip_on_and_off(): void
    {
        $this->makeUser('pos_admin');

        $res = $this->callToggle(true);
        $this->assertSame(302, $res->getStatusCode());
        $this->assertSame(1, (int) DB::table('companies')->where('id', 1)->value('table_click_direct_open'));

        $res = $this->callToggle(false);
        $this->assertSame(302, $res->getStatusCode());
        $this->assertSame(0, (int) DB::table('companies')->where('id', 1)->value('table_click_direct_open'));
    }

    public function test_manager_can_flip(): void
    {
        $this->makeUser('pos_manager');
        $this->callToggle(true);
        $this->assertSame(1, (int) DB::table('companies')->where('id', 1)->value('table_click_direct_open'));
    }

    public function test_cashier_gets_403_and_no_write(): void
    {
        $this->makeUser('pos_cashier');
        try {
            $this->callToggle(true);
            $this->fail('Expected 403 HttpException for cashier');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }
        $this->assertSame(0, (int) DB::table('companies')->where('id', 1)->value('table_click_direct_open'));
    }

    public function test_boot_fingerprint_changes_when_flag_flips(): void
    {
        // posConfigRev drives the offline boot fingerprint — flipping the flag
        // MUST change it or SW-cached sale screens keep the popup flow baked in.
        $company = new \App\Models\Company();
        $company->setRawAttributes(['table_click_direct_open' => 0], true);
        $off = $company->posConfigRev();
        $company->setRawAttributes(['table_click_direct_open' => 1], true);
        $on = $company->posConfigRev();
        $this->assertNotSame($off, $on);
    }
}
