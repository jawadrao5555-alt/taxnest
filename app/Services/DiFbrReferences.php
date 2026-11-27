<?php

namespace App\Services;

use App\Models\Company;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

final class DiFbrReferences
{
    private const URL = 'https://gw.fbr.gov.pk/pdi/';

    public function __construct(private FbrService $fbr) {}

    /**
     * Null means a lookup could not be verified. An empty list means FBR
     * returned no choices or the selected parent has no unambiguous match.
     */
    public function options(Company $company, string $date, string $province, array $line): array
    {
        $options = ['rates' => null, 'schedules' => null, 'serials' => null, 'units' => null];
        $token = $this->fbr->resolveDiToken($company);
        if ($token === '') {
            return $options;
        }

        $context = [$company->id, $company->fbr_environment, hash('sha256', $token)];
        $fetch = fn (string $path, string $id, string $label, array $query = []) =>
            $this->lookup($context, $token, $path, $id, $label, $query);

        $options['units'] = $this->unitOptions($context, $token, $line['hs'] ?? '');

        $provinces = $fetch('v1/provinces', 'stateprovincecode', 'stateprovincedesc');
        $types = $fetch('v1/transtypecode', 'transaction_type_id', 'transaction_desc');
        if ($provinces === null || $types === null) {
            return $options;
        }

        $origin = $this->matchingId($provinces, fn ($label) => $this->fbr->normalizeProvince($label) === $province);
        $saleType = $this->saleTypeKey($line['sale_type'] ?? '');
        $type = $this->matchingId($types, fn ($label) => $this->saleTypeKey($label) === $saleType);
        if (!$origin || !$type) {
            return $options;
        }

        $fbrDate = Carbon::parse($date)->format('d-M-Y');
        $options['rates'] = $fetch('v2/SaleTypeToRate', 'rate_id', 'rate_desc', [
            'date' => $fbrDate, 'transTypeId' => $type, 'originationSupplier' => $origin,
        ]);
        if ($options['rates'] === null) {
            return $options;
        }

        $rate = $this->matchingId($options['rates'], fn ($label) => $this->rateKey($label) === $this->rateKey($line['rate'] ?? ''));
        if (!$rate) {
            $options['schedules'] = $options['serials'] = [];
            return $options;
        }

        $options['schedules'] = $fetch('v1/SroSchedule', 'sro_id', 'sro_desc', [
            'rate_id' => $rate, 'date' => $fbrDate, 'origination_supplier_csv' => $origin,
        ]);
        if ($options['schedules'] !== null) {
            $schedule = $this->matchingId($options['schedules'], fn ($label) =>
                strcasecmp($label, trim($line['schedule'] ?? '')) === 0);
            $options['serials'] = $schedule ? $fetch('v2/SROItem', 'sro_item_id', 'sro_item_desc', [
                'date' => $date, 'sro_id' => $schedule,
            ]) : [];
        }

        return $options;
    }

    public function errors(Company $company, string $date, string $province, array $payload): array
    {
        $rate = (string) ($payload['rate'] ?? '');
        $schedule = trim((string) ($payload['sroScheduleNo'] ?? ''));
        $serial = trim((string) ($payload['sroItemSerialNo'] ?? ''));
        $options = $this->options($company, $date, $province, [
            'hs' => $payload['hsCode'] ?? '', 'sale_type' => $payload['saleType'] ?? '',
            'rate' => $rate, 'schedule' => $schedule,
        ]);

        if ($options['rates'] === null || $options['units'] === null
            || ($schedule !== '' && $options['schedules'] === null)
            || ($serial !== '' && $options['serials'] === null)) {
            return ['FBR references could not be verified. Keep the draft and retry the lookup before submitting.'];
        }

        $errors = [];
        if (!$this->matchingId($options['rates'], fn ($label) => $this->rateKey($label) === $this->rateKey($rate))) {
            $errors[] = 'The rate is not confirmed for this sale type, seller province and invoice date.';
        }
        if (!$this->matchingId($options['units'], fn ($label) => strcasecmp($label, $payload['uoM'] ?? '') === 0)) {
            $errors[] = 'The reporting unit is not confirmed for this HS code.';
        }
        if ($schedule !== '' && !$this->matchingId($options['schedules'], fn ($label) => strcasecmp($label, $schedule) === 0)) {
            $errors[] = 'The Schedule/SRO is not confirmed for this rate.';
        }
        if ($serial !== '' && !$this->matchingId($options['serials'], fn ($label) => strcasecmp($label, $serial) === 0)) {
            $errors[] = 'The serial is not confirmed for this Schedule/SRO.';
        }

        return $errors;
    }

    public function payloadUnit(Company $company, string $hs, string $unit): string
    {
        $token = $this->fbr->resolveDiToken($company);
        if ($token === '') {
            return $this->fbr->normalizeUom($unit);
        }

        $context = [$company->id, $company->fbr_environment, hash('sha256', $token)];
        $labels = array_column($this->unitOptions($context, $token, $hs) ?? [], 'label');
        $exact = $this->fbr->matchValidUom($labels, $unit);
        if ($exact !== null) {
            return $exact;
        }

        $normalized = $this->fbr->normalizeUom($unit);
        $aliases = array_values(array_unique(array_filter($labels, fn ($label) => $this->fbr->normalizeUom($label) === $normalized)));
        return count($aliases) === 1 ? $aliases[0] : $normalized;
    }

    private function unitOptions(array $context, string $token, string $hs): ?array
    {
        $hs = preg_replace('/\D/', '', $hs);
        if (strlen($hs) !== 8) {
            return [];
        }

        // Domestic Sales uses the same annexure as the existing DI resolver.
        return $this->lookup($context, $token, 'v2/HS_UOM', 'uom_id', 'description', [
            'hs_code' => substr($hs, 0, 4).'.'.substr($hs, 4), 'annexure_id' => 3,
        ]);
    }

    private function lookup(array $context, string $token, string $path, string $idKey, string $labelKey, array $query): ?array
    {
        $key = 'di-fbr-ref:'.hash('sha256', json_encode([$context, $path, $query], JSON_THROW_ON_ERROR));
        if (is_array($cached = Cache::get($key))) {
            return $cached;
        }

        try {
            $response = Http::withToken($token)->acceptJson()->connectTimeout(2)->timeout(4)
                ->withoutRedirecting()->get(self::URL.$path, $query);
            $data = $response->successful() ? $response->json() : null;
        } catch (\Illuminate\Http\Client\ConnectionException) {
            return null;
        }

        if (!is_array($data) || !array_is_list($data) || count($data) > 5000) {
            return null;
        }

        $rows = [];
        foreach ($data as $row) {
            if (!is_array($row)) {
                return null;
            }
            $row = array_change_key_case($row);
            $id = filter_var($row[$idKey] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            $label = is_string($row[$labelKey] ?? null) ? trim($row[$labelKey]) : '';
            if (!$id || $label === '' || strlen($label) > 250) {
                return null;
            }
            $rows[] = ['id' => $id, 'label' => $label];
        }

        Cache::put($key, $rows, now()->addHour());
        return $rows;
    }

    private function matchingId(array $rows, callable $matches): ?int
    {
        $ids = [];
        foreach ($rows as $row) {
            if ($matches($row['label'])) {
                $ids[$row['id']] = true;
            }
        }

        return count($ids) === 1 ? array_key_first($ids) : null;
    }

    private function saleTypeKey(string $label): string
    {
        $label = strtolower(trim($label));
        return match ($label) {
            'goods at standard rate' => 'goods at standard rate (default)',
            'goods at zero rate', 'zero rated' => 'goods at zero-rate',
            'exempt', 'goods exempt' => 'exempt goods',
            'services at standard rate' => 'services',
            'cement /concrete block' => 'cement/concrete block',
            default => $label,
        };
    }

    private function rateKey(string $label): string
    {
        $label = strtolower(trim($label));
        return preg_match('/^([0-9]+(?:\.[0-9]+)?)\s*%$/D', $label, $match)
            ? (float) $match[1].'%' : $label;
    }
}
