<?php

namespace Tests\Unit;

use App\Http\Middleware\PosAdminOnly;
use App\Models\User;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

/**
 * Route-level enforcement for POS Custom Access inside the admin-only group:
 * deny-by-default for members with a custom set (mapped+ticked required),
 * unchanged behavior for everyone without one.
 */
class PosAdminOnlyCustomAccessTest extends TestCase
{
    private function run_middleware(User $user, string $method, string $path): Response
    {
        auth('pos')->setUser($user);
        $request = Request::create('/' . ltrim($path, '/'), $method);

        return (new PosAdminOnly())->handle($request, fn () => response('NEXT', 200));
    }

    private function member(string $posRole, ?string $access): User
    {
        $u = new User();
        $u->pos_role = $posRole;
        $u->role = 'employee';
        $u->pos_custom_access = $access;

        return $u;
    }

    public function test_manager_without_custom_set_passes_unchanged(): void
    {
        $res = $this->run_middleware($this->member('pos_manager', null), 'POST', 'pos/restaurant/stations');
        $this->assertSame('NEXT', $res->getContent());
    }

    public function test_cashier_without_custom_set_is_redirected_unchanged(): void
    {
        $res = $this->run_middleware($this->member('pos_cashier', null), 'GET', 'pos/pra-settings');
        $this->assertSame(302, $res->getStatusCode());
    }

    public function test_manager_with_customize_unticked_is_blocked_on_admin_posts(): void
    {
        $m = $this->member('pos_manager', '["dashboard","reports"]');
        foreach ([
            ['POST', 'pos/restaurant/stations'],          // station CRUD
            ['POST', 'pos/api/enable-pra-integration'],   // irreversible flip
            ['POST', 'pos/team/cashier/5/access'],        // team (unticked)
            ['PUT', 'pos/products/9'],                    // products (unticked)
            ['POST', 'pos/some-future-admin-endpoint'],   // UNMAPPED → deny-by-default
        ] as [$method, $path]) {
            $res = $this->run_middleware($m, $method, $path);
            $this->assertNotSame('NEXT', $res->getContent(), "$method $path must be blocked");
            $this->assertSame(302, $res->getStatusCode(), "$method $path must redirect");
        }
    }

    public function test_grants_expand_cashier_and_allow_manager(): void
    {
        $cashier = $this->member('pos_cashier', '["customize","team"]');
        $this->assertSame('NEXT', $this->run_middleware($cashier, 'GET', 'pos/pra-settings')->getContent());
        $this->assertSame('NEXT', $this->run_middleware($cashier, 'POST', 'pos/restaurant/stations')->getContent());
        $this->assertSame('NEXT', $this->run_middleware($cashier, 'POST', 'pos/team/cashier/5/access')->getContent());
        // …but not features outside the set:
        $this->assertSame(302, $this->run_middleware($cashier, 'PUT', 'pos/products/9')->getStatusCode());

        $manager = $this->member('pos_manager', '["products"]');
        $this->assertSame('NEXT', $this->run_middleware($manager, 'PUT', 'pos/products/9')->getContent());
    }

    /**
     * Controller-level guards must use the same custom-aware pattern
     * (posCashierBlocked), so a custom-restricted cashier is denied INSIDE the
     * controller too — before any state change (defense in depth with the
     * middleware, which is covered above and by live E2E checks).
     */
    public function test_station_controller_guard_denies_custom_restricted_cashier(): void
    {
        $cashier = $this->member('pos_cashier', '["reports"]'); // customize NOT ticked
        auth('pos')->setUser($cashier);
        $request = Request::create('/pos/restaurant/stations', 'POST', ['name' => 'X']);
        app()->instance('request', $request);
        try {
            (new \App\Http\Controllers\RestaurantPosController())->storeStation($request);
            $this->fail('Expected 403 abort');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }
    }

    public function test_public_profile_controller_guard_denies_custom_restricted_cashier(): void
    {
        $cashier = $this->member('pos_cashier', '["reports"]');
        auth('pos')->setUser($cashier);
        $request = Request::create('/pos/public-profile', 'POST');
        $request->setUserResolver(fn () => $cashier);
        app()->instance('request', $request);
        try {
            (new \App\Http\Controllers\PublicProfileController())->saveSettings($request);
            $this->fail('Expected 403 abort');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }
    }

    public function test_json_requests_get_403_not_redirect(): void
    {
        $m = $this->member('pos_manager', '["dashboard"]');
        auth('pos')->setUser($m);
        $request = Request::create('/pos/restaurant/stations', 'POST');
        $request->headers->set('Accept', 'application/json');
        try {
            (new PosAdminOnly())->handle($request, fn () => response('NEXT', 200));
            $this->fail('Expected 403 abort');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }
    }
}
