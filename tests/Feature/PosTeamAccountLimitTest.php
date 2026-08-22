<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\Company;
use App\Models\PricingPlan;
use App\Models\Subscription;
use App\Models\User;
use App\Http\Controllers\CompanyUserController;
use App\Http\Controllers\PosController;
use App\Http\Middleware\CheckPlanLimit;
use App\Services\PlanLimitService;
use App\Services\PosPlanComparisonService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

/**
 * Task 1350 — POS team accounts must have exactly ONE number.
 *
 * pricing_plans looks like it carries two: user_limit (what the POS team pages
 * enforce, and what the package comparison table prints) and max_users. Only
 * user_limit is a POS number — max_users belongs to the DI panel's
 * plan.limit:users middleware on POST /company/users, which counts every user
 * (owner and inactive included) and ignores the company override. The two are
 * deliberately NOT mirrored: feeding a POS seat count into that column would
 * give the DI route a stricter cap than it has today.
 *
 * These tests pin the two things that would let drift back in:
 *
 *   1. Route-level POS team flow (PosController::storeCashier) — owner exempt,
 *      manager+cashier counted, kitchen/waiter/delivery exempt, inactive
 *      accounts release a slot, company override wins, and the cut-off is
 *      exactly the number the comparison table prints for that plan.
 *   2. The DI users route boundary — its cap follows max_users only, and a POS
 *      plan's seat number never leaks into it.
 *
 * Pattern: APP_ENV=testing + sqlite :memory: + minimal Schema::create
 * (mirrors PosQuickCreatePlanLimitTest).
 */
class PosTeamAccountLimitTest extends TestCase
{
    protected int $companyId;

    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropAllTables();

        Schema::create('companies', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->boolean('is_internal_account')->default(false);
            $table->integer('user_limit_override')->nullable();
            $table->softDeletes();
            $table->timestamps();
        });

        Schema::create('pricing_plans', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('product_type')->nullable();
            $table->integer('user_limit')->nullable();
            $table->integer('max_users')->nullable();
            $table->boolean('is_trial')->default(false);
            $table->timestamps();
        });

        Schema::create('subscriptions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('pricing_plan_id');
            $table->boolean('active')->default(true);
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->string('override_type')->nullable();
            $table->timestamp('override_until')->nullable();
            $table->timestamp('override_granted_at')->nullable();
            $table->date('end_date')->nullable();
            $table->timestamp('trial_ends_at')->nullable();
            $table->timestamps();
        });

        Schema::create('subscription_overrides', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->string('type')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id')->nullable();
            $table->string('name');
            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            $table->string('password')->nullable();
            $table->string('role')->nullable();
            $table->string('pos_role')->nullable();
            $table->string('username')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('security_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('action');
            $table->string('ip_address')->nullable();
            $table->text('user_agent')->nullable();
            $table->text('metadata')->nullable();
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id')->nullable();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('action');
            $table->string('entity_type')->nullable();
            $table->unsignedBigInteger('entity_id')->nullable();
            $table->text('old_values')->nullable();
            $table->text('new_values')->nullable();
            $table->string('ip_address')->nullable();
            $table->string('sha256_hash')->nullable();
            $table->timestamp('created_at')->nullable();
        });

        $company = Company::create(['name' => 'Team Shop']);
        $this->companyId = $company->id;
        app()->bind('currentCompanyId', fn () => $this->companyId);
    }

    // ── helpers ─────────────────────────────────────────────────────────

    private function makePlan(string $name, ?int $userLimit, string $productType = 'pos', ?int $maxUsers = null): PricingPlan
    {
        $plan = PricingPlan::create([
            'name' => $name,
            'product_type' => $productType,
            'user_limit' => $userLimit,
            // POS rows mirror max_users onto user_limit (the migration's job);
            // callers can pass an explicit value to simulate old drift.
            'max_users' => $maxUsers ?? $userLimit,
            'is_trial' => false,
        ]);
        Subscription::create([
            'company_id' => $this->companyId,
            'pricing_plan_id' => $plan->id,
            'active' => true,
            'starts_at' => now()->subDay(),
            'ends_at' => now()->addYear(),
        ]);
        return $plan;
    }

    /**
     * The shared container session — back()->with('error') flashes through
     * app('session.store'), so read the verdict from there. Flushed first so a
     * previous refusal can never leak into the next simulated request.
     */
    private function freshSession(): \Illuminate\Session\Store
    {
        $session = app('session.store');
        $session->flush();
        return $session;
    }

    private function owner(): User
    {
        return User::create([
            'company_id' => $this->companyId,
            'name' => 'Owner',
            'email' => 'owner@shop.test',
            'password' => bcrypt('secret123'),
            'role' => 'company_admin',
            'pos_role' => 'pos_admin',
            'is_active' => true,
        ]);
    }

    private function staff(string $posRole, string $email): User
    {
        return User::create([
            'company_id' => $this->companyId,
            'name' => ucfirst($posRole),
            'email' => $email,
            'password' => bcrypt('secret123'),
            'role' => 'employee',
            'pos_role' => $posRole,
            'is_active' => true,
        ]);
    }

    /** POS team page: add a member exactly the way the route does. */
    private function addTeamMember(string $posRole, string $email): string
    {
        $req = Request::create('/pos/team/cashier', 'POST', [
            'name' => 'New ' . $posRole,
            'email' => $email,
            'password' => 'secret123',
            'pos_role' => $posRole,
        ]);
        $req->setLaravelSession($this->freshSession());
        app()->instance('request', $req);

        (new PosController())->storeCashier($req);
        return $req->session()->get('error') !== null ? 'error' : 'ok';
    }

    // ── 1. POS team flow (route controller) ─────────────────────────────

    public function test_owner_is_exempt_and_staff_fill_exactly_the_advertised_number(): void
    {
        // Starter advertises 2 team accounts.
        $plan = $this->makePlan('Starter', 2);
        $owner = $this->owner();
        auth('pos')->setUser($owner);

        $this->assertSame(2, PlanLimitService::teamAccountLimit($plan),
            'The table prints teamAccountLimit(); the gate must use the same number.');

        $this->assertSame('ok', $this->addTeamMember('pos_cashier', 'c1@shop.test'));
        $this->assertSame('ok', $this->addTeamMember('pos_manager', 'm1@shop.test'));
        // Owner does NOT eat a slot: 2 added accounts is the cut-off, not 1.
        $this->assertSame('error', $this->addTeamMember('pos_cashier', 'c2@shop.test'));

        $this->assertSame(3, User::where('company_id', $this->companyId)->count(),
            'owner + exactly 2 team accounts');
    }

    public function test_confined_roles_never_consume_a_team_slot(): void
    {
        $this->makePlan('Starter', 2);
        auth('pos')->setUser($this->owner());

        // Fill the quota with counted roles first.
        $this->staff('pos_cashier', 'c1@shop.test');
        $this->staff('pos_manager', 'm1@shop.test');
        $this->assertSame('error', $this->addTeamMember('pos_cashier', 'c2@shop.test'));

        // Kitchen / waiter / delivery are limit-exempt confined roles.
        foreach (['pos_kitchen' => 'k@shop.test', 'pos_waiter' => 'w@shop.test', 'pos_delivery' => 'd@shop.test'] as $role => $email) {
            $this->assertSame('ok', $this->addTeamMember($role, $email), "{$role} must stay exempt at quota");
        }

        // ...and they still do not free or consume a counted slot afterwards.
        $this->assertSame('error', $this->addTeamMember('pos_manager', 'm2@shop.test'));
    }

    public function test_inactive_accounts_and_unlimited_plan_and_company_override(): void
    {
        $plan = $this->makePlan('Starter', 2);
        auth('pos')->setUser($this->owner());
        $c1 = $this->staff('pos_cashier', 'c1@shop.test');
        $this->staff('pos_manager', 'm1@shop.test');
        $this->assertSame('error', $this->addTeamMember('pos_cashier', 'c2@shop.test'));

        // A deactivated account releases its slot.
        $c1->update(['is_active' => false]);
        $this->assertSame('ok', $this->addTeamMember('pos_cashier', 'c3@shop.test'));

        // Admin override on the company beats the plan number.
        Company::find($this->companyId)->update(['user_limit_override' => 9]);
        $this->assertSame('ok', $this->addTeamMember('pos_cashier', 'c4@shop.test'));

        // Unlimited plan (-1) → table prints "Unlimited", gate never blocks.
        Company::find($this->companyId)->update(['user_limit_override' => null]);
        $plan->update(['user_limit' => -1, 'max_users' => -1]);
        $this->assertNull(PlanLimitService::teamAccountLimit($plan->fresh()));
        $this->assertSame('ok', $this->addTeamMember('pos_manager', 'm9@shop.test'));
    }

    public function test_every_pos_plan_gate_uses_the_number_the_table_prints(): void
    {
        auth('pos')->setUser($this->owner());

        foreach ([['Starter', 2], ['Business', 5], ['Pro', 20], ['Unlimited', -1]] as [$name, $limit]) {
            Subscription::where('company_id', $this->companyId)->delete();
            User::where('company_id', $this->companyId)->whereIn('pos_role', ['pos_manager', 'pos_cashier'])->delete();
            $plan = $this->makePlan($name, $limit);

            $printed = PlanLimitService::teamAccountLimit($plan);
            $this->assertSame($limit < 0 ? null : $limit, $printed, "{$name}: table number");

            if ($printed === null) {
                $this->assertTrue(PlanLimitService::canAddPosUser($this->companyId)['allowed']);
                continue;
            }

            // Seat the plan exactly full, then prove the very next add is refused.
            for ($i = 0; $i < $printed; $i++) {
                $this->staff('pos_cashier', "seat{$i}@{$name}.test");
                $this->assertTrue(
                    $i + 1 === $printed || PlanLimitService::canAddPosUser($this->companyId)['allowed'],
                    "{$name}: must still allow at " . ($i + 1) . "/{$printed}"
                );
            }
            $this->assertFalse(PlanLimitService::canAddPosUser($this->companyId)['allowed'],
                "{$name}: must refuse the account after {$printed}");
        }
    }

    // ── 2. The DI users route must stay exactly as it was ───────────────

    /**
     * pricing_plans.max_users is NOT the POS team-account number. It belongs to
     * the DI panel's plan.limit:users middleware on POST /company/users, which
     * counts every user (owner and inactive included) and ignores the company
     * override. Mirroring the POS seat count into it — or repointing the
     * middleware at user_limit — would hand that route a stricter cap than it
     * has today. This pins the boundary: the DI cap follows max_users only, and
     * a POS plan's seat number never leaks into it.
     */
    public function test_di_users_cap_follows_max_users_only_and_ignores_the_pos_seat_number(): void
    {
        // POS plan: 2 team accounts, DI column left unlimited (its real shape).
        $this->makePlan('Pro-like', 2, 'pos', -1);
        auth()->setUser($this->owner());
        auth('pos')->setUser(User::where('company_id', $this->companyId)->first());

        // POS side stops at the advertised 2 team accounts...
        $this->staff('pos_cashier', 'c1@shop.test');
        $this->staff('pos_manager', 'm1@shop.test');
        $this->assertFalse(PlanLimitService::canAddPosUser($this->companyId)['allowed']);

        // ...while the DI route, which is not a POS surface, is untouched by it.
        $this->assertTrue($this->middlewareAllowsUser(),
            'The POS seat count must never become the DI users cap.');
    }

    public function test_di_users_middleware_verdict_tracks_max_users_exactly(): void
    {
        // max_users below user_limit: the middleware must still follow its own
        // column (unchanged behaviour), not the POS/plan seat number.
        $this->makePlan('DI Business', 5, 'di', 3);
        auth()->setUser($this->owner());

        $this->assertTrue($this->middlewareAllowsUser());          // 1 user
        User::create(['company_id' => $this->companyId, 'name' => 'U2', 'email' => 'u2@shop.test',
            'password' => bcrypt('x'), 'role' => 'employee', 'is_active' => true]);
        $this->assertTrue($this->middlewareAllowsUser());          // 2 users
        User::create(['company_id' => $this->companyId, 'name' => 'U3', 'email' => 'u3@shop.test',
            'password' => bcrypt('x'), 'role' => 'employee', 'is_active' => true]);
        $this->assertFalse($this->middlewareAllowsUser());         // 3 = max_users

        // Unlimited DI column never blocks, whatever the seat number says.
        PricingPlan::where('name', 'DI Business')->update(['max_users' => -1]);
        $this->assertTrue($this->middlewareAllowsUser());
    }

    public function test_company_users_route_creates_a_user_under_its_own_cap_and_refuses_at_it(): void
    {
        $this->makePlan('DI Retail', 3, 'di', 3);
        auth()->setUser($this->owner());

        $this->assertTrue($this->middlewareAllowsUser());
        $this->assertSame('ok', $this->postCompanyUser('a@shop.test'));
        $this->assertSame('ok', $this->postCompanyUser('b@shop.test'));
        $this->assertSame(3, User::where('company_id', $this->companyId)->count());

        $this->assertFalse($this->middlewareAllowsUser());
        $this->assertSame('error', $this->postCompanyUser('c@shop.test'));
        $this->assertSame(3, User::where('company_id', $this->companyId)->count());
    }

    /** Run the real plan.limit:users middleware for the current company. */
    private function middlewareAllowsUser(): bool
    {
        $req = Request::create('/company/users', 'POST', []);
        $req->setLaravelSession($this->freshSession());
        $req->headers->set('Accept', 'application/json');
        app()->instance('request', $req);

        $passed = false;
        (new CheckPlanLimit())->handle($req, function () use (&$passed) {
            $passed = true;
            return response('ok');
        }, 'users');
        return $passed;
    }

    /** POST /company/users the way the route does: middleware, then controller. */
    private function postCompanyUser(string $email): string
    {
        if (!$this->middlewareAllowsUser()) {
            return 'error';
        }
        $req = Request::create('/company/users', 'POST', [
            'name' => 'DI User', 'email' => $email,
            'password' => 'secret123', 'role' => 'employee',
        ]);
        $req->setLaravelSession($this->freshSession());
        app()->instance('request', $req);

        (new CompanyUserController())->store($req);
        return $req->session()->get('error') !== null ? 'error' : 'ok';
    }

    // ── 3. The comparison table reads the same resolver ─────────────────

    public function test_comparison_table_team_row_reads_the_gate_resolver(): void
    {
        $plan = $this->makePlan('Pro', 10);
        $this->assertSame(
            PlanLimitService::teamAccountLimit($plan),
            PosPlanComparisonService::teamAccountLimit($plan),
            'The table must not compute its own team number.'
        );

        // Old drift shape: max_users left behind at a smaller number. The table
        // and every gate keep answering with user_limit, so nothing a customer
        // is shown can be refused by a stale column.
        $plan->update(['max_users' => 3]);
        $this->assertSame(10, PosPlanComparisonService::teamAccountLimit($plan->fresh()));
    }
}
