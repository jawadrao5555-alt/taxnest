<?php

namespace Tests\Feature;

use App\Http\Controllers\FbrPosController;
use App\Models\User;
use App\Services\BranchContextService;
use App\Services\PosBusinessDay;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * FBR POS sales-report tiles follow the same branch rule as exportReportCsv
 * (audit MEDIUM, confirmed). The four tiles used to be company_id + date only,
 * so a manager on Branch A saw every branch's money under one heading.
 *
 * Pattern: sqlite :memory: + minimal Schema::create, controller invoked
 * directly (mirrors PosReportsLocalTabBranchScopeTest).
 */
class FbrPosReportsBranchScopeTest extends TestCase
{
    protected int $companyId;
    protected int $mainBranchId;
    protected int $cityBranchId;

    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropAllTables();
        User::flushScopeColumnCache();
        \App\Services\PosFeatureService::flushGateCaches();

        Schema::create('companies', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('product_type')->nullable();
            $table->string('status')->nullable();
            $table->string('pos_business_day_cutoff')->nullable();
            $table->boolean('is_internal_account')->default(false);
            $table->integer('invoice_limit_override')->nullable();
            $table->boolean('pos_setup_completed')->default(true);
            $table->softDeletes();
            $table->timestamps();
        });

        Schema::create('subscriptions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id')->nullable();
            $table->unsignedBigInteger('pricing_plan_id')->nullable();
            $table->boolean('active')->default(false);
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
            $table->unsignedBigInteger('default_branch_id')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('branches', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->string('name');
            $table->boolean('is_head_office')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('branch_user', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('branch_id');
            $table->unsignedBigInteger('user_id');
            $table->timestamps();
        });

        Schema::create('fbr_pos_transactions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('branch_id')->nullable();
            $table->string('invoice_number');
            $table->string('transaction_type')->nullable()->default('sale');
            $table->unsignedBigInteger('parent_transaction_id')->nullable();
            $table->string('business_date')->nullable();
            $table->string('status');
            $table->decimal('total_amount', 12, 2)->default(0);
            $table->decimal('tax_amount', 12, 2)->default(0);
            $table->decimal('discount_amount', 12, 2)->default(0);
            $table->string('payment_method')->nullable();
            $table->timestamps();
        });

        $this->companyId = (int) DB::table('companies')->insertGetId([
            'name' => 'FBR Two Shops',
            'product_type' => 'fbrpos',
            'status' => 'active',
            'pos_setup_completed' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        app()->bind('currentCompanyId', fn () => $this->companyId);

        $this->mainBranchId = $this->makeBranch('Main Shop', true);
        $this->cityBranchId = $this->makeBranch('Gulberg');

        $this->makeBill('F-MAIN', 1000, $this->mainBranchId);
        $this->makeBill('F-CITY', 500, $this->cityBranchId);
        $this->makeBill('F-OLD', 200, null);
    }

    protected function tearDown(): void
    {
        session()->forget(BranchContextService::SESSION_KEY);
        Auth::guard('fbrpos')->logout();
        parent::tearDown();
    }

    private function makeBranch(string $name, bool $head = false): int
    {
        return (int) DB::table('branches')->insertGetId([
            'company_id' => $this->companyId,
            'name' => $name,
            'is_head_office' => $head,
            'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function makeUser(string $posRole = 'pos_admin', ?int $branchId = null, string $role = 'company_admin'): User
    {
        $id = DB::table('users')->insertGetId([
            'name' => 'U' . uniqid(),
            'email' => uniqid('u') . '@taxnest.test',
            'company_id' => $this->companyId,
            'role' => $role,
            'pos_role' => $posRole,
            'default_branch_id' => $branchId,
            'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return User::find($id);
    }

    private function makeBill(string $number, float $amount, ?int $branchId): void
    {
        DB::table('fbr_pos_transactions')->insert([
            'company_id' => $this->companyId,
            'branch_id' => $branchId,
            'invoice_number' => $number,
            'transaction_type' => 'sale',
            'business_date' => PosBusinessDay::currentFbr($this->companyId),
            'status' => 'completed',
            'total_amount' => $amount,
            'tax_amount' => 0,
            'discount_amount' => 0,
            'payment_method' => 'cash',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function actAs(User $user, $branch = null): void
    {
        Auth::guard('fbrpos')->setUser($user);
        if ($branch !== null) {
            session([BranchContextService::SESSION_KEY => $branch]);
        }
    }

    /** @return array{today: float, month: float} */
    private function tiles(): array
    {
        $view = (new FbrPosController())->reports(Request::create('/fbr-pos/reports', 'GET'));
        $data = $view->getData();

        return [
            'today' => (float) ($data['todayStats']->revenue ?? 0),
            'month' => (float) ($data['monthStats']->revenue ?? 0),
        ];
    }

    public function test_report_tiles_follow_the_active_branch(): void
    {
        $owner = $this->makeUser('pos_admin');

        $this->actAs($owner, $this->mainBranchId);
        $tiles = $this->tiles();
        $this->assertSame(1200.0, $tiles['today'], 'Main Shop + legacy only');
        $this->assertSame(1200.0, $tiles['month']);

        $this->actAs($owner, $this->cityBranchId);
        $tiles = $this->tiles();
        $this->assertSame(700.0, $tiles['today'], 'Gulberg + legacy only');
        $this->assertSame(700.0, $tiles['month']);
    }

    public function test_owner_all_branches_view_is_company_wide(): void
    {
        $this->actAs($this->makeUser('pos_admin'), BranchContextService::ALL);

        $tiles = $this->tiles();
        $this->assertSame(1700.0, $tiles['today']);
        $this->assertSame(1700.0, $tiles['month']);
    }

    public function test_manager_pivoted_to_one_branch_cannot_see_the_other(): void
    {
        $manager = $this->makeUser('pos_manager', null, 'user');
        DB::table('branch_user')->insert([
            'branch_id' => $this->mainBranchId,
            'user_id' => $manager->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actAs($manager);
        $this->assertSame(1200.0, $this->tiles()['today']);

        $this->actAs($manager, $this->cityBranchId);
        $this->assertSame(1200.0, $this->tiles()['today'], 'a manager cannot reach a branch outside their pivot');

        $this->assertFalse(app(BranchContextService::class)->setActiveBranch(BranchContextService::ALL));
    }

    public function test_requested_branch_id_the_user_cannot_access_is_403(): void
    {
        $manager = $this->makeUser('pos_manager', null, 'user');
        DB::table('branch_user')->insert([
            'branch_id' => $this->mainBranchId,
            'user_id' => $manager->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->actAs($manager);

        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        (new FbrPosController())->reports(Request::create('/fbr-pos/reports', 'GET', [
            'branch_id' => $this->cityBranchId,
        ]));
    }

    public function test_a_shop_without_branches_still_sees_every_bill(): void
    {
        DB::table('branches')->delete();
        DB::table('fbr_pos_transactions')->update(['branch_id' => null]);
        $this->actAs($this->makeUser('pos_admin'));

        $this->assertSame(1700.0, $this->tiles()['today']);
    }
}
