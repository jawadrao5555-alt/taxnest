<?php

namespace App\Services;

class ScheduleEngine
{
    public static array $scheduleTypes = [
        'standard' => ['label' => 'Standard Rate', 'tax_rate' => 18, 'requires_sro' => false, 'requires_serial' => false, 'requires_mrp' => false],
        'reduced' => ['label' => 'Reduced Rate', 'tax_rate' => 10, 'requires_sro' => true, 'requires_serial' => true, 'requires_mrp' => false],
        '3rd_schedule' => ['label' => '3rd Schedule', 'tax_rate' => 17, 'requires_sro' => false, 'requires_serial' => false, 'requires_mrp' => false],
        'exempt' => ['label' => 'Exempt', 'tax_rate' => 0, 'requires_sro' => true, 'requires_serial' => true, 'requires_mrp' => false],
        'zero_rated' => ['label' => 'Zero Rated', 'tax_rate' => 0, 'requires_sro' => false, 'requires_serial' => false, 'requires_mrp' => false],
    ];

    public static array $hsLookupTable = [
        '15179090' => ['pct_code' => '1517.9090', 'schedule_type' => 'standard', 'tax_rate' => 18],
        '25232900' => ['pct_code' => '2523.2900', 'schedule_type' => 'standard', 'tax_rate' => 18],
        '31021000' => ['pct_code' => '3102.1000', 'schedule_type' => 'exempt', 'tax_rate' => 0],
        '84713010' => ['pct_code' => '8471.3010', 'schedule_type' => 'standard', 'tax_rate' => 18],
        '87032100' => ['pct_code' => '8703.2100', 'schedule_type' => '3rd_schedule', 'tax_rate' => 17],
        '02023000' => ['pct_code' => '0202.3000', 'schedule_type' => 'exempt', 'tax_rate' => 0],
        '04011000' => ['pct_code' => '0401.1000', 'schedule_type' => 'zero_rated', 'tax_rate' => 0],
        '10063090' => ['pct_code' => '1006.3090', 'schedule_type' => 'zero_rated', 'tax_rate' => 0],
        '11010010' => ['pct_code' => '1101.0010', 'schedule_type' => 'zero_rated', 'tax_rate' => 0],
        '30049099' => ['pct_code' => '3004.9099', 'schedule_type' => 'exempt', 'tax_rate' => 0],
        '48191000' => ['pct_code' => '4819.1000', 'schedule_type' => 'reduced', 'tax_rate' => 10],
        '61091000' => ['pct_code' => '6109.1000', 'schedule_type' => 'standard', 'tax_rate' => 18],
        '62034200' => ['pct_code' => '6203.4200', 'schedule_type' => 'standard', 'tax_rate' => 18],
        '85171100' => ['pct_code' => '8517.1100', 'schedule_type' => '3rd_schedule', 'tax_rate' => 17],
        '27101990' => ['pct_code' => '2710.1990', 'schedule_type' => 'reduced', 'tax_rate' => 10],
    ];

    public static function getScheduleConfig(string $scheduleType): array
    {
        return self::$scheduleTypes[$scheduleType] ?? self::$scheduleTypes['standard'];
    }

    public static function getRequiredFields(string $scheduleType, ?float $taxRate = null): array
    {
        return self::resolveValidationRules($scheduleType, $taxRate);
    }

    public static function resolveValidationRules(string $scheduleType, ?float $taxRate = null): array
    {
        switch ($scheduleType) {
            case 'standard':
                return [
                    'requires_sro' => false,
                    'requires_serial' => false,
                    'requires_mrp' => false,
                ];

            case '3rd_schedule':
                if ($taxRate !== null && $taxRate >= 18) {
                    return [
                        'requires_sro' => false,
                        'requires_serial' => false,
                        'requires_mrp' => true,
                    ];
                }
                return [
                    'requires_sro' => true,
                    'requires_serial' => true,
                    'requires_mrp' => true,
                ];

            case 'exempt':
                return [
                    'requires_sro' => true,
                    'requires_serial' => true,
                    'requires_mrp' => false,
                ];

            case 'zero_rated':
                return [
                    'requires_sro' => false,
                    'requires_serial' => false,
                    'requires_mrp' => false,
                ];

            case 'reduced':
                return [
                    'requires_sro' => true,
                    'requires_serial' => true,
                    'requires_mrp' => false,
                ];

            default:
                return [
                    'requires_sro' => false,
                    'requires_serial' => false,
                    'requires_mrp' => false,
                ];
        }
    }

    public static function getTaxRate(string $scheduleType): float
    {
        $config = self::getScheduleConfig($scheduleType);
        return $config['tax_rate'];
    }

    public static function lookupByHsCode(string $hsCode): ?array
    {
        $normalized = preg_replace('/[^0-9]/', '', $hsCode);

        if (isset(self::$hsLookupTable[$normalized])) {
            $data = self::$hsLookupTable[$normalized];
            $rules = self::resolveValidationRules($data['schedule_type'], $data['tax_rate']);
            return [
                'pct_code' => $data['pct_code'],
                'schedule_type' => $data['schedule_type'],
                'tax_rate' => $data['tax_rate'],
                'requires_sro' => $rules['requires_sro'],
                'requires_serial' => $rules['requires_serial'],
                'requires_mrp' => $rules['requires_mrp'],
            ];
        }

        return null;
    }

    public static function validateItems(array $items): array
    {
        $errors = [];
        $scheduleTypes = [];

        foreach ($items as $index => $item) {
            $scheduleType = $item['schedule_type'] ?? 'standard';
            $scheduleTypes[] = $scheduleType;
            $taxRate = isset($item['tax_rate']) ? floatval($item['tax_rate']) : null;

            if ($taxRate === null) {
                $taxFromAmount = isset($item['tax'], $item['price'], $item['quantity']) ? floatval($item['tax']) : 0;
                $subtotal = (floatval($item['price'] ?? 0) * floatval($item['quantity'] ?? 1));
                if ($subtotal > 0) {
                    $taxRate = ($taxFromAmount / $subtotal) * 100;
                }
            }

            $rules = self::resolveValidationRules($scheduleType, $taxRate);
            $config = self::getScheduleConfig($scheduleType);
            $itemNum = $index + 1;

            if ($rules['requires_sro'] && empty($item['sro_schedule_no'])) {
                if ($scheduleType === '3rd_schedule') {
                    $errors[] = "Item #{$itemNum}: SRO Schedule No is required for 3rd Schedule items with reduced tax rate (below 18%)";
                } else {
                    $errors[] = "Item #{$itemNum}: SRO Schedule No is required for {$config['label']}";
                }
            }
            if ($rules['requires_serial'] && empty($item['serial_no'])) {
                if ($scheduleType === '3rd_schedule') {
                    $errors[] = "Item #{$itemNum}: SRO Item Serial No is required for 3rd Schedule items with reduced tax rate (below 18%)";
                } else {
                    $errors[] = "Item #{$itemNum}: SRO Item Serial No is required for {$config['label']}";
                }
            }
            if ($rules['requires_mrp'] && (empty($item['mrp']) || floatval($item['mrp']) <= 0)) {
                if ($scheduleType === '3rd_schedule' && $taxRate !== null && $taxRate >= 18) {
                    $errors[] = "Item #{$itemNum}: MRP (Retail Price) is required for 3rd Schedule items at standard 18% rate";
                } elseif ($scheduleType === '3rd_schedule') {
                    $errors[] = "Item #{$itemNum}: Fixed/Notified Value or Retail Price is required for 3rd Schedule items with reduced tax rate (below 18%)";
                } else {
                    $errors[] = "Item #{$itemNum}: MRP is required for {$config['label']}";
                }
            }
        }

        $uniqueSchedules = array_unique($scheduleTypes);
        if (count($uniqueSchedules) > 1) {
            $labels = array_map(fn($s) => self::getScheduleConfig($s)['label'], $uniqueSchedules);
            $errors[] = "Mixed schedule types in same invoice: " . implode(', ', $labels) . ". All items must use the same schedule type.";
        }

        return $errors;
    }

    public static function validateForSubmission(array $items): array
    {
        $errors = self::validateItems($items);
        if (!empty($errors)) {
            return [
                'valid' => false,
                'errors' => $errors,
                'message' => 'FBR submission blocked: ' . count($errors) . ' validation error(s) found. Please fix the following issues before submitting.',
            ];
        }
        return ['valid' => true, 'errors' => [], 'message' => 'All schedule validation checks passed.'];
    }
}
