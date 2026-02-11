<?php

namespace App\Services;

class ScheduleEngine
{
    public static array $scheduleTypes = [
        'standard' => ['label' => 'Standard Rate', 'tax_rate' => 18, 'requires_sro' => false, 'requires_serial' => false, 'requires_mrp' => false],
        'reduced' => ['label' => 'Reduced Rate', 'tax_rate' => 10, 'requires_sro' => true, 'requires_serial' => true, 'requires_mrp' => false],
        '3rd_schedule' => ['label' => '3rd Schedule', 'tax_rate' => 17, 'requires_sro' => true, 'requires_serial' => true, 'requires_mrp' => true],
        'exempt' => ['label' => 'Exempt', 'tax_rate' => 0, 'requires_sro' => true, 'requires_serial' => true, 'requires_mrp' => false],
        'zero_rated' => ['label' => 'Zero Rated', 'tax_rate' => 0, 'requires_sro' => true, 'requires_serial' => false, 'requires_mrp' => false],
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

    public static function getRequiredFields(string $scheduleType): array
    {
        $config = self::getScheduleConfig($scheduleType);
        return [
            'requires_sro' => $config['requires_sro'],
            'requires_serial' => $config['requires_serial'],
            'requires_mrp' => $config['requires_mrp'],
        ];
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
            $config = self::getScheduleConfig($data['schedule_type']);
            return [
                'pct_code' => $data['pct_code'],
                'schedule_type' => $data['schedule_type'],
                'tax_rate' => $data['tax_rate'],
                'requires_sro' => $config['requires_sro'],
                'requires_serial' => $config['requires_serial'],
                'requires_mrp' => $config['requires_mrp'],
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
            $config = self::getScheduleConfig($scheduleType);
            $itemNum = $index + 1;

            if ($config['requires_sro'] && empty($item['sro_schedule_no'])) {
                $errors[] = "Item #{$itemNum}: SRO Schedule No is required for {$config['label']}";
            }
            if ($config['requires_serial'] && empty($item['serial_no'])) {
                $errors[] = "Item #{$itemNum}: Serial No is required for {$config['label']}";
            }
            if ($config['requires_mrp'] && (empty($item['mrp']) || floatval($item['mrp']) <= 0)) {
                $errors[] = "Item #{$itemNum}: MRP is required for {$config['label']}";
            }
        }

        $uniqueSchedules = array_unique($scheduleTypes);
        if (count($uniqueSchedules) > 1) {
            $labels = array_map(fn($s) => self::getScheduleConfig($s)['label'], $uniqueSchedules);
            $errors[] = "Mixed schedule types in same invoice: " . implode(', ', $labels) . ". All items must use the same schedule type.";
        }

        return $errors;
    }
}
