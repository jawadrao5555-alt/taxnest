<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Enterprise360\MonitorInstallationAccess;
use App\Services\Enterprise360\MonitorSalesEvent;
use App\Services\Enterprise360\MonitorSalesProjection;
use App\Services\Enterprise360\MonitorSalesSnapshot;
use DateTimeImmutable;
use Illuminate\Console\Command;

final class PreviewEnterprise360Monitor extends Command
{
    protected $signature = 'enterprise360:preview-monitor';

    protected $description = 'Preview read-only Monitor coverage using synthetic sales evidence';

    public function handle(): int
    {
        if (! $this->laravel->environment(['local', 'testing'])) {
            $this->error('The synthetic Monitor preview is available in local and testing environments.');

            return self::FAILURE;
        }

        $access = new class implements MonitorInstallationAccess
        {
            public function readableInstallationIds(int $actorId): array
            {
                return $actorId === 1 ? [11, 22] : [];
            }
        };

        $start = new DateTimeImmutable('2026-01-01T00:00:00+00:00');
        $end = new DateTimeImmutable('2026-01-02T00:00:00+00:00');
        $captured = $end->modify('+4 minutes');

        $result = (new MonitorSalesProjection($access))->project(
            1,
            $start,
            $end,
            $end->modify('+5 minutes'),
            600,
            new MonitorSalesSnapshot(
                11,
                $start,
                $end,
                $captured,
                true,
                MonitorSalesEvent::native('demo-sale-1', 87500),
                MonitorSalesEvent::fiscalAcknowledgement('demo-sale-1'),
                MonitorSalesEvent::native('demo-return-1', -5000),
            ),
        );

        $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }
}
