<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class FbrReferenceSeeder extends Seeder
{
    public function run(): void
    {
        $base = storage_path('app/fbr_reference');
        if (!is_dir($base)) {
            $this->command->error("Reference dir not found: $base");
            return;
        }

        $now = now();

        // 1. HS CODES — parse "0902:-Tea" / "0101.2100:-" → code + description
        $rows = [];
        foreach ($this->loadJson("$base/description.json") as $raw) {
            $parts = explode(':-', $raw, 2);
            $code = trim($parts[0] ?? '');
            $desc = trim($parts[1] ?? '');
            if ($code === '') continue;
            $rows[$code] = [
                'code' => $code,
                'description' => $desc !== '' ? mb_substr($desc, 0, 500) : null,
                'is_active' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }
        $this->bulkInsert('fbr_hs_codes', array_values($rows), 500);
        $this->command->info('HS Codes: ' . count($rows));

        // 2. Simple name-only tables
        $simple = [
            'fbr_sros'                  => ['file' => 'sro.json',                'col' => 'sro_number'],
            'fbr_sale_types'            => ['file' => 'sale_types.json',         'col' => 'name'],
            'fbr_uoms'                  => ['file' => 'uom.json',                'col' => 'name'],
            'fbr_provinces'             => ['file' => 'province.json',           'col' => 'name'],
            'fbr_buyer_types'           => ['file' => 'buyer_type.json',         'col' => 'name'],
            'fbr_document_types'        => ['file' => 'document_type.json',      'col' => 'name'],
            'fbr_reasons'               => ['file' => 'reason.json',             'col' => 'name'],
            'fbr_petroleum_levy_types'  => ['file' => 'petroleum_levy_on.json',  'col' => 'name'],
            'fbr_item_sr_numbers'       => ['file' => 'item_sr_no.json',         'col' => 'sr_no'],
        ];
        foreach ($simple as $table => $cfg) {
            $rows = [];
            foreach ($this->loadJson("$base/{$cfg['file']}") as $val) {
                $val = trim((string) $val);
                if ($val === '' || $val === 'None') continue;
                $rows[$val] = [
                    $cfg['col'] => mb_substr($val, 0, 200),
                    'is_active' => 1,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
            $this->bulkInsert($table, array_values($rows), 500);
            $this->command->info("$table: " . count($rows));
        }

        // 3. RATES — capture both label + parsed numeric value
        $rows = [];
        foreach ($this->loadJson("$base/rate.json") as $raw) {
            $label = trim((string) $raw);
            if ($label === '') continue;
            $numeric = is_numeric($label) ? (float) $label : null;
            $rows[$label] = [
                'label' => mb_substr($label, 0, 50),
                'numeric_value' => $numeric,
                'is_active' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }
        $this->bulkInsert('fbr_rates', array_values($rows), 500);
        $this->command->info('fbr_rates: ' . count($rows));
    }

    private function loadJson(string $path): array
    {
        if (!file_exists($path)) return [];
        $data = json_decode(file_get_contents($path), true);
        return is_array($data) ? $data : [];
    }

    private function bulkInsert(string $table, array $rows, int $chunkSize = 500): void
    {
        if (empty($rows)) return;
        DB::table($table)->truncate();
        foreach (array_chunk($rows, $chunkSize) as $chunk) {
            DB::table($table)->insert($chunk);
        }
    }
}
