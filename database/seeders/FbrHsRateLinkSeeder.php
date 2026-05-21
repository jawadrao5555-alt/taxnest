<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class FbrHsRateLinkSeeder extends Seeder
{
    public function run(): void
    {
        $rows = [
            // === 3rd SCHEDULE (Reduced/Specific Rates) ===
            ['hs_code' => '3105.3000', 'schedule_type' => '3rd_schedule', 'tax_rate' => 5.00,  'rate_label' => '5%',  'sale_type' => '3rd Schedule Goods', 'sro_number' => '3rd Schedule goods', 'sr_no' => '51',  'uom' => 'KG',  'notes' => 'Fertilizers (DAP / NPK <10kg pack)'],
            ['hs_code' => '3105.1000', 'schedule_type' => '3rd_schedule', 'tax_rate' => 5.00,  'rate_label' => '5%',  'sale_type' => '3rd Schedule Goods', 'sro_number' => '3rd Schedule goods', 'sr_no' => '51',  'uom' => 'KG',  'notes' => 'Fertilizer compounds'],
            ['hs_code' => '3105.2000', 'schedule_type' => '3rd_schedule', 'tax_rate' => 5.00,  'rate_label' => '5%',  'sale_type' => '3rd Schedule Goods', 'sro_number' => '3rd Schedule goods', 'sr_no' => '51',  'uom' => 'KG',  'notes' => 'NPK Fertilizer'],
            ['hs_code' => '8517.1300', 'schedule_type' => '3rd_schedule', 'tax_rate' => 18.00, 'rate_label' => '18%', 'sale_type' => '3rd Schedule Goods', 'sro_number' => '3rd Schedule goods', 'sr_no' => '50',  'uom' => 'NO',  'notes' => 'Smartphones / Mobile sets'],
            ['hs_code' => '2402.2000', 'schedule_type' => '3rd_schedule', 'tax_rate' => 18.00, 'rate_label' => '18%', 'sale_type' => '3rd Schedule Goods', 'sro_number' => '3rd Schedule goods', 'sr_no' => '5',   'uom' => 'Thousand Unit',  'notes' => 'Cigarettes containing tobacco (FBR sandbox-verified UoM)'],
            ['hs_code' => '3401.1100', 'schedule_type' => '3rd_schedule', 'tax_rate' => 18.00, 'rate_label' => '18%', 'sale_type' => '3rd Schedule Goods', 'sro_number' => '3rd Schedule goods', 'sr_no' => '21',  'uom' => 'KG',  'notes' => 'Toilet soap'],

            // === STANDARD RATE ===
            ['hs_code' => '0902',      'schedule_type' => 'standard',    'tax_rate' => 18.00, 'rate_label' => '18%', 'sale_type' => 'Goods at standard rate (default)', 'sro_number' => null, 'sr_no' => null, 'uom' => 'KG', 'notes' => 'Tea — standard rated'],
            ['hs_code' => '0902.4090', 'schedule_type' => 'standard',    'tax_rate' => 18.00, 'rate_label' => '18%', 'sale_type' => 'Goods at standard rate (default)', 'sro_number' => null, 'sr_no' => null, 'uom' => 'KG', 'notes' => 'Tea — black fermented'],

            // === REDUCED RATE ===
            ['hs_code' => '1006',      'schedule_type' => 'reduced',     'tax_rate' => 0.00,  'rate_label' => '0%',  'sale_type' => 'Goods at Reduced Rate',           'sro_number' => null, 'sr_no' => null, 'uom' => 'KG', 'notes' => 'Rice — exempt for unbranded'],

            // === ZERO RATED ===
            ['hs_code' => '3003',      'schedule_type' => 'zero_rated',  'tax_rate' => 0.00,  'rate_label' => '0%',  'sale_type' => 'Goods at zero-rate',              'sro_number' => 'SRO 297(I)/2023', 'sr_no' => null, 'uom' => 'NO', 'notes' => 'Pharmaceutical products'],

            // === EXEMPT ===
            ['hs_code' => '0401',      'schedule_type' => 'exempt',      'tax_rate' => null,  'rate_label' => 'Exempt', 'sale_type' => 'Exempt goods',                'sro_number' => '6th Schedule', 'sr_no' => null, 'uom' => 'Litre', 'notes' => 'Milk and cream — exempt under 6th Schedule'],
        ];

        $count = 0;
        foreach ($rows as $r) {
            DB::table('fbr_hs_rate_links')->updateOrInsert(
                ['hs_code' => $r['hs_code'], 'schedule_type' => $r['schedule_type']],
                array_merge($r, ['is_active' => 1, 'updated_at' => now(), 'created_at' => now()])
            );
            $count++;
        }
        $this->command->info("FBR HS-Rate Links seeded: $count rows");
    }
}
