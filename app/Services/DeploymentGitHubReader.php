<?php

namespace App\Services;

use App\Exceptions\DeploymentGitHubUnavailable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/** Read-only GitHub validation. Never retries a claim, merge or dispatch. */
class DeploymentGitHubReader
{
    public function get(string $path, string $stage): Response
    {
        try {
            $response = Http::withHeaders([
                'Accept' => 'application/vnd.github+json',
                'User-Agent' => 'TaxNest-owner-approval-relay',
                'X-GitHub-Api-Version' => '2022-11-28',
            ])->connectTimeout(2)->timeout(10)
                ->retry(3, 200, fn ($exception) => $exception instanceof ConnectionException
                    || ($exception instanceof RequestException && $exception->response->serverError()), throw: false)
                ->get('https://api.github.com/repos/'.OwnerDeploymentApprovalService::REPOSITORY.'/'.$path);
        } catch (ConnectionException) {
            throw new DeploymentGitHubUnavailable($stage);
        }

        if (!$response->successful()) {
            $retryAfter = 30;
            $delay = $response->header('Retry-After');
            $reset = $response->header('X-RateLimit-Reset');
            if (is_string($delay) && ctype_digit($delay)) {
                $retryAfter = max(30, min(3600, (int) $delay));
            } elseif ($response->header('X-RateLimit-Remaining') === '0'
                && is_string($reset) && ctype_digit($reset)) {
                $retryAfter = max(30, min(3600, (int) $reset - time()));
            }
            // Never include upstream bodies, URLs, tokens or exception text.
            throw new DeploymentGitHubUnavailable($stage, $response->status(), $retryAfter);
        }

        return $response;
    }
}
