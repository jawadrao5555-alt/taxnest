<?php

namespace App\Console\Commands;

use App\Services\CompanyGreetingService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

class SendOptInGreetings extends Command
{
    protected $signature = 'greetings:dispatch';

    protected $description = 'Prepare and deliver only explicitly opted-in company greetings';

    public function handle(CompanyGreetingService $service): int
    {
        if (! config('greetings.delivery_enabled', false)) {
            $this->info('Greeting delivery is disabled.');

            return self::SUCCESS;
        }
        $service->run(CarbonImmutable::now());

        return self::SUCCESS;
    }
}
