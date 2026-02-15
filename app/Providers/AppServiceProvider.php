<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Services\SecurityLogService;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
    }

    public function boot(): void
    {
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
