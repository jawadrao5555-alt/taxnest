<?php

namespace App\Services;

class ScheduleEngine
{
    public static array $scheduleTypes = [
        'standard' => ['label' => 'Standard Rate', 'tax_rate' => null, 'requires_sro' => false, 'requires_serial' => false, 'requires_mrp' => false],
        'reduced' => ['label' => 'Reduced Rate', 'tax_rate' => 10, 'requires_sro' => true, 'requires_serial' => true, 'requires_mrp' => false],
        '3rd_schedule' => ['label' => '3rd Schedule', 'tax_rate' => 17, 'requires_sro' => false, 'requires_serial' => false, 'requires_mrp' => true],
        'exempt' => ['label' => 'Exempt', 'tax_rate' => 0, 'requires_sro' => true, 'requires_serial' => false, 'requires_mrp' => false],
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

    public static function getRequiredFields(string $scheduleType, ?float $taxRate = null, float $standardTaxRate = 18.0): array
    {
        return self::resolveValidationRules($scheduleType, $taxRate, $standardTaxRate);
    }

    public static function resolveValidationRules(string $scheduleType, ?float $taxRate = null, float $standardTaxRate = 18.0): array
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
                    'requires_serial' => false,
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
        return $config['tax_rate'] ?? 18.0;
    }

    public static function lookupByHsCode(string $hsCode, float $standardTaxRate = 18.0): ?array
    {
        $normalized = preg_replace('/[^0-9]/', '', $hsCode);

        if (isset(self::$hsLookupTable[$normalized])) {
            $data = self::$hsLookupTable[$normalized];
            $rules = self::resolveValidationRules($data['schedule_type'], $data['tax_rate'], $standardTaxRate);
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

    public static function validateItems(array $items, float $standardTaxRate = 18.0): array
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

            if ($scheduleType === 'exempt' && $taxRate !== null && $taxRate != 0) {
                $errors[] = "Item #" . ($index + 1) . ": Exempt items must have 0% tax rate";
            }

            if ($scheduleType === 'zero_rated' && $taxRate !== null && $taxRate != 0) {
                $errors[] = "Item #" . ($index + 1) . ": Zero Rated items must have 0% tax rate";
            }

            $rules = self::resolveValidationRules($scheduleType, $taxRate, $standardTaxRate);
            $config = self::getScheduleConfig($scheduleType);
            $itemNum = $index + 1;

            if ($scheduleType === '3rd_schedule' && $taxRate !== null && $taxRate < $standardTaxRate) {
                $missing = [];
                if ($rules['requires_sro'] && empty($item['sro_schedule_no'])) $missing[] = 'SRO';
                if ($rules['requires_serial'] && empty($item['serial_no'])) $missing[] = 'Serial No';
                if ($rules['requires_mrp'] && (empty($item['mrp']) || floatval($item['mrp']) <= 0)) $missing[] = 'MRP';
                if (!empty($missing)) {
                    $errors[] = "Item #{$itemNum}: 3rd Schedule (Reduced Rate) requires " . implode(', ', $missing) . ".";
                }
            } else {
                if ($rules['requires_sro'] && empty($item['sro_schedule_no'])) {
                    $errors[] = "Item #{$itemNum}: SRO Schedule No is required for {$config['label']}";
                }
                if ($rules['requires_serial'] && empty($item['serial_no'])) {
                    $errors[] = "Item #{$itemNum}: SRO Item Serial No is required for {$config['label']}";
                }
                if ($rules['requires_mrp'] && (empty($item['mrp']) || floatval($item['mrp']) <= 0)) {
                    if ($scheduleType === '3rd_schedule' && $taxRate !== null && $taxRate >= $standardTaxRate) {
                        $errors[] = "Item #{$itemNum}: MRP (Retail Price) is required for 3rd Schedule items at standard {$standardTaxRate}% rate";
                    } else {
                        $errors[] = "Item #{$itemNum}: MRP is required for {$config['label']}";
                    }
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

    public static function mapSaleType(string $scheduleType): string
    {
        return match ($scheduleType) {
            'standard' => 'Goods at Standard Rate (default)',
            'reduced' => 'Goods at Reduced Rate',
            '3rd_schedule' => '3rd Schedule Goods',
            'exempt' => 'Exempt Goods',
            'zero_rated' => 'Goods at zero-rate',
            'steel' => 'Steel Melting and re-rolling',
            'ship_breaking' => 'Ship breaking',
            'cotton_ginners' => 'Cotton Ginners',
            'telecom' => 'Telecommunication services',
            'toll_manufacturing' => 'Toll Manufacturing',
            'petroleum' => 'Petroleum Products',
            'electricity' => 'Electricity Supply to Retailers',
            'gas_cng' => 'Gas to CNG stations',
            'mobile_phones' => 'Mobile Phones',
            'processing' => 'Processing/ Conversion of Goods',
            'fed_goods' => 'Goods (FED in ST Mode)',
            'fed_services' => 'Services (FED in ST Mode)',
            'services' => 'Services',
            'electric_vehicle' => 'Electric Vehicle',
            'cement' => 'Cement /Concrete Block',
            'potassium_chlorate' => 'Potassium Chlorate',
            'cng_sales' => 'CNG Sales',
            'sro_297' => 'Goods as per SRO.297(|)/2023',
            'non_adjustable' => 'Non-Adjustable Supplies',
            default => 'Goods at Standard Rate (default)',
        };
    }

    public static function validateFbrPayload(array $payload): array
    {
        $errors = [];

        $requiredTop = ['invoiceType', 'invoiceDate', 'sellerNTNCNIC', 'sellerBusinessName', 'sellerProvince', 'buyerProvince'];
        foreach ($requiredTop as $field) {
            if (empty($payload[$field])) {
                $errors[] = "Missing required field: {$field}";
            }
        }

        if (empty($payload['items']) || !is_array($payload['items'])) {
            $errors[] = "Invoice must contain at least one item";
            return $errors;
        }

        $requiredItem = ['hsCode', 'productDescription', 'rate', 'uoM', 'quantity', 'valueSalesExcludingST', 'saleType'];
        foreach ($payload['items'] as $idx => $item) {
            $num = $idx + 1;
            foreach ($requiredItem as $field) {
                if (!isset($item[$field]) || (is_string($item[$field]) && $item[$field] === '')) {
                    $errors[] = "Item #{$num}: Missing required field: {$field}";
                }
            }
            if (isset($item['quantity']) && $item['quantity'] <= 0) {
                $errors[] = "Item #{$num}: Quantity must be greater than 0";
            }
            if (isset($item['valueSalesExcludingST']) && $item['valueSalesExcludingST'] < 0) {
                $errors[] = "Item #{$num}: valueSalesExcludingST cannot be negative";
            }
            if (isset($item['salesTaxApplicable']) && $item['salesTaxApplicable'] < 0) {
                $errors[] = "Item #{$num}: salesTaxApplicable cannot be negative";
            }
            $saleType = $item['saleType'] ?? '';
            if (str_contains($saleType, 'Exempt') && isset($item['salesTaxApplicable']) && $item['salesTaxApplicable'] != 0) {
                $errors[] = "Item #{$num}: Exempt items must have zero salesTaxApplicable";
            }
        }

        return $errors;
    }

    public static function validateForSubmission(array $items, float $standardTaxRate = 18.0): array
    {
        $errors = self::validateItems($items, $standardTaxRate);
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
