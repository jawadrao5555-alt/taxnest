<?php

namespace Tests\Feature\Security;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\TestCase;

/**
 * Regression guard for the former unauthenticated GET /setup-migrate-* and
 * /setup-seed-* closures that ran `migrate --force` / `db:seed --force`
 * over plain HTTP. Schema changes are CLI-only (deploy script) — no HTTP
 * surface may reach Artisan::call.
 */
class SetupRoutesRemovedTest extends TestCase
{
    use RefreshDatabase;

    public function test_legacy_setup_urls_are_not_routable(): void
    {
        foreach (['/setup-migrate-xK9mP2', '/setup-seed-xK9mP2', '/setup-migrate', '/setup-seed'] as $uri) {
            $this->getJson($uri)->assertNotFound();

            // Router-level proof (independent of the HTML 404→redirect renderer).
            try {
                Route::getRoutes()->match(Request::create($uri, 'GET'));
                $this->fail("Router matched [{$uri}] — a setup entry point is registered.");
            } catch (NotFoundHttpException $e) {
                $this->assertTrue(true);
            }
        }
    }

    public function test_no_registered_route_is_a_setup_entry_point(): void
    {
        foreach (Route::getRoutes() as $route) {
            $uri = $route->uri();
            $this->assertFalse(
                str_starts_with($uri, 'setup-'),
                "Route [{$uri}] looks like a web setup entry point; schema/seed work must stay CLI-only."
            );
        }
    }

    public function test_web_routes_never_invoke_artisan(): void
    {
        $source = file_get_contents(base_path('routes/web.php'));
        $this->assertStringNotContainsString(
            'Artisan::call(',
            $source,
            'routes/web.php must not invoke Artisan; schema/seed operations are CLI-only.'
        );
    }

    public function test_hitting_the_old_urls_does_not_run_migrations_or_seeders(): void
    {
        $before = Artisan::output();
        $this->getJson('/setup-migrate-xK9mP2')->assertNotFound();
        $this->getJson('/setup-seed-xK9mP2')->assertNotFound();
        // Browser-style requests must not behave differently.
        $this->assertContains($this->get('/setup-seed-xK9mP2')->getStatusCode(), [302, 404]);
        $this->assertSame($before, Artisan::output());
        $this->assertDatabaseMissing('users', ['email' => 'admin@test.com']);
    }
}
