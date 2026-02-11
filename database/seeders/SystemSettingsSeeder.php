<?php

namespace Database\Seeders;

use App\Models\SystemSetting;
use Illuminate\Database\Seeder;

class SystemSettingsSeeder extends Seeder
{
    public function run(): void
    {
        SystemSetting::set('mom_spike_threshold', '200', 'Month-over-month spike threshold percentage');
        SystemSetting::set('tax_drop_threshold', '60', 'Tax drop threshold percentage');
        SystemSetting::set('critical_score_threshold', '40', 'Critical compliance score threshold');
        SystemSetting::set('stability_bonus_weight', '10', 'Weight for stability bonus in scoring');
    }
}
