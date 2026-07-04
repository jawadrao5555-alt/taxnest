<?php

namespace App\Services;

use App\Models\RegisteredCredential;
use Illuminate\Support\Facades\DB;

/**
 * Persistent ledger of every credential ever used to open an account.
 *
 * Public registration is blocked when a submitted credential already exists here,
 * so a person cannot create a second free-trial account and is pushed to subscribe.
 * Admin-created accounts are recorded but NOT blocked (admin is the recovery path).
 */
class CredentialLedgerService
{
    /** Canonicalise a value so the same real credential always matches. */
    public static function normalize(string $type, ?string $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $value = trim($value);
        if ($value === '') {
            return null;
        }

        $norm = match ($type) {
            'email', 'username' => mb_strtolower($value),
            'phone', 'cnic'     => preg_replace('/[^0-9]/', '', $value),
            'ntn'               => strtoupper(preg_replace('/[^0-9A-Za-z]/', '', $value)),
            default             => $value,
        };

        return ($norm === null || $norm === '') ? null : $norm;
    }

    /**
     * Return the FIRST credential type already present in the ledger, or null when
     * every supplied credential is brand new.
     *
     * @param array<string,?string> $creds e.g. ['email'=>.., 'phone'=>.., 'ntn'=>.., 'username'=>..]
     */
    public static function firstUsed(array $creds): ?string
    {
        $pairs = [];
        foreach ($creds as $type => $raw) {
            $norm = self::normalize($type, $raw);
            if ($norm !== null) {
                $pairs[$type] = $norm;
            }
        }
        if (empty($pairs)) {
            return null;
        }

        $hit = RegisteredCredential::query()
            ->where(function ($q) use ($pairs) {
                foreach ($pairs as $type => $value) {
                    $q->orWhere(function ($qq) use ($type, $value) {
                        $qq->where('credential_type', $type)
                           ->where('credential_value', $value);
                    });
                }
            })
            ->first();

        return $hit?->credential_type;
    }

    /**
     * Record every supplied credential. Duplicates are ignored (relies on the
     * unique index), so this is safe to call for admin creation and re-runs.
     *
     * @param array<string,?string> $creds
     */
    public static function record(array $creds, ?int $companyId = null, ?string $productType = null): void
    {
        $now = now();
        $rows = [];
        foreach ($creds as $type => $raw) {
            $norm = self::normalize($type, $raw);
            if ($norm === null) {
                continue;
            }
            $rows[] = [
                'credential_type'  => $type,
                'credential_value' => mb_substr($norm, 0, 191),
                'product_type'     => $productType,
                'company_id'       => $companyId,
                'created_at'       => $now,
            ];
        }
        if ($rows) {
            DB::table('registered_credentials')->insertOrIgnore($rows);
        }
    }

    /**
     * Map a used credential type to [form field, user-facing message] for a
     * friendly, field-attached validation error.
     *
     * @return array{0:string,1:string}
     */
    public static function rejectionFor(string $type): array
    {
        $labels = [
            'email'    => 'email address',
            'phone'    => 'phone number',
            'ntn'      => 'NTN',
            'username' => 'username',
            'cnic'     => 'CNIC',
        ];
        $fields = [
            'email'    => 'email',
            'phone'    => 'phone',
            'ntn'      => 'company_ntn',
            'username' => 'username',
            'cnic'     => 'cnic',
        ];

        $label = $labels[$type] ?? 'detail';
        $field = $fields[$type] ?? 'email';
        $message = "This {$label} has already been used to create an account. A new free trial is not allowed — please log in to your existing account or choose a subscription plan.";

        return [$field, $message];
    }
}
