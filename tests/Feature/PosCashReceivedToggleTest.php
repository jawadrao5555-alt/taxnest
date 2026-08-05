<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Http\Controllers\PosController;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * CASH RECEIVED / WAPSI BOX TOGGLE (owner, Aug 2026).
 *
 * companies.pos_cash_received_enabled: default OFF — the Pay-modal Cash
 * Received input + "Wapas dein" change line stay hidden exactly as today;
 * a company that wants the box flips it ON at POS Customize.
 * Invariants locked here:
 *   1. Admin/manager can flip ON and OFF; only the company row changes.
 *   2. Cashier gets 403 and no write (settings are admin-only).
 *   3. Default is OFF (new column defaults to 0).
 *
 * Pattern: sqlite :memory: + minimal Schema::create, controller invoked
 * directly (same as PosWaiterStylePrefTest).
 */
class PosCashReceivedToggleTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropAllTables();

        Schema::create('companies', function (Blueprint $table) {
            $table->id();
            $table->string('name')->nullable();
            $table->boolean('pos_cash_received_enabled')->default(false);
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
        $request = Request::create('/pos/settings/cash-received-toggle', 'POST', ['enabled' => $enabled]);
        $request->setLaravelSession(app('session.store'));
        return app(PosController::class)->toggleCashReceived($request);
    }

    public function test_default_is_off(): void
    {
        $this->assertSame(0, (int) DB::table('companies')->where('id', 1)->value('pos_cash_received_enabled'));
    }

    public function test_admin_can_flip_on_and_off(): void
    {
        $this->makeUser('pos_admin');

        $res = $this->callToggle(true);
        $this->assertSame(200, $res->getStatusCode());
        $this->assertTrue($res->getData(true)['success']);
        $this->assertSame(1, (int) DB::table('companies')->where('id', 1)->value('pos_cash_received_enabled'));

        $res = $this->callToggle(false);
        $this->assertSame(200, $res->getStatusCode());
        $this->assertSame(0, (int) DB::table('companies')->where('id', 1)->value('pos_cash_received_enabled'));
    }

    public function test_manager_can_flip(): void
    {
        $this->makeUser('pos_manager');
        $res = $this->callToggle(true);
        $this->assertSame(200, $res->getStatusCode());
        $this->assertSame(1, (int) DB::table('companies')->where('id', 1)->value('pos_cash_received_enabled'));
    }

    public function test_cashier_gets_403_and_no_write(): void
    {
        $this->makeUser('pos_cashier');
        $res = $this->callToggle(true);
        $this->assertSame(403, $res->getStatusCode());
        $this->assertSame(0, (int) DB::table('companies')->where('id', 1)->value('pos_cash_received_enabled'));
    }

    public function test_boot_fingerprint_changes_when_flag_flips(): void
    {
        // posConfigRev drives the offline boot fingerprint — flipping the flag
        // MUST change it or cached sale screens keep serving the old UI.
        $company = new \App\Models\Company();
        $company->setRawAttributes(['pos_cash_received_enabled' => 0], true);
        $off = $company->posConfigRev();
        $company->setRawAttributes(['pos_cash_received_enabled' => 1], true);
        $on = $company->posConfigRev();
        $this->assertNotSame($off, $on);
    }
}
