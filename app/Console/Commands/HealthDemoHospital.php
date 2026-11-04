<?php

namespace App\Console\Commands;

use App\Support\DevStagingGuard;
use App\Models\AdminUser;
use Illuminate\Support\Str;
use Database\Seeders\DisposableHospitalSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

final class HealthDemoHospital extends Command
{
    protected $signature = 'health:demo-hospital';
    protected $description = 'Initialize an empty local disposable MariaDB with six fictional hospitals';

    public function handle(): int
    {
        if (!app()->environment('local', 'testing') || (string) env('HOSPITAL_DEMO_ALLOW') !== '1'
            || DevStagingGuard::problems() || Schema::getTables()) {
            $this->error('Refused. Use an empty local disposable MariaDB with HOSPITAL_DEMO_ALLOW=1.');
            return self::FAILURE;
        }

        // Historical data-only migrations insert a named customer and login on fresh installs.
        // Include every schema migration and the real package catalogue, excluding those two.
        $exclude = ['2026_03_26_210000_seed_rao_brothers_company.php', '2026_03_26_220000_fix_rao_brothers_status.php'];
        $paths = array_values(array_filter(glob(database_path('migrations/*.php')),
            fn (string $file) => !in_array(basename($file), $exclude, true)));
        if ($this->call('migrate', ['--path' => $paths, '--realpath' => true]) !== self::SUCCESS) {
            return self::FAILURE;
        }

        // The SaaS foundation migration creates a bootstrap administrator. This database
        // was empty on entry, so repurpose that new row as the synthetic operator.
        AdminUser::query()->sole()->update(['name' => 'Demo SaaS Owner', 'email' => 'demo.saas@example.test',
            'password' => env('HOSPITAL_DEMO_PASSWORD') ?: Str::random(40)]);

        // Keep subsequent local migrate runs from introducing the intentionally skipped tenants.
        $repository = app('migration.repository');
        foreach ($exclude as $filename) {
            $repository->log(pathinfo($filename, PATHINFO_FILENAME), $repository->getNextBatchNumber());
        }

        return $this->call('db:seed', ['--class' => DisposableHospitalSeeder::class]);
    }
}
