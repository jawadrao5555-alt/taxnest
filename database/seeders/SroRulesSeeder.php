<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class SroRulesSeeder extends Seeder
{
    public function run(): void
    {
        $rules = [
            ['hs_code' => '02011000', 'schedule_type' => 'exempt', 'sro_number' => 'SRO 551(I)/2008', 'serial_no' => '1', 'applicable_sector' => 'Food', 'description' => 'Fresh/chilled bovine carcasses - Exempt from sales tax under 6th Schedule', 'concessionary_rate' => 0],
            ['hs_code' => '02023000', 'schedule_type' => 'exempt', 'sro_number' => 'SRO 551(I)/2008', 'serial_no' => '1', 'applicable_sector' => 'Food', 'description' => 'Boneless frozen bovine meat - Exempt under 6th Schedule', 'concessionary_rate' => 0],
            ['hs_code' => '02071100', 'schedule_type' => 'exempt', 'sro_number' => 'SRO 551(I)/2008', 'serial_no' => '2', 'applicable_sector' => 'Food', 'description' => 'Fresh/chilled whole chicken - Exempt under 6th Schedule', 'concessionary_rate' => 0],
            ['hs_code' => '04011000', 'schedule_type' => 'exempt', 'sro_number' => 'SRO 551(I)/2008', 'serial_no' => '3', 'applicable_sector' => 'Dairy', 'description' => 'Milk (fat <=1%) - Exempt under 6th Schedule', 'concessionary_rate' => 0],
            ['hs_code' => '04012000', 'schedule_type' => 'exempt', 'sro_number' => 'SRO 551(I)/2008', 'serial_no' => '3', 'applicable_sector' => 'Dairy', 'description' => 'Milk (fat 1-6%) - Exempt under 6th Schedule', 'concessionary_rate' => 0],
            ['hs_code' => '04014000', 'schedule_type' => 'exempt', 'sro_number' => 'SRO 551(I)/2008', 'serial_no' => '3', 'applicable_sector' => 'Dairy', 'description' => 'Milk (fat >6%) - Exempt under 6th Schedule', 'concessionary_rate' => 0],
            ['hs_code' => '04021000', 'schedule_type' => 'exempt', 'sro_number' => 'SRO 551(I)/2008', 'serial_no' => '4', 'applicable_sector' => 'Dairy', 'description' => 'Milk powder (<=1.5% fat) - Exempt under 6th Schedule', 'concessionary_rate' => 0],
            ['hs_code' => '04051000', 'schedule_type' => 'exempt', 'sro_number' => 'SRO 551(I)/2008', 'serial_no' => '5', 'applicable_sector' => 'Dairy', 'description' => 'Butter - Exempt under 6th Schedule', 'concessionary_rate' => 0],
            ['hs_code' => '04061000', 'schedule_type' => 'exempt', 'sro_number' => 'SRO 551(I)/2008', 'serial_no' => '6', 'applicable_sector' => 'Dairy', 'description' => 'Fresh cheese/curd - Exempt under 6th Schedule', 'concessionary_rate' => 0],
            ['hs_code' => '07019000', 'schedule_type' => 'exempt', 'sro_number' => 'SRO 551(I)/2008', 'serial_no' => '7', 'applicable_sector' => 'Agriculture', 'description' => 'Potatoes - Exempt under 6th Schedule', 'concessionary_rate' => 0],
            ['hs_code' => '07031000', 'schedule_type' => 'exempt', 'sro_number' => 'SRO 551(I)/2008', 'serial_no' => '8', 'applicable_sector' => 'Agriculture', 'description' => 'Onions and shallots - Exempt under 6th Schedule', 'concessionary_rate' => 0],
            ['hs_code' => '07032000', 'schedule_type' => 'exempt', 'sro_number' => 'SRO 551(I)/2008', 'serial_no' => '8', 'applicable_sector' => 'Agriculture', 'description' => 'Garlic - Exempt under 6th Schedule', 'concessionary_rate' => 0],
            ['hs_code' => '10011900', 'schedule_type' => 'exempt', 'sro_number' => 'SRO 551(I)/2008', 'serial_no' => '9', 'applicable_sector' => 'Agriculture', 'description' => 'Wheat - Exempt under 6th Schedule', 'concessionary_rate' => 0],
            ['hs_code' => '10063090', 'schedule_type' => 'exempt', 'sro_number' => 'SRO 551(I)/2008', 'serial_no' => '10', 'applicable_sector' => 'Agriculture', 'description' => 'Rice - Exempt under 6th Schedule', 'concessionary_rate' => 0],
            ['hs_code' => '11010010', 'schedule_type' => 'exempt', 'sro_number' => 'SRO 551(I)/2008', 'serial_no' => '11', 'applicable_sector' => 'Food', 'description' => 'Wheat flour (atta/maida) - Exempt under 6th Schedule', 'concessionary_rate' => 0],
            ['hs_code' => '15079000', 'schedule_type' => 'exempt', 'sro_number' => 'SRO 551(I)/2008', 'serial_no' => '12', 'applicable_sector' => 'Food', 'description' => 'Soyabean oil - Exempt under 6th Schedule', 'concessionary_rate' => 0],
            ['hs_code' => '17011300', 'schedule_type' => 'exempt', 'sro_number' => 'SRO 551(I)/2008', 'serial_no' => '13', 'applicable_sector' => 'Food', 'description' => 'Cane sugar - Exempt under 6th Schedule', 'concessionary_rate' => 0],
            ['hs_code' => '30049099', 'schedule_type' => 'exempt', 'sro_number' => 'SRO 551(I)/2008', 'serial_no' => '14', 'applicable_sector' => 'Pharma', 'description' => 'Medicaments/pharmaceutical products - Exempt under 6th Schedule', 'concessionary_rate' => 0],
            ['hs_code' => '30059000', 'schedule_type' => 'exempt', 'sro_number' => 'SRO 551(I)/2008', 'serial_no' => '15', 'applicable_sector' => 'Pharma', 'description' => 'Surgical dressings/bandages - Exempt under 6th Schedule', 'concessionary_rate' => 0],
            ['hs_code' => '48201000', 'schedule_type' => 'exempt', 'sro_number' => 'SRO 551(I)/2008', 'serial_no' => '16', 'applicable_sector' => 'Stationery', 'description' => 'Exercise books/notebooks - Exempt under 6th Schedule', 'concessionary_rate' => 0],
            ['hs_code' => '49011000', 'schedule_type' => 'exempt', 'sro_number' => 'SRO 551(I)/2008', 'serial_no' => '17', 'applicable_sector' => 'Stationery', 'description' => 'Printed books/brochures - Exempt under 6th Schedule', 'concessionary_rate' => 0],
            ['hs_code' => '49021000', 'schedule_type' => 'exempt', 'sro_number' => 'SRO 551(I)/2008', 'serial_no' => '18', 'applicable_sector' => 'Media', 'description' => 'Newspapers/journals - Exempt under 6th Schedule', 'concessionary_rate' => 0],

            ['hs_code' => '52010000', 'schedule_type' => 'zero_rated', 'sro_number' => 'SRO 1125(I)/2011', 'serial_no' => '1', 'applicable_sector' => 'Textile', 'description' => 'Raw cotton - Zero rated for textile export sector', 'concessionary_rate' => 0],
            ['hs_code' => '52030000', 'schedule_type' => 'zero_rated', 'sro_number' => 'SRO 1125(I)/2011', 'serial_no' => '2', 'applicable_sector' => 'Textile', 'description' => 'Cotton waste - Zero rated for textile export', 'concessionary_rate' => 0],
            ['hs_code' => '52041100', 'schedule_type' => 'zero_rated', 'sro_number' => 'SRO 1125(I)/2011', 'serial_no' => '3', 'applicable_sector' => 'Textile', 'description' => 'Cotton sewing thread - Zero rated for textile', 'concessionary_rate' => 0],
            ['hs_code' => '52051100', 'schedule_type' => 'zero_rated', 'sro_number' => 'SRO 1125(I)/2011', 'serial_no' => '4', 'applicable_sector' => 'Textile', 'description' => 'Cotton yarn (uncombed, single) - Zero rated', 'concessionary_rate' => 0],
            ['hs_code' => '52081100', 'schedule_type' => 'zero_rated', 'sro_number' => 'SRO 1125(I)/2011', 'serial_no' => '5', 'applicable_sector' => 'Textile', 'description' => 'Unbleached cotton fabric - Zero rated', 'concessionary_rate' => 0],
            ['hs_code' => '54011000', 'schedule_type' => 'zero_rated', 'sro_number' => 'SRO 1125(I)/2011', 'serial_no' => '6', 'applicable_sector' => 'Textile', 'description' => 'Synthetic filament yarn - Zero rated for textile', 'concessionary_rate' => 0],
            ['hs_code' => '55032000', 'schedule_type' => 'zero_rated', 'sro_number' => 'SRO 1125(I)/2011', 'serial_no' => '7', 'applicable_sector' => 'Textile', 'description' => 'Polyester staple fibers - Zero rated', 'concessionary_rate' => 0],
            ['hs_code' => '61091000', 'schedule_type' => 'zero_rated', 'sro_number' => 'SRO 1125(I)/2011', 'serial_no' => '8', 'applicable_sector' => 'Textile', 'description' => 'T-shirts/knitted garments - Zero rated for export', 'concessionary_rate' => 0],
            ['hs_code' => '62034200', 'schedule_type' => 'zero_rated', 'sro_number' => 'SRO 1125(I)/2011', 'serial_no' => '9', 'applicable_sector' => 'Textile', 'description' => 'Cotton trousers/shorts - Zero rated for export', 'concessionary_rate' => 0],
            ['hs_code' => '63031200', 'schedule_type' => 'zero_rated', 'sro_number' => 'SRO 1125(I)/2011', 'serial_no' => '10', 'applicable_sector' => 'Textile', 'description' => 'Knitted curtains/furnishing - Zero rated', 'concessionary_rate' => 0],
            ['hs_code' => '41012000', 'schedule_type' => 'zero_rated', 'sro_number' => 'SRO 1125(I)/2011', 'serial_no' => '11', 'applicable_sector' => 'Leather', 'description' => 'Raw hides/skins of bovine - Zero rated for leather export', 'concessionary_rate' => 0],
            ['hs_code' => '42021200', 'schedule_type' => 'zero_rated', 'sro_number' => 'SRO 1125(I)/2011', 'serial_no' => '12', 'applicable_sector' => 'Leather', 'description' => 'Leather trunks/suitcases - Zero rated for export', 'concessionary_rate' => 0],
            ['hs_code' => '42032100', 'schedule_type' => 'zero_rated', 'sro_number' => 'SRO 1125(I)/2011', 'serial_no' => '13', 'applicable_sector' => 'Leather', 'description' => 'Leather gloves - Zero rated for export', 'concessionary_rate' => 0],
            ['hs_code' => '64039900', 'schedule_type' => 'zero_rated', 'sro_number' => 'SRO 1125(I)/2011', 'serial_no' => '14', 'applicable_sector' => 'Leather', 'description' => 'Leather footwear - Zero rated for export', 'concessionary_rate' => 0],
            ['hs_code' => '57011000', 'schedule_type' => 'zero_rated', 'sro_number' => 'SRO 1125(I)/2011', 'serial_no' => '15', 'applicable_sector' => 'Textile', 'description' => 'Carpets/rugs (knotted) - Zero rated for export', 'concessionary_rate' => 0],

            ['hs_code' => '87032100', 'schedule_type' => '3rd_schedule', 'sro_number' => 'SRO 693(I)/2006', 'serial_no' => '1', 'applicable_sector' => 'Automotive', 'description' => 'Motor vehicles (1000-1500cc) - 3rd Schedule, fixed tax at MRP', 'concessionary_rate' => 17],
            ['hs_code' => '87032300', 'schedule_type' => '3rd_schedule', 'sro_number' => 'SRO 693(I)/2006', 'serial_no' => '2', 'applicable_sector' => 'Automotive', 'description' => 'Motor vehicles (1500-3000cc) - 3rd Schedule at MRP', 'concessionary_rate' => 17],
            ['hs_code' => '87032400', 'schedule_type' => '3rd_schedule', 'sro_number' => 'SRO 693(I)/2006', 'serial_no' => '3', 'applicable_sector' => 'Automotive', 'description' => 'Motor vehicles (>3000cc) - 3rd Schedule at MRP', 'concessionary_rate' => 25],
            ['hs_code' => '85171100', 'schedule_type' => '3rd_schedule', 'sro_number' => 'SRO 693(I)/2006', 'serial_no' => '4', 'applicable_sector' => 'Electronics', 'description' => 'Mobile phones/smartphones - 3rd Schedule at MRP', 'concessionary_rate' => 17],
            ['hs_code' => '84182100', 'schedule_type' => '3rd_schedule', 'sro_number' => 'SRO 693(I)/2006', 'serial_no' => '5', 'applicable_sector' => 'Electronics', 'description' => 'Refrigerators - 3rd Schedule at MRP', 'concessionary_rate' => 17],
            ['hs_code' => '84501100', 'schedule_type' => '3rd_schedule', 'sro_number' => 'SRO 693(I)/2006', 'serial_no' => '6', 'applicable_sector' => 'Electronics', 'description' => 'Washing machines - 3rd Schedule at MRP', 'concessionary_rate' => 17],
            ['hs_code' => '85163200', 'schedule_type' => '3rd_schedule', 'sro_number' => 'SRO 693(I)/2006', 'serial_no' => '7', 'applicable_sector' => 'Electronics', 'description' => 'Hair dryers/electric appliances - 3rd Schedule at MRP', 'concessionary_rate' => 17],
            ['hs_code' => '84151000', 'schedule_type' => '3rd_schedule', 'sro_number' => 'SRO 693(I)/2006', 'serial_no' => '8', 'applicable_sector' => 'Electronics', 'description' => 'Air conditioners (window/split) - 3rd Schedule at MRP', 'concessionary_rate' => 17],
            ['hs_code' => '85287200', 'schedule_type' => '3rd_schedule', 'sro_number' => 'SRO 693(I)/2006', 'serial_no' => '9', 'applicable_sector' => 'Electronics', 'description' => 'LED/LCD television sets - 3rd Schedule at MRP', 'concessionary_rate' => 17],

            ['hs_code' => '27101990', 'schedule_type' => 'reduced', 'sro_number' => 'SRO 648(I)/2013', 'serial_no' => '1', 'applicable_sector' => 'Petroleum', 'description' => 'Light oils/petroleum - Reduced rate under SRO 648', 'concessionary_rate' => 10],
            ['hs_code' => '27101220', 'schedule_type' => 'reduced', 'sro_number' => 'SRO 648(I)/2013', 'serial_no' => '2', 'applicable_sector' => 'Petroleum', 'description' => 'Motor spirit/petrol - Reduced rate', 'concessionary_rate' => 10],
            ['hs_code' => '27101940', 'schedule_type' => 'reduced', 'sro_number' => 'SRO 648(I)/2013', 'serial_no' => '3', 'applicable_sector' => 'Petroleum', 'description' => 'High speed diesel oil - Reduced rate', 'concessionary_rate' => 10],
            ['hs_code' => '27111200', 'schedule_type' => 'reduced', 'sro_number' => 'SRO 648(I)/2013', 'serial_no' => '4', 'applicable_sector' => 'Energy', 'description' => 'Propane (LPG) - Reduced rate under SRO 648', 'concessionary_rate' => 10],
            ['hs_code' => '27112100', 'schedule_type' => 'reduced', 'sro_number' => 'SRO 648(I)/2013', 'serial_no' => '5', 'applicable_sector' => 'Energy', 'description' => 'Natural gas in gaseous state - Reduced rate', 'concessionary_rate' => 10],

            ['hs_code' => '31021000', 'schedule_type' => 'exempt', 'sro_number' => 'SRO 551(I)/2008', 'serial_no' => '19', 'applicable_sector' => 'Agriculture', 'description' => 'Urea fertilizer - Exempt under 6th Schedule', 'concessionary_rate' => 0],
            ['hs_code' => '31031100', 'schedule_type' => 'exempt', 'sro_number' => 'SRO 551(I)/2008', 'serial_no' => '20', 'applicable_sector' => 'Agriculture', 'description' => 'Superphosphate fertilizer - Exempt under 6th Schedule', 'concessionary_rate' => 0],
            ['hs_code' => '31042000', 'schedule_type' => 'exempt', 'sro_number' => 'SRO 551(I)/2008', 'serial_no' => '21', 'applicable_sector' => 'Agriculture', 'description' => 'Potassic fertilizer - Exempt under 6th Schedule', 'concessionary_rate' => 0],

            ['hs_code' => '84713010', 'schedule_type' => 'reduced', 'sro_number' => 'SRO 655(I)/2007', 'serial_no' => '1', 'applicable_sector' => 'IT', 'description' => 'Laptop computers - Reduced rate for IT sector', 'concessionary_rate' => 5],
            ['hs_code' => '84714100', 'schedule_type' => 'reduced', 'sro_number' => 'SRO 655(I)/2007', 'serial_no' => '2', 'applicable_sector' => 'IT', 'description' => 'Desktop computers - Reduced rate for IT', 'concessionary_rate' => 5],
            ['hs_code' => '85414020', 'schedule_type' => 'exempt', 'sro_number' => 'SRO 551(I)/2008', 'serial_no' => '22', 'applicable_sector' => 'Energy', 'description' => 'Solar panels/photovoltaic cells - Exempt under 6th Schedule', 'concessionary_rate' => 0],
            ['hs_code' => '85044090', 'schedule_type' => 'exempt', 'sro_number' => 'SRO 551(I)/2008', 'serial_no' => '23', 'applicable_sector' => 'Energy', 'description' => 'Solar inverters - Exempt under 6th Schedule', 'concessionary_rate' => 0],

            ['hs_code' => '48191000', 'schedule_type' => 'reduced', 'sro_number' => 'SRO 1125(I)/2011', 'serial_no' => '16', 'applicable_sector' => 'Packaging', 'description' => 'Carton boxes/packaging - Reduced rate for export packaging', 'concessionary_rate' => 10],
            ['hs_code' => '39232100', 'schedule_type' => 'reduced', 'sro_number' => 'SRO 1125(I)/2011', 'serial_no' => '17', 'applicable_sector' => 'Packaging', 'description' => 'Plastic bags/sacks for packaging - Reduced rate', 'concessionary_rate' => 10],

            ['hs_code' => '25232100', 'schedule_type' => 'standard', 'sro_number' => 'SRO 350(I)/2024', 'serial_no' => '1', 'applicable_sector' => 'Construction', 'description' => 'White/grey cement - Standard rate with FED', 'concessionary_rate' => 18],
            ['hs_code' => '25232900', 'schedule_type' => 'standard', 'sro_number' => 'SRO 350(I)/2024', 'serial_no' => '2', 'applicable_sector' => 'Construction', 'description' => 'Portland cement - Standard rate with FED', 'concessionary_rate' => 18],
            ['hs_code' => '72131000', 'schedule_type' => 'standard', 'sro_number' => 'SRO 350(I)/2024', 'serial_no' => '3', 'applicable_sector' => 'Construction', 'description' => 'Iron/steel bars (hot-rolled) - Standard rate', 'concessionary_rate' => 18],
            ['hs_code' => '72142000', 'schedule_type' => 'standard', 'sro_number' => 'SRO 350(I)/2024', 'serial_no' => '4', 'applicable_sector' => 'Construction', 'description' => 'Iron/steel bars (cold-formed) - Standard rate', 'concessionary_rate' => 18],
        ];

        foreach ($rules as $rule) {
            DB::table('special_sro_rules')->updateOrInsert(
                ['hs_code' => $rule['hs_code'], 'sro_number' => $rule['sro_number']],
                array_merge($rule, [
                    'is_active' => true,
                    'effective_from' => now()->startOfYear()->toDateString(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ])
            );
        }

        DB::table('hs_master_global')
            ->where('sro_required', true)
            ->whereNull('default_sro_number')
            ->update(['default_sro_number' => 'SRO 551(I)/2008']);
    }
}
