<?php

use App\Support\DatabaseDown;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Database\QueryException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Cloudflare in front of the origin: restore the real visitor IP from
        // CF-Connecting-IP (only when the peer is a genuine CF edge, or the
        // host's remoteip layer already restored it — spoof-safe either way).
        // Must run BEFORE TrustProxies / everything else.
        $middleware->prepend(\App\Http\Middleware\TrustCloudflare::class);
        // Trust proxies for PROTO only — deliberately NOT X-Forwarded-For/
        // Host/Port/Prefix. Client IP must come from REMOTE_ADDR alone
        // (TrustCloudflare sets it for Cloudflare traffic), and host/port/
        // prefix always derive from the real Host header / connection, so a
        // direct-origin caller cannot poison ip(), generated absolute URLs,
        // or signed URLs with forged forwarded headers. TrustCloudflare
        // additionally strips X-Forwarded-Proto from public non-Cloudflare
        // peers, so proto trust effectively covers only Cloudflare and the
        // local dev preview proxy (which needs it for secure cookies).
        $middleware->trustProxies(at: '*', headers:
            \Illuminate\Http\Request::HEADER_X_FORWARDED_PROTO
        );
        $middleware->append(\App\Http\Middleware\SecurityHeaders::class);
        // Slow-request telemetry: near-zero overhead on fast requests; logs
        // >2s requests to storage/logs/slow-requests-*.log in terminate().
        $middleware->append(\App\Http\Middleware\SlowRequestLogger::class);
        $middleware->validateCsrfTokens(except: [
            'pos/*',
            'api/agent/*',
            'api/rider-app/*',
            'api/caller-app/*',
            'webhooks/whatsapp/*', // Meta WA Cloud API status callbacks
            'bio-sync/*/iclock/*', // Biometric device ADMS push (no browser session)
            'iclock/*',            // Root ADMS push for domain-only firmware (K50/K40 — SN-identified)
        ]);
        $middleware->alias([
            'company' => \App\Http\Middleware\CompanyIsolation::class,
            'role' => \App\Http\Middleware\RoleMiddleware::class,
            'rate_limit_company' => \App\Http\Middleware\RateLimitByCompany::class,
            'pos.auth' => \App\Http\Middleware\PosAuth::class,
            'fbrpos.auth' => \App\Http\Middleware\FbrPosAuth::class,
            'admin.auth' => \App\Http\Middleware\AdminAuth::class,
            'agent.auth' => \App\Http\Middleware\AgentAuth::class,
            'franchise.auth' => \App\Http\Middleware\FranchiseAuth::class,
            'plan.limit' => \App\Http\Middleware\CheckPlanLimit::class,
            'company.approval' => \App\Http\Middleware\CheckCompanyApproval::class,
            'restaurant.only' => \App\Http\Middleware\RestaurantOnly::class,
            'feature' => \App\Http\Middleware\FeatureEnabled::class,
        ]);

        // VIEW-ONLY "View as Company" enforcement. Must sit on the web group (runs
        // AFTER StartSession) — a global append would run before sessions boot and
        // silently no-op.
        $middleware->web(append: [
            \App\Http\Middleware\ReadOnlyImpersonation::class,
            \App\Http\Middleware\LogImpersonatedWrites::class,
            // Consultant "switch into client": re-validates client consent on
            // every request while the switch flag is set; forces exit when the
            // link is revoked mid-session. Same StartSession constraint as above.
            \App\Http\Middleware\ConsultantSwitchGuard::class,
            \App\Http\Middleware\SetPosLocale::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // ── DB unreachable (shared-host MySQL blips) → friendly fail-fast page ──
        // ONLY connection failures are intercepted (DatabaseDown gate); every
        // ordinary query error returns null here and keeps default handling.
        // The db-down view is fully self-contained — NO auth()/session/DB calls
        // (the DB is down while it renders). 503 + Retry-After so clients and
        // crawlers treat it as temporary.
        //
        // Deploy-window exception (Task 736): the 15 Aug 2026 ~04:49 UTC "503
        // burst" during a deploy was THIS renderable (content-negotiated
        // 2037B HTML / 603B JSON bodies in the access log — a maintenance 503
        // serves one uniform body), fired when a concurrent recovery briefly
        // broke the DB config while the deploy lock was held. If a deploy is
        // in progress (lock at ~/.taxnest-deploy.lock held by flock), browsers
        // get the friendly 200 "Updating…" page instead of a 503. JSON/API
        // clients keep the honest 503 (desktop agents must never mistake a
        // down window for success).
        $deployInProgress = function (): bool {
            $lockFile = dirname(base_path()) . '/.taxnest-deploy.lock';
            if (! is_file($lockFile)) {
                return false;
            }
            $fh = @fopen($lockFile, 'r');
            if (! $fh) {
                return false;
            }
            $free = flock($fh, LOCK_SH | LOCK_NB); // deploy scripts hold LOCK_EX
            if ($free) {
                flock($fh, LOCK_UN);
            }
            fclose($fh);
            return ! $free;
        };
        $dbDownResponse = function (\Throwable $e, $request) use ($deployInProgress) {
            if (! DatabaseDown::isConnectionFailure($e)) {
                return null;
            }
            if ($request->expectsJson()) {
                return response()->json([
                    'error' => __('pos.db_down_title') . ' — ' . __('pos.db_down_body'),
                    'db_down' => true,
                ], 503, ['Retry-After' => '15']);
            }
            if ($deployInProgress()) {
                return response()->view('errors.deploying', [], 200, ['Refresh' => '4']);
            }
            return response()->view('errors.db-down', [], 503, ['Retry-After' => '15']);
        };
        $exceptions->renderable(fn (QueryException $e, $request) => $dbDownResponse($e, $request));
        $exceptions->renderable(fn (\PDOException $e, $request) => $dbDownResponse($e, $request));

        $exceptions->renderable(function (NotFoundHttpException $e, $request) {
            if ($request->expectsJson()) {
                return response()->json(['error' => 'Resource not found.'], 404);
            }

            $previous = $e->getPrevious();
            $modelName = 'Page';
            if ($previous instanceof ModelNotFoundException) {
                $model = class_basename($previous->getModel());
                $modelName = match($model) {
                    'Invoice' => 'Invoice',
                    'Product' => 'Product',
                    'Company' => 'Company',
                    'Branch' => 'Branch',
                    'CustomerProfile' => 'Customer Profile',
                    default => $model,
                };
            }

            $message = "{$modelName} not found or has been deleted.";
            $path = $request->path();

            // Keep each panel isolated — a POS / FBR-POS / admin not-found must never
            // dump the user onto the Digital Invoice dashboard (cross-panel leak when
            // multiple guards share one session). Redirect within the active panel.
            if (str_starts_with($path, 'pos/') || $path === 'pos') {
                return redirect(auth('pos')->check() ? '/pos/dashboard' : '/pos/login')->with('error', $message);
            }
            if (str_starts_with($path, 'fbr-pos/') || $path === 'fbr-pos') {
                return redirect(auth('fbrpos')->check() ? '/fbr-pos/dashboard' : '/fbr-pos/login')->with('error', $message);
            }
            if (str_starts_with($path, 'admin/') || $path === 'admin') {
                return redirect(auth('admin')->check() ? '/admin/dashboard' : '/admin/login')->with('error', $message);
            }

            $redirectTo = auth()->check() ? '/dashboard' : '/';
            return redirect($redirectTo)->with('error', $message);
        });

        $exceptions->renderable(function (MethodNotAllowedHttpException $e, $request) {
            if ($request->expectsJson()) {
                return response()->json(['error' => 'Method not allowed.'], 405);
            }

            $redirectTo = auth()->check() ? '/dashboard' : '/';
            return redirect($redirectTo)->with('error', 'This page cannot be accessed directly.');
        });

        // CSRF token failures (419 "Page Expired"). Laravel's prepareException()
        // converts the underlying TokenMismatchException into a generic HttpException(419)
        // BEFORE the render callbacks run, so a callback type-hinted on
        // TokenMismatchException never fires — we must catch the HttpException and gate on
        // the 419 status here. Anything that is not a 419 is passed through untouched
        // (return null → the framework's default handling / other callbacks take over).
        $exceptions->renderable(function (HttpException $e, $request) {
            if ($e->getStatusCode() !== 419) {
                return null;
            }

            if ($request->expectsJson()) {
                return response()->json(['error' => 'Session expired. Please refresh and try again.'], 419);
            }

            $path = $request->path();
            if (str_starts_with($path, 'admin/') || str_starts_with($path, 'admin')) {
                if (auth('admin')->check()) {
                    return redirect()->back()->with('error', 'Your session expired. Please try again.');
                }
                return redirect('/admin/login')->with('error', 'Session expired. Please log in again.');
            }
            if (str_starts_with($path, 'pos/') || str_starts_with($path, 'pos')) {
                if (auth('pos')->check()) {
                    return redirect()->back()->with('error', 'Your session expired. Please try again.');
                }
                return redirect('/pos/login')->with('error', 'Session expired. Please log in again.');
            }
            if (str_starts_with($path, 'fbr-pos/') || str_starts_with($path, 'fbr-pos')) {
                if (auth('fbrpos')->check()) {
                    return redirect()->back()->with('error', 'Your session expired. Please try again.');
                }
                return redirect('/fbr-pos/login')->with('error', 'Session expired. Please log in again.');
            }

            if (auth()->check()) {
                return redirect()->back()->with('error', 'Your session expired. Please try again.');
            }
            return redirect('/login')->with('error', 'Session expired. Please log in again.');
        });
    })->create();
