<?php

namespace Tests\Feature;

use App\Http\Middleware\PosAuth;
use App\Models\User;
use App\Services\PosAccessService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Tests\TestCase;

/**
 * /pos/tutorials — har POS role ke liye khula (owner intent: "every company
 * login may learn", TutorialController, 2 Aug 2026).
 *
 * Confined roles (archive_viewer, local_viewer, pos_kitchen, pos_rider,
 * pos_delivery, pos_waiter) apne path-prefix allowlists mein qaid hain —
 * yeh test lock karta hai ke:
 *   (a) pos/tutorials HAR confined role ke allowlist mein hai (middleware
 *       $next tak jaane deta hai, redirect nahi karta),
 *   (b) confinement baqi pages par barqarar hai (foreign page → apne
 *       portal par redirect),
 *   (c) pos/tutorials kisi Custom Access feature key par map NAHI hota
 *       (cashier/manager grants ise kabhi block nahi kar sakte).
 *
 * Pattern: APP_ENV=testing + sqlite :memory: + minimal Schema::create,
 * middleware directly invoked (same approach as the rider invariant tests —
 * full-page render layouts ki poori schema maangta hai, gate ka faisla
 * middleware mein hota hai).
 *
 * Run with:
 *   APP_ENV=testing DB_CONNECTION=sqlite DB_DATABASE=:memory: \
 *     php artisan test --filter=PosTutorialsRoleAccessTest
 */
class PosTutorialsRoleAccessTest extends TestCase
{
    private const COMPANY = 71;

    /** pos_role => home path jahan confined role redirect hota hai. */
    private const CONFINED_HOMES = [
        'archive_viewer' => '/pos/archive',
        'local_viewer'   => '/pos/local-bills',
        'pos_kitchen'    => '/pos/restaurant/kds',
        'pos_rider'      => '/pos/rider',
        'pos_delivery'   => '/pos/deliveries',
        'pos_waiter'     => '/pos/waiter',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropAllTables();

        Schema::create('companies', function (Blueprint $table) {
            $table->id();
            $table->string('name')->nullable();
            $table->string('product_type')->default('pos');
            $table->boolean('is_internal_account')->default(false);
            $table->softDeletes();
            $table->timestamps();
        });

        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id')->nullable();
            $table->string('name')->nullable();
            $table->string('email')->nullable();
            $table->string('password')->nullable();
            $table->string('role')->nullable();
            $table->string('pos_role')->nullable();
            $table->boolean('is_active')->default(true);
            $table->text('pos_custom_access')->nullable();
            $table->unsignedBigInteger('default_branch_id')->nullable();
            $table->timestamps();
        });

        // PosAuth resolves the active branch at the end of handle() —
        // empty table = null branch, which is the no-branches case.
        Schema::create('branches', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->string('name')->nullable();
            $table->boolean('is_head_office')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        DB::table('companies')->insert([
            'id' => self::COMPANY,
            'name' => 'Tutorials Access Co',
            'product_type' => 'pos',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function makeUser(string $posRole, int $id): User
    {
        DB::table('users')->insert([
            'id' => $id,
            'company_id' => self::COMPANY,
            'name' => "Role $posRole",
            'email' => "$posRole@test.pk",
            'password' => bcrypt('x'),
            'role' => 'pos_user',
            'pos_role' => $posRole,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return User::findOrFail($id);
    }

    /** Run the PosAuth middleware for $user against GET $path. */
    private function runMiddleware(User $user, string $path)
    {
        Auth::guard('pos')->setUser($user);

        $request = Request::create('https://nestpos.test/' . ltrim($path, '/'), 'GET');

        return (new PosAuth())->handle($request, fn () => response('NEXT-OK'));
    }

    public function test_every_confined_role_can_reach_pos_tutorials(): void
    {
        $id = 100;
        foreach (self::CONFINED_HOMES as $role => $home) {
            $user = $this->makeUser($role, $id++);
            $response = $this->runMiddleware($user, 'pos/tutorials');

            $this->assertNotInstanceOf(
                RedirectResponse::class,
                $response,
                "Role [$role] must NOT be redirected away from /pos/tutorials"
            );
            $this->assertSame(
                'NEXT-OK',
                $response->getContent(),
                "Role [$role] must pass PosAuth for /pos/tutorials"
            );
        }
    }

    public function test_confinement_still_holds_on_other_pages(): void
    {
        $id = 200;
        foreach (self::CONFINED_HOMES as $role => $home) {
            $user = $this->makeUser($role, $id++);
            $response = $this->runMiddleware($user, 'pos/dashboard');

            $this->assertInstanceOf(
                RedirectResponse::class,
                $response,
                "Role [$role] must still be confined away from /pos/dashboard"
            );
            $this->assertSame(
                $home,
                '/' . ltrim(parse_url($response->getTargetUrl(), PHP_URL_PATH), '/'),
                "Role [$role] must be sent home to $home"
            );
        }
    }

    public function test_unconfined_roles_pass_and_custom_access_never_gates_tutorials(): void
    {
        // Cashier (unconfined) passes straight through.
        $cashier = $this->makeUser('pos_cashier', 300);
        $response = $this->runMiddleware($cashier, 'pos/tutorials');
        $this->assertSame('NEXT-OK', $response->getContent(), 'pos_cashier must reach /pos/tutorials');

        // Tutorials must map to NO Custom Access feature key — a badly
        // configured per-member grant may never block the learning page.
        $this->assertNull(
            PosAccessService::featureForPath('pos/tutorials'),
            'pos/tutorials must not be gated by any Custom Access feature'
        );
    }
}
