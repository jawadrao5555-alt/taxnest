<?php

namespace App\Services;

/** One read-only information hierarchy for Sales and frozen Day Close reports. */
final class PosReportPresentation
{
    public static function forView(?array $history, ?array $split, ?\App\Models\User $viewer, bool $showLocal): ?array
    {
        if ($history !== null) {
            return self::fromHistory($history);
        }
        if (! isset($split['report_groups']) || ! is_array($split['report_groups'])) {
            return null;
        }
        $scope = $viewer?->posBillingScope() ?? 'pra';
        $allowed = $scope === 'local' ? ['local', 'exempt'] : ['pra', 'exempt'];
        if ($scope === 'both' && $showLocal && ! $viewer?->posHidesLocalStream()) {
            $allowed[] = 'local';
        }

        return self::fromFrozen($split['report_groups'], $allowed);
    }

    public static function fromHistory(array $history): array
    {
        return [
            'total' => $history['summary']['net'], 'tax' => $history['summary']['tax'],
            'gross' => $history['summary']['gross'] ?? 0, 'discount' => $history['summary']['discount'] ?? 0, 'returns_count' => $history['summary']['returns'] ?? 0,
            'count' => $history['summary']['sales'], 'returns' => $history['summary']['returned'],
            'provisional' => $history['provisional']['net'], 'complete' => $history['complete'],
            'payments' => $history['payments'], 'order_types' => $history['order_types'],
            'products' => $history['products'], 'streams' => $history['streams'] ?? [],
            'delivery' => $history['delivery'] ?? null,
        ];
    }

    public static function fromFrozen(array $groups, array $allowedStreams): array
    {
        $out = ['total' => 0, 'tax' => 0, 'gross' => 0, 'discount' => 0, 'returns_count' => 0, 'count' => 0, 'returns' => 0, 'provisional' => 0, 'complete' => true,
            'payments' => [], 'order_types' => [], 'products' => [], 'streams' => [], 'delivery' => 0];
        $knownCharges = true;
        $products = [];
        foreach ($groups as $g) {
            if (! in_array($g['stream'] ?? '', $allowedStreams, true)) {
                continue;
            }
            $totals = $g['totals'];
            if (($g['stage'] ?? '') !== 'final') {
                $out['provisional'] += $totals['net'];

                continue;
            }
            foreach (['total' => 'net', 'tax' => 'tax', 'gross' => 'gross', 'discount' => 'discount', 'returns_count' => 'returns', 'count' => 'sales', 'returns' => 'returned'] as $key => $source) {
                $out[$key] += $totals[$source] ?? 0;
            }
            $knownCharges = $knownCharges && array_key_exists('delivery', $totals);
            $out['delivery'] += $totals['delivery'] ?? 0;
            foreach (['payments' => $g['payment_method'], 'streams' => $g['stream']] as $bucket => $key) {
                $out[$bucket][$key] ??= ['net' => 0, 'tax' => 0];
                foreach (['net', 'tax'] as $field) {
                    $out[$bucket][$key][$field] += $totals[$field] ?? 0;
                }
            }
            foreach (($totals['order_types'] ?? ['unknown' => $totals]) as $type => $row) {
                $out['order_types'][$type] ??= ['net' => 0, 'tax' => 0];
                foreach (['net', 'tax'] as $field) {
                    $out['order_types'][$type][$field] += $row[$field] ?? 0;
                }
            }
            foreach ($g['products'] as $row) {
                $key = json_encode(array_intersect_key($row, array_flip(['product_id', 'name', 'category', 'size', 'channel', 'basis'])));
                if (! isset($products[$key])) {
                    $products[$key] = $row;

                    continue;
                }
                foreach (['qty', 'base', 'tax', 'net'] as $field) {
                    $products[$key][$field] = round(($products[$key][$field] ?? 0) + ($row[$field] ?? 0), $field === 'qty' ? 4 : 2);
                }
            }
        }
        foreach (['total', 'tax', 'gross', 'discount', 'returns', 'provisional', 'delivery'] as $key) {
            $out[$key] = round($out[$key], 2);
        }
        $out['products'] = array_values($products);
        if (! $knownCharges) {
            $out['delivery'] = null;
        }

        return $out;
    }
}
