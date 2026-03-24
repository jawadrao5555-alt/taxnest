<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Session\TokenMismatchException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->trustProxies(at: '*');
        $middleware->prepend(\App\Http\Middleware\ForceHttps::class);
        $middleware->append(\App\Http\Middleware\SecurityHeaders::class);
        $middleware->validateCsrfTokens(except: [
            'pos/invoice/store',
            'pos/api/draft/save',
            'pos/api/invoice/*/lock',
            'pos/api/invoice/*/unlock',
        ]);
        $middleware->alias([
            'company' => \App\Http\Middleware\CompanyIsolation::class,
            'role' => \App\Http\Middleware\RoleMiddleware::class,
            'rate_limit_company' => \App\Http\Middleware\RateLimitByCompany::class,
            'pos.auth' => \App\Http\Middleware\PosAuth::class,
            'admin.auth' => \App\Http\Middleware\AdminAuth::class,
            'franchise.auth' => \App\Http\Middleware\FranchiseAuth::class,
            'plan.limit' => \App\Http\Middleware\CheckPlanLimit::class,
            'company.approval' => \App\Http\Middleware\CheckCompanyApproval::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
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

            $redirectTo = auth()->check() ? '/dashboard' : '/';
            return redirect($redirectTo)->with('error', "{$modelName} not found or has been deleted.");
        });

        $exceptions->renderable(function (MethodNotAllowedHttpException $e, $request) {
            if ($request->expectsJson()) {
                return response()->json(['error' => 'Method not allowed.'], 405);
            }

            $redirectTo = auth()->check() ? '/dashboard' : '/';
            return redirect($redirectTo)->with('error', 'This page cannot be accessed directly.');
        });

        $exceptions->renderable(function (TokenMismatchException $e, $request) {
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

            if (auth()->check()) {
                return redirect()->back()->with('error', 'Your session expired. Please try again.');
            }
            return redirect('/login')->with('error', 'Session expired. Please log in again.');
        });
    })->create();
