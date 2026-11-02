<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

final class PraDiSandboxGate
{
    public static function available(): bool
    {
        if (!config('pra_di_sandbox.enabled', false)) return false;
        if (app()->environment('testing')) {
            $connection = DB::connection()->getConfig();
            return ($connection['driver'] ?? '') === 'sqlite'
                && ($connection['database'] ?? '') === ':memory:'
                && empty($connection['url']);
        }

        return app()->environment('local') && DevStagingGuard::problems() === [];
    }

    public static function enforce(): void
    {
        abort_unless(self::available(), 404);
    }
}
