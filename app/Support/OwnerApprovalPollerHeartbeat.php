<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;

/**
 * Durable last-success marker for Approval Relay Dispatch.
 *
 * Recorded on every authenticated OIDC dispatch-claims call, including empty
 * {"claims":[]}. This is how TaxNest Admin distinguishes "GitHub never polled"
 * from "the poller is alive and there is simply nothing to lease".
 *
 * No GitHub PAT or App installation token is stored. Immediate dispatch would
 * require an owner security decision to add one.
 */
final class OwnerApprovalPollerHeartbeat
{
    public const CACHE_KEY = 'owner_approval.poller.last_success';
    public const STORAGE_PATH = 'owner-approval/poller-heartbeat.json';

    public static function record(
        ?CarbonInterface $at = null,
        int $runId = 0,
        int $runAttempt = 0
    ): CarbonImmutable {
        $recorded = CarbonImmutable::parse(($at ?? now())->toIso8601String());
        $payload = [
            'recorded_at' => $recorded->toIso8601String(),
            'run_id' => max(0, $runId),
            'run_attempt' => max(0, $runAttempt),
        ];

        Cache::forever(self::CACHE_KEY, $payload);
        try {
            Storage::disk('local')->put(self::STORAGE_PATH, json_encode($payload, JSON_THROW_ON_ERROR));
        } catch (\Throwable) {
            // Cache remains the live source. File persistence is best-effort.
        }

        return $recorded;
    }

    public static function snapshot(): array
    {
        $payload = Cache::get(self::CACHE_KEY);
        if (!is_array($payload)) {
            $payload = self::fromStorage();
        }

        $at = self::parseTime(is_array($payload) ? ($payload['recorded_at'] ?? null) : null);

        return [
            'recorded_at' => $at,
            'run_id' => is_array($payload) ? (int) ($payload['run_id'] ?? 0) : 0,
            'run_attempt' => is_array($payload) ? (int) ($payload['run_attempt'] ?? 0) : 0,
        ];
    }

    public static function lastSuccessAt(): ?CarbonInterface
    {
        return self::snapshot()['recorded_at'];
    }

    public static function forget(): void
    {
        Cache::forget(self::CACHE_KEY);
        try {
            Storage::disk('local')->delete(self::STORAGE_PATH);
        } catch (\Throwable) {
        }
    }

    private static function fromStorage(): ?array
    {
        try {
            if (!Storage::disk('local')->exists(self::STORAGE_PATH)) {
                return null;
            }
            $decoded = json_decode((string) Storage::disk('local')->get(self::STORAGE_PATH), true);
            return is_array($decoded) ? $decoded : null;
        } catch (\Throwable) {
            return null;
        }
    }

    private static function parseTime(mixed $raw): ?CarbonImmutable
    {
        if (!is_string($raw) || $raw === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($raw);
        } catch (\Throwable) {
            return null;
        }
    }
}
