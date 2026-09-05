<?php

namespace Tests\Feature;

use App\Http\Middleware\ReadOnlyImpersonation;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Session\Store;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Contracts\Auth\StatefulGuard;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

/**
 * "View as Company" must never lock somebody out of every login page.
 *
 * The owner hit this on live (5 Sep 2026): an old impersonation flag was still
 * sitting in the browser session long after the admin session behind it was
 * gone. ReadOnlyImpersonation refuses every login POST while that flag is set,
 * so each sign-in attempt was bounced straight back to the same login page —
 * from the outside it looked like the button simply did nothing.
 *
 * Two guarantees are locked in here:
 *   1. An ORPHANED flag (no admin session behind it) is dropped, and the panel
 *      login it opened is closed, so the request continues normally.
 *   2. A LIVE impersonation still refuses the identity swap, but lands the admin
 *      on the admin panel — the one surface carrying the "Exit" button — instead
 *      of the login page they were already stuck on.
 */
class ImpersonationLoginLockoutTest extends TestCase
{
    private function request(string $method, string $path, array $session): Request
    {
        $request = Request::create('/' . ltrim($path, '/'), $method);

        $store = new Store('test-session', new ArraySessionHandler(120));
        $store->start();
        foreach ($session as $key => $value) {
            $store->put($key, $value);
        }
        $request->setLaravelSession($store);

        return $request;
    }

    private function fakeGuards(bool $adminLoggedIn, bool $panelLoggedIn, &$panelLoggedOut = false): void
    {
        $panelLoggedOut = false;

        $panelGuard = \Mockery::mock(StatefulGuard::class);
        $panelGuard->shouldReceive('check')->andReturn($panelLoggedIn);
        $panelGuard->shouldReceive('logout')->andReturnUsing(function () use (&$panelLoggedOut) {
            $panelLoggedOut = true;
        });

        $adminGuard = \Mockery::mock(StatefulGuard::class);
        $adminGuard->shouldReceive('check')->andReturn($adminLoggedIn);

        Auth::shouldReceive('guard')->andReturnUsing(function ($name) use ($adminGuard, $panelGuard) {
            return $name === 'admin' ? $adminGuard : $panelGuard;
        });
    }

    public function test_orphaned_impersonation_flag_does_not_block_a_fresh_login(): void
    {
        $panelLoggedOut = false;
        $this->fakeGuards(adminLoggedIn: false, panelLoggedIn: true, panelLoggedOut: $panelLoggedOut);

        $request = $this->request('POST', 'login', [
            'impersonation' => [
                'admin_id' => 1,
                'company_id' => 7,
                'guard' => 'fbrpos',
                'readonly' => true,
            ],
        ]);

        $reached = false;
        $response = (new ReadOnlyImpersonation())->handle($request, function () use (&$reached) {
            $reached = true;
            return new Response('ok');
        });

        $this->assertTrue($reached, 'A login POST must pass through when no admin session backs the flag.');
        $this->assertSame('ok', $response->getContent());
        $this->assertNull($request->session()->get('impersonation'), 'The orphaned flag must be cleared.');
        $this->assertTrue($panelLoggedOut, 'The panel login the impersonation opened must be closed with it.');
    }

    public function test_live_impersonation_still_refuses_a_login_but_sends_the_admin_to_the_exit_banner(): void
    {
        $panelLoggedOut = false;
        $this->fakeGuards(adminLoggedIn: true, panelLoggedIn: true, panelLoggedOut: $panelLoggedOut);

        $request = $this->request('POST', 'login', [
            'impersonation' => [
                'admin_id' => 1,
                'company_id' => 7,
                'guard' => 'fbrpos',
                'readonly' => true,
            ],
        ]);

        $reached = false;
        $response = (new ReadOnlyImpersonation())->handle($request, function () use (&$reached) {
            $reached = true;
            return new Response('ok');
        });

        $this->assertFalse($reached, 'A live impersonation must still refuse an identity swap.');
        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame(url('/admin/dashboard'), $response->headers->get('Location'));
        $this->assertNotNull($request->session()->get('impersonation'), 'A live flag must survive the refusal.');
        $this->assertFalse($panelLoggedOut, 'A live impersonation must not close the panel login.');
    }

    public function test_admin_panel_requests_are_untouched_while_impersonating(): void
    {
        $this->fakeGuards(adminLoggedIn: true, panelLoggedIn: true);

        $request = $this->request('POST', 'admin/impersonation/stop', [
            'impersonation' => ['admin_id' => 1, 'company_id' => 7, 'guard' => 'pos', 'readonly' => true],
        ]);

        $reached = false;
        (new ReadOnlyImpersonation())->handle($request, function () use (&$reached) {
            $reached = true;
            return new Response('ok');
        });

        $this->assertTrue($reached, 'The exit route must stay reachable during impersonation.');
    }

    public function test_view_only_writes_inside_the_panel_still_bounce_back(): void
    {
        $this->fakeGuards(adminLoggedIn: true, panelLoggedIn: true);

        $request = $this->request('POST', 'pos/settings', [
            'impersonation' => ['admin_id' => 1, 'company_id' => 7, 'guard' => 'pos', 'readonly' => true],
        ]);

        $reached = false;
        $response = (new ReadOnlyImpersonation())->handle($request, function () use (&$reached) {
            $reached = true;
            return new Response('ok');
        });

        $this->assertFalse($reached, 'View-only mode must keep blocking company writes.');
        $this->assertSame(302, $response->getStatusCode());
        $this->assertNotSame(url('/admin/dashboard'), $response->headers->get('Location'),
            'A blocked write stays on the company page it came from — only identity swaps jump to the admin panel.');
    }
}
