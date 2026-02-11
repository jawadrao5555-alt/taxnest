<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Support\Facades\Event;
use App\Services\SecurityLogService;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
    }

    public function boot(): void
    {
        Event::listen(Failed::class, function (Failed $event) {
            SecurityLogService::log('failed_login', null, [
                'email' => $event->credentials['email'] ?? 'unknown',
            ]);
        });

        Event::listen(Login::class, function (Login $event) {
            SecurityLogService::log('login', $event->user->id);
        });
    }
}
