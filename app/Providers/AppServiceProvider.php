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
