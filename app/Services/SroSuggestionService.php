<?php

namespace App\Services;

class SroSuggestionService
{
    private static array $sroDatabase = [
        '3rd_schedule' => [
            'default' => ['sro' => 'SRO 1125(I)/2011', 'serial' => '54', 'description' => '3rd Schedule - Sales Tax Act 1990'],
            'rates' => [
                17 => ['sro' => 'SRO 1125(I)/2011', 'serial' => '54', 'description' => '3rd Schedule items at 17% reduced rate'],
                16 => ['sro' => 'SRO 1125(I)/2011', 'serial' => '54', 'description' => '3rd Schedule items at 16% reduced rate'],
                15 => ['sro' => 'SRO 1125(I)/2011', 'serial' => '54', 'description' => '3rd Schedule items at 15% reduced rate'],
                12 => ['sro' => 'SRO 1125(I)/2011', 'serial' => '49', 'description' => '3rd Schedule items at 12% concessionary rate'],
                10 => ['sro' => 'SRO 1125(I)/2011', 'serial' => '49', 'description' => '3rd Schedule items at 10% concessionary rate'],
                5 => ['sro' => 'SRO 1125(I)/2011', 'serial' => '48', 'description' => '3rd Schedule items at 5% concessionary rate'],
            ],
        ],
        'exempt' => [
            'default' => ['sro' => 'SRO 551(I)/2008', 'serial' => '1', 'description' => '6th Schedule - Exempt from Sales Tax'],
            'hs_prefixes' => [
                '01' => ['sro' => 'SRO 551(I)/2008', 'serial' => '1', 'description' => 'Live animals - 6th Schedule exempt'],
                '02' => ['sro' => 'SRO 551(I)/2008', 'serial' => '2', 'description' => 'Meat - 6th Schedule exempt'],
                '04' => ['sro' => 'SRO 551(I)/2008', 'serial' => '3', 'description' => 'Dairy - 6th Schedule exempt'],
                '10' => ['sro' => 'SRO 551(I)/2008', 'serial' => '5', 'description' => 'Cereals - 6th Schedule exempt'],
                '11' => ['sro' => 'SRO 551(I)/2008', 'serial' => '6', 'description' => 'Milling products - 6th Schedule exempt'],
                '30' => ['sro' => 'SRO 551(I)/2008', 'serial' => '25', 'description' => 'Pharmaceutical products - 6th Schedule exempt'],
            ],
        ],
        'zero_rated' => [
            'default' => ['sro' => 'SRO 1190(I)/2019', 'serial' => '1', 'description' => '5th Schedule - Zero Rated Supply'],
        ],
        'reduced' => [
            'default' => ['sro' => 'SRO 1125(I)/2011', 'serial' => '1', 'description' => 'Reduced rate supply under SRO'],
        ],
    ];

    public static function suggest(string $scheduleType, ?float $taxRate = null, ?string $hsCode = null, float $standardTaxRate = 18.0): ?array
    {
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
