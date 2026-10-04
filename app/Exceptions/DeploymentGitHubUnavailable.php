<?php

namespace App\Exceptions;

class DeploymentGitHubUnavailable extends \InvalidArgumentException
{
    public function __construct(
        public readonly string $stage,
        public readonly ?int $upstreamStatus = null,
        public readonly int $retryAfter = 30,
    ) {
        parent::__construct('GitHub deployment validation unavailable ('.$stage.', '.
            ($upstreamStatus === null ? 'connection failure' : 'HTTP '.$upstreamStatus).
            '). No merge or deployment was authorized.');
    }
}
