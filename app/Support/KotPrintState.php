<?php

namespace App\Support;

/**
 * Operator-facing KOT / print-job states. Keys stay stable for tests and
 * Live Ops; labels are English-only on paper but the UI may translate.
 */
final class KotPrintState
{
    public const PRINTING = 'printing';
    public const PRINTED = 'printed';
    public const PENDING = 'pending';
    public const RECOVERED = 'recovered';
    public const ACTION_REQUIRED = 'action_required';

    /**
     * @return array{key: string, label: string, tone: string}
     */
    public static function forJob(object $job): array
    {
        $status = strtolower((string) ($job->status ?? ''));
        $error = (string) ($job->error ?? '');

        return match (true) {
            $status === 'printing', $status === 'local' => [
                'key' => self::PRINTING,
                'label' => $status === 'local' ? 'Printing (local)' : 'Printing',
                'tone' => 'cyan',
            ],
            $status === 'pending' => [
                'key' => self::PENDING,
                'label' => 'Pending',
                'tone' => 'amber',
            ],
            $status === 'expired',
            $status === 'done' && self::isRecoveredError($error) => [
                'key' => self::RECOVERED,
                'label' => 'Recovered',
                'tone' => 'emerald',
            ],
            $status === 'done' => [
                'key' => self::PRINTED,
                'label' => 'Printed',
                'tone' => 'emerald',
            ],
            default => [
                'key' => self::ACTION_REQUIRED,
                'label' => 'Action required',
                'tone' => 'rose',
            ],
        };
    }

    public static function isRecoveredError(string $error): bool
    {
        return str_contains($error, 'Superseded')
            || str_contains($error, 'Shop PC confirmed')
            || str_contains($error, 'cloud printed');
    }

    public static function isActionRequiredError(string $error): bool
    {
        return str_starts_with($error, 'local_agent_unresponsive')
            || str_starts_with($error, 'unconfirmed_after_print_content_fetched');
    }
}
