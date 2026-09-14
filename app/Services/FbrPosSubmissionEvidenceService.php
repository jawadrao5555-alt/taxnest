<?php

namespace App\Services;

use App\Models\Company;
use App\Models\FbrPosTransaction;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Safe evidence for the FBR POS local-fiscal-device hand-off.
 *
 * This table deliberately stores no invoice payload, token, access code,
 * customer data, full POSID, full fiscal number, or local URL. Its purpose is
 * to distinguish three facts which the old UI collapsed into "Production":
 * TaxNest's requested environment, local IMS acceptance, and independent FBR
 * central/Tax Asaan verification.
 */
class FbrPosSubmissionEvidenceService
{
    public const TABLE = 'fbr_pos_submission_evidence';

    public function available(): bool
    {
        try {
            return Schema::hasTable(self::TABLE);
        } catch (\Throwable $e) {
            return false;
        }
    }

    public function recordDispatch(Company $company, FbrPosTransaction $transaction, ?string $agentVersion = null): void
    {
        if (! $this->available()) {
            return;
        }

        try {
            DB::transaction(function () use ($company, $transaction, $agentVersion) {
                $now = now();
                $row = DB::table(self::TABLE)
                    ->where('transaction_id', $transaction->id)
                    ->lockForUpdate()
                    ->first();
                $posId = $this->normaliseIdentity($company->fbr_pos_id);
                $attrs = [
                    'company_id' => (int) $company->id,
                    'channel' => 'local_fiscal_device',
                    'endpoint_class' => 'local_fbr_ims',
                    'requested_environment' => $this->requestedEnvironment($company),
                    'local_environment_proof' => 'unknown',
                    'pos_id_fingerprint' => $this->fingerprint($posId),
                    'pos_id_mask' => $this->mask($posId),
                    'agent_version' => $this->cleanVersion($agentVersion ?: $company->agent_version),
                    'last_dispatched_at' => $now,
                    'updated_at' => $now,
                ];

                if ($row) {
                    $attrs['dispatch_count'] = ((int) $row->dispatch_count) + 1;
                    DB::table(self::TABLE)->where('id', $row->id)->update($attrs);

                    return;
                }

                DB::table(self::TABLE)->insert($attrs + [
                    'transaction_id' => (int) $transaction->id,
                    'dispatch_count' => 1,
                    'first_dispatched_at' => $now,
                    'result_state' => 'dispatched',
                    'central_verification_state' => 'unknown',
                    'created_at' => $now,
                ]);
            });
        } catch (\Throwable $e) {
            // Evidence must never block an invoice poll during deploy/migration
            // skew. The callback is still fail-closed independently below.
            report($e);
        }
    }

    public function recordResult(
        Company $company,
        FbrPosTransaction $transaction,
        $response,
        ?string $responseCode,
        ?string $fiscalNumber,
        string $resultState,
        ?string $agentVersion = null
    ): void {
        if (! $this->available()) {
            return;
        }

        try {
            $now = now();
            $posId = $this->normaliseIdentity($company->fbr_pos_id);
            $fiscal = $this->normaliseIdentity($fiscalNumber);
            $encoded = is_array($response)
                ? json_encode($response, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
                : null;
            $attrs = [
                'company_id' => (int) $company->id,
                'channel' => 'local_fiscal_device',
                'endpoint_class' => 'local_fbr_ims',
                'requested_environment' => $this->requestedEnvironment($company),
                'local_environment_proof' => 'unknown',
                'pos_id_fingerprint' => $this->fingerprint($posId),
                'pos_id_mask' => $this->mask($posId),
                'agent_version' => $this->cleanVersion($agentVersion ?: $company->agent_version),
                'callback_at' => $now,
                'response_code' => $responseCode === null ? null : mb_substr(trim($responseCode), 0, 40),
                'response_hash' => $encoded === null ? null : hash('sha256', $encoded),
                'fiscal_number_fingerprint' => $this->fingerprint($fiscal),
                'fiscal_number_mask' => $this->mask($fiscal, 4),
                'result_state' => $resultState,
                'central_verification_state' => 'unknown',
                'updated_at' => $now,
            ];

            $existing = DB::table(self::TABLE)->where('transaction_id', $transaction->id)->first();
            if ($existing) {
                DB::table(self::TABLE)->where('id', $existing->id)->update($attrs);

                return;
            }

            DB::table(self::TABLE)->insert($attrs + [
                'transaction_id' => (int) $transaction->id,
                'dispatch_count' => 0,
                'created_at' => $now,
            ]);
        } catch (\Throwable $e) {
            report($e);
        }
    }

    /** Safe, credential-free three-way state for the FBR settings page. */
    public function diagnostics(Company $company): array
    {
        $unknown = [
            'requested_environment' => $this->requestedEnvironment($company),
            'local_environment_proof' => 'unknown',
            'pos_id_state' => 'unknown',
            'pos_id_mask' => $this->mask($this->normaliseIdentity($company->fbr_pos_id)),
            'local_acceptance_state' => 'unknown',
            'central_verification_state' => 'unknown',
            'last_evidence_at' => null,
        ];
        if (! $this->available()) {
            return $unknown;
        }

        try {
            $row = DB::table(self::TABLE)
                ->where('company_id', $company->id)
                ->orderByDesc('last_dispatched_at')
                ->orderByDesc('id')
                ->first();
            if (! $row) {
                return $unknown;
            }

            $current = $this->fingerprint($this->normaliseIdentity($company->fbr_pos_id));
            $stored = $row->pos_id_fingerprint ?: null;
            $posState = (! $current || ! $stored)
                ? 'unknown'
                : (hash_equals($stored, $current) ? 'matches_current' : 'drift');

            return [
                'requested_environment' => $row->requested_environment ?: $unknown['requested_environment'],
                'local_environment_proof' => $row->local_environment_proof ?: 'unknown',
                'pos_id_state' => $posState,
                'pos_id_mask' => $row->pos_id_mask ?: $unknown['pos_id_mask'],
                'local_acceptance_state' => $row->result_state ?: 'unknown',
                'central_verification_state' => $row->central_verification_state ?: 'unknown',
                'last_evidence_at' => $row->callback_at ?: $row->last_dispatched_at,
            ];
        } catch (\Throwable $e) {
            return $unknown;
        }
    }

    public static function requestedEnvironmentLabel(Company $company): string
    {
        $environment = strtolower(trim((string) ($company->fbr_pos_environment ?? 'sandbox')));
        if (($company->fbr_connection_mode ?? 'cloud') === 'fiscal_device') {
            return $environment === 'production'
                ? __('pos.production_requested_unverified')
                : __('pos.sandbox_requested_unverified');
        }

        return $environment === 'production' ? __('pos.production_live') : __('pos.sandbox_testing');
    }

    private function requestedEnvironment(Company $company): string
    {
        return strtolower(trim((string) ($company->fbr_pos_environment ?? 'sandbox'))) === 'production'
            ? 'production'
            : 'sandbox';
    }

    private function normaliseIdentity($value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private function fingerprint(?string $value): ?string
    {
        return $value === null ? null : hash('sha256', $value);
    }

    private function mask(?string $value, int $tail = 2): ?string
    {
        if ($value === null) {
            return null;
        }
        $tail = max(1, min($tail, 6));

        return '***'.mb_substr($value, -$tail);
    }

    private function cleanVersion($version): ?string
    {
        $version = trim((string) $version);
        if ($version === '' || ! preg_match('/^[A-Za-z0-9._+-]{1,40}$/', $version)) {
            return null;
        }

        return $version;
    }
}
