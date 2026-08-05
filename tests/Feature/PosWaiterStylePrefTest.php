<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Http\Controllers\RestaurantWaiterController;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/**
 * PER-WAITER STYLE PREF (owner, 5 Aug 2026).
 *
 * users.pos_personal_style: NULL = company default; 'saaf'/'default' = the
 * waiter's OWN pick, overriding companies.pos_dashboard_style BOTH directions
 * for THAT user only. Locks the architect-review invariants:
 *   1. A waiter can flip saaf -> default -> saaf; only HIS row changes.
 *   2. The company style row is never touched by the endpoint.
 *   3. Non-waiter roles (cashier/admin/manager) get 403 — the override is
 *      waiter-scoped so the company-driven sale screen never mismatches.
 *   4. Invalid style values are rejected.
 *
 * Pattern: sqlite :memory: + minimal Schema::create, controller invoked
 * directly (same as PosWaiterMultiOrderPickerTest).
 */
class PosWaiterStylePrefTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropAllTables();

        Schema::create('companies', function (Blueprint $table) {
            $table->id();
            $table->string('name')->nullable();
            $table->string('pos_dashboard_style')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id')->nullable();
            $table->string('name')->nullable();
            $table->string('pos_role')->nullable();
            $table->string('pos_personal_style', 10)->nullable();
            $table->timestamps();
        });

        DB::table('companies')->insert(['id' => 1, 'name' => 'Test Cafe', 'pos_dashboard_style' => 'saaf']);
    }

    private function makeUser(string $role): User
    {
        $user = User::forceCreate(['company_id' => 1, 'name' => $role, 'pos_role' => $role]);
        Auth::guard('pos')->setUser($user);
        return $user;
    }

    private function callSaveStyle(string $style)
    {
        $request = Request::create('/pos/waiter/style', 'POST', ['style' => $style]);
        $request->setLaravelSession(app('session.store'));
        return (new RestaurantWaiterController())->saveStyle($request);
    }

    public function test_waiter_can_flip_style_both_directions_own_row_only(): void
    {
        $waiter = $this->makeUser('pos_waiter');
        $other = User::forceCreate(['company_id' => 1, 'name' => 'other waiter', 'pos_role' => 'pos_waiter']);

        $res = $this->callSaveStyle('default');
        $this->assertSame(200, $res->getStatusCode());
        $this->assertTrue($res->getData(true)['ok']);
        $this->assertSame('default', $waiter->fresh()->pos_personal_style);

        $res = $this->callSaveStyle('saaf');
        $this->assertSame(200, $res->getStatusCode());
        $this->assertSame('saaf', $waiter->fresh()->pos_personal_style);

        // Sibling waiter untouched (self-only write).
        $this->assertNull($other->fresh()->pos_personal_style);
    }

    public function test_company_style_row_never_touched(): void
    {
        $this->makeUser('pos_waiter');
        $this->callSaveStyle('default');
        $this->assertSame('saaf', DB::table('companies')->where('id', 1)->value('pos_dashboard_style'));
    }

    public function test_non_waiter_roles_get_403_and_no_write(): void
    {
        foreach (['pos_cashier', 'pos_admin', 'pos_manager', 'pos_kitchen'] as $role) {
            $user = $this->makeUser($role);
            $res = $this->callSaveStyle('saaf');
            $this->assertSame(403, $res->getStatusCode(), "role {$role} must be forbidden");
            $this->assertNull($user->fresh()->pos_personal_style, "role {$role} row must stay NULL");
        }
    }

    public function test_invalid_style_rejected(): void
    {
        $this->makeUser('pos_waiter');
        $this->expectException(ValidationException::class);
        $this->callSaveStyle('purple');
    }
}
