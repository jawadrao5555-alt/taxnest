<?php

namespace App\Services;

use App\Models\Company;
use App\Models\Product;

final class DiProductTaxProfile
{
    public const FIELDS = ['hs_code', 'pct_code', 'uom', 'schedule_type', 'default_tax_rate', 'sro_reference', 'serial_number'];

    public function __construct(private DiFbrReferences $references, private FbrService $fbr) {}

    public function errors(Company $company, string $date, string $province, array $defaults): array
    {
        $schedule = $defaults['schedule_type'] ?? 'standard';
        $hs = $defaults['hs_code'];
        if (!preg_match('/^\d{4}\.?\d{4}$/D', $hs)) {
            return ['Enter the full eight-digit HS code.'];
        }

        $rate = (float) $defaults['default_tax_rate'];
        if (in_array($schedule, ['exempt', 'zero_rated'], true) && $rate !== 0.0) {
            return ['Use a zero percentage for this sale type.'];
        }
        $rules = ScheduleEngine::resolveValidationRules($schedule, $rate);
        if (($rules['requires_sro'] && empty($defaults['sro_reference']))
            || ($rules['requires_serial'] && empty($defaults['serial_number']))
            || (!empty($defaults['serial_number']) && empty($defaults['sro_reference']))) {
            return ['Enter the applicable Schedule/SRO and item serial.'];
        }

        return $this->references->errors($company, $date, $province, [
            'hsCode' => $hs,
            'uoM' => $this->references->payloadUnit($company, $hs, $defaults['uom']),
            'saleType' => ScheduleEngine::mapSaleType($schedule),
            'rate' => $schedule === 'exempt' ? 'Exempt' : $rate.'%',
            'sroScheduleNo' => $defaults['sro_reference'] ?? '',
            'sroItemSerialNo' => $defaults['serial_number'] ?? '',
        ]);
    }

    public function verification(Company $company, string $date, array $defaults): array
    {
        $province = $this->fbr->normalizeProvince($company->province);
        $errors = $this->errors($company, $date, $province, $defaults);
        if ($errors) {
            throw \Illuminate\Validation\ValidationException::withMessages(['verify_di_tax' => $errors]);
        }

        return ['date' => $date, 'province' => $province, 'environment' => $company->fbr_environment,
            'fingerprint' => self::fingerprint($defaults)];
    }

    public static function hasVerifiedDefaults(Product $product): bool
    {
        $verification = $product->di_tax_verification;
        return is_array($verification)
            && ($verification['fingerprint'] ?? null) === self::fingerprint($product->only(self::FIELDS));
    }

    private static function fingerprint(array $defaults): string
    {
        $values = [];
        foreach (self::FIELDS as $field) {
            $values[$field] = match ($field) {
                'default_tax_rate' => (float) ($defaults[$field] ?? 0),
                'schedule_type' => $defaults[$field] ?? 'standard',
                default => trim((string) ($defaults[$field] ?? '')),
            };
        }
        return hash('sha256', json_encode($values, JSON_THROW_ON_ERROR));
    }
}
