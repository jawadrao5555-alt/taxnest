<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Database\Eloquent\ModelNotFoundException;
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
        $middleware->alias([
            'company' => \App\Http\Middleware\CompanyIsolation::class,
            'role' => \App\Http\Middleware\RoleMiddleware::class,
            'rate_limit_company' => \App\Http\Middleware\RateLimitByCompany::class,
            'pos.auth' => \App\Http\Middleware\PosAuth::class,
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
    })->create();
