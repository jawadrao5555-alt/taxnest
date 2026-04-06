<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

class SroSuggestionService
{
    private static array $sroDatabase = [
        '3rd_schedule' => [
            'default' => ['sro' => '3rd Schedule goods', 'serial' => '54', 'description' => '3rd Schedule - Sales Tax Act 1990'],
            'rates' => [
                17 => ['sro' => '3rd Schedule goods', 'serial' => '54', 'description' => '3rd Schedule items at 17% reduced rate'],
                16 => ['sro' => '3rd Schedule goods', 'serial' => '54', 'description' => '3rd Schedule items at 16% reduced rate'],
                15 => ['sro' => '3rd Schedule goods', 'serial' => '54', 'description' => '3rd Schedule items at 15% reduced rate'],
                12 => ['sro' => '3rd Schedule goods', 'serial' => '49', 'description' => '3rd Schedule items at 12% concessionary rate'],
                10 => ['sro' => '3rd Schedule goods', 'serial' => '49', 'description' => '3rd Schedule items at 10% concessionary rate'],
                5 => ['sro' => '3rd Schedule goods', 'serial' => '48', 'description' => '3rd Schedule items at 5% concessionary rate'],
            ],
        ],
        'exempt' => [
            'default' => ['sro' => '6th Schedule', 'serial' => '1', 'description' => '6th Schedule - Exempt from Sales Tax'],
            'hs_prefixes' => [
                '01' => ['sro' => '6th Schedule', 'serial' => '1', 'description' => 'Live animals - 6th Schedule exempt'],
                '02' => ['sro' => '6th Schedule', 'serial' => '2', 'description' => 'Meat - 6th Schedule exempt'],
                '04' => ['sro' => '6th Schedule', 'serial' => '3', 'description' => 'Dairy - 6th Schedule exempt'],
                '10' => ['sro' => '6th Schedule', 'serial' => '5', 'description' => 'Cereals - 6th Schedule exempt'],
                '11' => ['sro' => '6th Schedule', 'serial' => '6', 'description' => 'Milling products - 6th Schedule exempt'],
                '29' => ['sro' => '6th Schedule', 'serial' => '13', 'description' => 'Organic chemicals - 6th Schedule exempt'],
                '30' => ['sro' => '6th Schedule', 'serial' => '25', 'description' => 'Pharmaceutical products - 6th Schedule exempt'],
                '31' => ['sro' => '6th Schedule', 'serial' => '27', 'description' => 'Fertilizers - 6th Schedule exempt'],
                '38' => ['sro' => '6th Schedule', 'serial' => '25', 'description' => 'Chemical products - 6th Schedule exempt'],
            ],
        ],
        'zero_rated' => [
            'default' => ['sro' => '5th Schedule', 'serial' => '1', 'description' => '5th Schedule - Zero Rated Supply'],
        ],
        'reduced' => [
            'default' => ['sro' => '8th Schedule', 'serial' => '1', 'description' => 'Reduced rate supply under 8th Schedule'],
        ],
    ];

    private static function normalizeHsCode(string $hsCode): string
    {
        return trim($hsCode);
    }

    private static function lookupFromDatabase(?string $hsCode, string $scheduleType): ?array
    {
        if (empty($hsCode)) return null;

        try {
            $normalized = self::normalizeHsCode($hsCode);

            $mapping = DB::table('hs_code_mappings')
                ->where('hs_code', $normalized)
                ->where('sale_type', $scheduleType)
                ->where('is_active', true)
                ->where('sro_applicable', true)
                ->orderBy('priority')
                ->first();

            if ($mapping && $mapping->sro_number) {
                $serial = '';
                if ($mapping->serial_number_applicable && $mapping->serial_number_value) {
                    $serial = $mapping->serial_number_value;
                }

                return [
                    'sro' => $mapping->sro_number,
                    'serial' => $serial,
                    'description' => $mapping->label ?? 'Admin-managed mapping',
                    'confidence' => 'high',
                    'auto_fill' => true,
                    'source' => 'admin_mapping',
                ];
            }
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::warning('HS mapping DB lookup failed: ' . $e->getMessage());
        }

        return null;
    }

    public static function suggest(string $scheduleType, ?float $taxRate = null, ?string $hsCode = null, float $standardTaxRate = 18.0): ?array
    {
        if ($hsCode) {
            $dbMapping = self::lookupFromDatabase($hsCode, $scheduleType);
            if ($dbMapping) {
                return $dbMapping;
            }
        }

        if ($scheduleType === 'standard') {
            if ($hsCode) {
                $learned = HsUsagePatternService::getSroSuggestionForHs($hsCode, 'standard');
                if ($learned) {
                    return [
                        'sro' => $learned['sro_schedule_no'],
                        'serial' => $learned['serial_no'],
                        'description' => 'Learned from previous successful invoices',
                        'confidence' => 'learned',
                        'auto_fill' => true,
                        'source' => 'learned',
                    ];
                }
            }
            return null;
        }

        if ($hsCode) {
            $learned = HsUsagePatternService::getSroSuggestionForHs($hsCode, $scheduleType);
            if ($learned) {
                return [
                    'sro' => $learned['sro_schedule_no'],
                    'serial' => $learned['serial_no'],
                    'description' => 'Learned from previous successful invoices',
                    'confidence' => 'high',
                    'auto_fill' => true,
                    'source' => 'learned',
                ];
            }
        }

        $scheduleData = self::$sroDatabase[$scheduleType] ?? null;
        if (!$scheduleData) {
            return null;
        }

        if ($scheduleType === '3rd_schedule' && $taxRate !== null && $taxRate < $standardTaxRate) {
            $roundedRate = (int) round($taxRate);
            if (isset($scheduleData['rates'][$roundedRate])) {
                $suggestion = $scheduleData['rates'][$roundedRate];
                $suggestion['confidence'] = 'high';
                $suggestion['auto_fill'] = true;
                return $suggestion;
            }
            $suggestion = $scheduleData['default'];
            $suggestion['confidence'] = 'medium';
            $suggestion['auto_fill'] = true;
            return $suggestion;
        }

        if ($scheduleType === 'exempt' && $hsCode) {
            $prefix = substr(preg_replace('/[^0-9]/', '', $hsCode), 0, 2);
            if (isset($scheduleData['hs_prefixes'][$prefix])) {
                $suggestion = $scheduleData['hs_prefixes'][$prefix];
                $suggestion['confidence'] = 'high';
                $suggestion['auto_fill'] = true;
                return $suggestion;
            }
        }

        $suggestion = $scheduleData['default'];
        $suggestion['confidence'] = 'low';
        $suggestion['auto_fill'] = false;
        return $suggestion;
    }

    public static function suggestForItems(array $items): array
    {
        $suggestions = [];

        foreach ($items as $index => $item) {
            $scheduleType = $item['schedule_type'] ?? 'standard';
            $taxRate = isset($item['tax_rate']) ? floatval($item['tax_rate']) : null;
            $hsCode = $item['hs_code'] ?? null;

            $suggestion = self::suggest($scheduleType, $taxRate, $hsCode);

            if ($suggestion) {
                $suggestions[$index] = $suggestion;
            }
        }

        return $suggestions;
    }

    public static function getApiResponse(string $scheduleType, ?float $taxRate = null, ?string $hsCode = null, float $standardTaxRate = 18.0): array
    {
        $suggestion = self::suggest($scheduleType, $taxRate, $hsCode, $standardTaxRate);

        if (!$suggestion) {
            return ['has_suggestion' => false];
        }

        return [
            'has_suggestion' => true,
            'sro_schedule_no' => $suggestion['sro'],
            'serial_no' => $suggestion['serial'],
            'description' => $suggestion['description'],
            'confidence' => $suggestion['confidence'],
            'auto_fill' => $suggestion['auto_fill'],
            'source' => $suggestion['source'] ?? 'rules',
            'note' => 'This is a suggestion only. Please verify the SRO reference before submission.',
        ];
    }
}
