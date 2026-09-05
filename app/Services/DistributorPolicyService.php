<?php
namespace App\Services;
use App\Models\SystemSetting;
class DistributorPolicyService {
    public const DEFAULTS = ['year1'=>15,'year2'=>10,'year3'=>5,'max_discount'=>10,'hold_days'=>15,'tiers'=>[['companies'=>3,'rate'=>2],['companies'=>6,'rate'=>3],['companies'=>10,'rate'=>5]]];
    public static function policy(): array {
        $p = self::DEFAULTS;
        foreach (['year1','year2','year3','max_discount','hold_days'] as $key) $p[$key] = (float) SystemSetting::get('distributor_'.$key, $p[$key]);
        $tiers = json_decode((string) SystemSetting::get('distributor_tiers', json_encode($p['tiers'])), true);
        if (is_array($tiers)) $p['tiers'] = $tiers;
        return $p;
    }
    public static function rateForYear(int $year): float { $p=self::policy(); return $year <= 3 ? (float) $p['year'.$year] : 0; }
    public static function discountFor(?\App\Models\Agent $agent): float { return $agent ? min((float)$agent->discount_percent, (float)self::policy()['max_discount']) : 0; }
}