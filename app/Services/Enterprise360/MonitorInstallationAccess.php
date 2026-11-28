<?php

declare(strict_types=1);

namespace App\Services\Enterprise360;

interface MonitorInstallationAccess
{
    /**
     * Resolve the verified installation grants for the actor on the server.
     *
     * @return list<positive-int>
     */
    public function readableInstallationIds(int $actorId): array;
}
