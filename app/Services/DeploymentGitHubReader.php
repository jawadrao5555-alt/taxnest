<?php

namespace App\Services;

use App\Exceptions\DeploymentGitHubUnavailable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;

/** Read-only GitHub validation. Never retries a claim, merge or dispatch. */
class DeploymentGitHubReader
{
    public function get(string $path, string $stage): Response
    {
        $key = 'deployment-github-cooldown:v1:'.OwnerDeploymentApprovalService::REPOSITORY;
        $cooldown = Cache::get($key);
        if (is_array($cooldown) && ($cooldown['until'] ?? 0) > now()->timestamp) {
            throw new DeploymentGitHubUnavailable($stage, $cooldown['status'], $cooldown['until'] - now()->timestamp);
        }
        try {
            $client = Http::withHeaders([]);
            // Empty configuration preserves public read access; never forward a
            // credential to redirects or any non-GitHub endpoint.
            if ($token = config('deployment_approval.github_read_token')) {
                $client = $client->withToken($token);
            }
            $response = $client->withoutRedirecting()->withHeaders([
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
                $retryAfter = max(30, min(3600, (int) $reset - now()->timestamp));
            }
            if (in_array($response->status(), [403, 429], true)) {
                Cache::put($key, ['status' => $response->status(), 'until' => now()->timestamp + $retryAfter], $retryAfter);
            }
            // Never include upstream bodies, URLs, tokens or exception text.
            throw new DeploymentGitHubUnavailable($stage, $response->status(), $retryAfter);
        }

        return $response;
    }
}
