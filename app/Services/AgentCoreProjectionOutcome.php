<?php

namespace App\Services;

final class AgentCoreProjectionOutcome
{
    public function __construct(
        public readonly string $status,
        public readonly array $result,
        public readonly ?string $error = null,
        public readonly ?string $dependency = null,
    ) {
    }

    public function isAcknowledged(): bool
    {
        return in_array($this->status, ['accepted', 'projected'], true);
    }
}