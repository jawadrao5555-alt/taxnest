<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Services\SecurityLogService;
use App\Models\InvoiceItem;
use App\Observers\InvoiceItemObserver;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
    }

    public function boot(): void
    {
        InvoiceItem::observe(InvoiceItemObserver::class);

        // Rate limit public password-reset endpoints per IP+email to prevent
        // reset-email bombing, account enumeration, and SMTP reputation abuse.
        \Illuminate\Support\Facades\RateLimiter::for('password-reset', function (\Illuminate\Http\Request $request) {
            $email = strtolower((string) $request->input('email', ''));

            return [
                \Illuminate\Cache\RateLimiting\Limit::perMinute(5)->by('pr-ip:' . $request->ip()),
                \Illuminate\Cache\RateLimiting\Limit::perMinute(5)->by('pr:' . $request->ip() . '|' . $email),
            ];
        });

        // Allow any view to be used as an anonymous component (e.g.
        // <x-dynamic-component :component="'pos.archive.layout'"> resolves to
        // resources/views/pos/archive/layout.blade.php). Required by the
        // Archive Portal and Local Bills Portal layouts.
        \Illuminate\Support\Facades\Blade::anonymousComponentPath(resource_path('views'));

        // Admin-saved SMTP settings (SaaS admin → Settings) override .env
        // MAIL_* at runtime. Silent no-op when unset/disabled/DB down.
        \App\Services\SmtpRuntimeConfig::apply();

        $dbDefault = config('database.default');
        Log::info('DB_DRIVER', [
            'default' => $dbDefault,
            'host' => config('database.connections.' . $dbDefault . '.host'),
            'port' => config('database.connections.' . $dbDefault . '.port'),
            'database' => config('database.connections.' . $dbDefault . '.database'),
            'sapi' => php_sapi_name(),
        ]);

        if (app()->environment('production') && $dbDefault !== 'mysql') {
            $msg = 'PRODUCTION DB GUARD: expected mysql, got ' . $dbDefault . '. Aborting.';
            Log::critical($msg);
            if (php_sapi_name() === 'cli') {
                fwrite(STDERR, $msg . PHP_EOL);
                exit(1);
            }
            abort(503, 'Database configuration error.');
        }

        if (app()->environment('production') && php_sapi_name() === 'cli') {
            $command = $_SERVER['argv'][1] ?? '';
            if (in_array($command, ['tinker'])) {
                echo "CLI interactive shell disabled in production environment.\n";
                exit(403);
            }
        }

        if (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https') {
            \Illuminate\Support\Facades\URL::forceScheme('https');
        } elseif (str_starts_with(config('app.url', ''), 'https://')) {
            \Illuminate\Support\Facades\URL::forceScheme('https');
        }

        Event::listen(Failed::class, function (Failed $event) {
            SecurityLogService::log('failed_login', null, [
                'email' => $event->credentials['email'] ?? 'unknown',
            ]);
        });

        Event::listen(Login::class, function (Login $event) {
            SecurityLogService::log('login', $event->user->id);

            // Last-login stamp for company users (web/pos/fbrpos guards).
            // SKIP when a SaaS admin session is active in the same browser —
            // that is the impersonation ("View as Company") flow, which calls
            // auth($guard)->login() and must never look like a real customer
            // login. AdminUser logins are excluded by the instanceof check.
            // Direct DB update: no model events, no updated_at churn.
            try {
                if ($event->user instanceof \App\Models\User
                    && !\Illuminate\Support\Facades\Auth::guard('admin')->check()) {
                    DB::table('users')->where('id', $event->user->id)->update([
                        'last_login_at' => now(),
                        'last_login_ip' => request()->ip(),
                    ]);
                }
            } catch (\Throwable $e) {
                // Column not migrated yet or DB hiccup — never block a login.
                Log::warning('last_login stamp failed: ' . $e->getMessage());
            }

            // Staff Hazri (owner batch, 26 Jul 2026): every REAL pos-guard login
            // writes one attendance row. Impersonation (admin session active)
            // is excluded — SaaS admin ka "View as" kabhi hazri na banaye.
            // Direct DB insert: no model events; failure NEVER blocks a login.
            // Task 558: fbrpos guard bhi included — FBR POS shops ka "online"
            // indicator (Live Activity) inhi session rows se chalta hai. FBR
            // companies kabhi PRA Staff Hazri report mein nahi aatin (report
            // company-scoped hai), is liye table share karna safe hai.
            try {
                if (in_array($event->guard ?? null, ['pos', 'fbrpos'], true)
                    && $event->user instanceof \App\Models\User
                    && $event->user->company_id
                    && !\Illuminate\Support\Facades\Auth::guard('admin')->check()) {
                    DB::table('pos_user_sessions')->insert([
                        'company_id' => $event->user->company_id,
                        'user_id' => $event->user->id,
                        'login_at' => now(),
                        'last_activity_at' => now(),
                        'ip' => request()->ip(),
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            } catch (\Throwable $e) {
                // Table not migrated yet — never block a login.
                Log::warning('hazri login row failed: ' . $e->getMessage());
            }
        });

        if (app()->environment('production')) {
            \Illuminate\Database\Eloquent\Model::preventLazyLoading();
        }

        DB::listen(function ($query) {
            if ($query->time > 300) {
                Log::warning('Slow query detected', [
                    'sql' => $query->sql,
                    'bindings' => $query->bindings,
                    'time_ms' => $query->time,
                    'route' => request()->path(),
                ]);
            }
        });
    }
}
