<?php

namespace App\Services;

use App\Models\GlobalHsMaster;
use App\Models\HsUnmappedLog;
use Illuminate\Support\Facades\DB;

class GlobalHsService
{
    public static function lookup(string $hsCode, ?int $companyId = null, ?int $invoiceId = null): ?array
    {
        $normalized = preg_replace('/[^0-9]/', '', $hsCode);
        if (empty($normalized)) return null;

        $master = GlobalHsMaster::where('hs_code', $normalized)->first();

        if ($master) {
            return [
                'found' => true,
                'hs_code' => $master->hs_code,
                'pct_code' => $master->pct_code,
                'schedule_type' => $master->schedule_type,
                'tax_rate' => $master->tax_rate,
                'default_uom' => $master->default_uom,
                'sro_required' => $master->sro_required,
                'sro_number' => $master->sro_number,
                'sro_item_serial_no' => $master->sro_item_serial_no,
                'mrp_required' => $master->mrp_required,
                'sector_tag' => $master->sector_tag,
                'risk_weight' => $master->risk_weight,
                'mapping_status' => $master->mapping_status,
                'source' => 'global_hs_master',
            ];
        }

        if ($companyId) {
            self::logUnmapped($normalized, $companyId, $invoiceId);
        }

        return null;
    }

    public static function logUnmapped(string $hsCode, int $companyId, ?int $invoiceId = null): void
    {
        $normalized = preg_replace('/[^0-9]/', '', $hsCode);
        if (empty($normalized)) return;

        $existing = HsUnmappedLog::where('hs_code', $normalized)
            ->where('company_id', $companyId)
            ->first();

        if ($existing) {
            $existing->update([
                'frequency_count' => $existing->frequency_count + 1,
                'last_seen_at' => now(),
                'invoice_id' => $invoiceId ?? $existing->invoice_id,
            ]);
        } else {
            HsUnmappedLog::create([
                'hs_code' => $normalized,
                'company_id' => $companyId,
                'invoice_id' => $invoiceId,
                'frequency_count' => 1,
                'first_seen_at' => now(),
                'last_seen_at' => now(),
            ]);
        }
    }

    public static function resolveForInvoiceItem(string $hsCode, float $standardTaxRate = 18.0, ?int $companyId = null, ?int $invoiceId = null): array
    {
        $normalized = preg_replace('/[^0-9]/', '', $hsCode);

        $globalLookup = self::lookup($normalized, $companyId, $invoiceId);
        if ($globalLookup && $globalLookup['found']) {
            $rules = ScheduleEngine::resolveValidationRules(
                $globalLookup['schedule_type'],
                $globalLookup['tax_rate'],
                $standardTaxRate
            );
            return array_merge($globalLookup, [
                'requires_sro' => $rules['requires_sro'],
                'requires_serial' => $rules['requires_serial'],
                'requires_mrp' => $rules['requires_mrp'],
                'standard_tax_rate' => $standardTaxRate,
            ]);
        }

        $scheduleResult = ScheduleEngine::lookupByHsCode($normalized, $standardTaxRate);
        if ($scheduleResult) {
            $scheduleResult['source'] = 'schedule_engine';
            $scheduleResult['standard_tax_rate'] = $standardTaxRate;
            return $scheduleResult;
        }

        if ($companyId) {
            self::logUnmapped($normalized, $companyId, $invoiceId);
        }

        return [
            'found' => false,
            'source' => 'none',
        ];
    }

    public static function suggestSro(string $hsCode, string $scheduleType, ?float $taxRate = null, float $standardTaxRate = 18.0): ?array
    {
        $normalized = preg_replace('/[^0-9]/', '', $hsCode);

        $master = GlobalHsMaster::where('hs_code', $normalized)->first();

        $confidence = 'low';
        $suggestion = null;

        if ($master && $master->sro_number) {
            $confidence = 'high';
            $suggestion = [
                'sro' => $master->sro_number,
                'serial' => $master->sro_item_serial_no,
                'description' => "From Global HS Master: {$master->description}",
                'confidence' => $confidence,
                'auto_fill' => false,
                'source' => 'global_hs_master',
            ];
        }

        if (!$suggestion) {
            $engineSuggestion = SroSuggestionService::suggest($scheduleType, $taxRate, $hsCode, $standardTaxRate);
            if ($engineSuggestion) {
                $suggestion = $engineSuggestion;
                $suggestion['source'] = 'sro_suggestion_service';
            }
        }

        return $suggestion;
    }

    public static function seedFromExistingSources(): array
    {
        $seeded = 0;
        $skipped = 0;

        foreach (ScheduleEngine::$hsLookupTable as $hsCode => $data) {
            $rules = ScheduleEngine::resolveValidationRules($data['schedule_type'], $data['tax_rate']);
            $existing = GlobalHsMaster::where('hs_code', $hsCode)->first();
            if ($existing) {
                $skipped++;
                continue;
            }
            GlobalHsMaster::create([
                'hs_code' => $hsCode,
                'pct_code' => $data['pct_code'],
                'schedule_type' => $data['schedule_type'],
                'tax_rate' => $data['tax_rate'],
                'sro_required' => $rules['requires_sro'],
                'mrp_required' => $rules['requires_mrp'],
                'mapping_status' => 'Mapped',
                'risk_weight' => 0,
            ]);
            $seeded++;
        }

        $products = DB::table('products')
            ->whereNotNull('hs_code')
            ->where('hs_code', '!=', '')
            ->get();

        foreach ($products as $product) {
            $hsCode = preg_replace('/[^0-9]/', '', $product->hs_code);
            if (empty($hsCode)) continue;
            $existing = GlobalHsMaster::where('hs_code', $hsCode)->first();
            if ($existing) {
                if (empty($existing->default_uom) && !empty($product->uom)) {
                    $existing->update(['default_uom' => $product->uom]);
                }
                if (empty($existing->description) && !empty($product->name)) {
                    $existing->update(['description' => $product->name]);
                }
                $skipped++;
                continue;
            }
            $scheduleType = self::normalizeScheduleType($product->schedule_type);
            $rules = ScheduleEngine::resolveValidationRules($scheduleType, $product->default_tax_rate);
            GlobalHsMaster::create([
                'hs_code' => $hsCode,
                'description' => $product->name,
                'schedule_type' => $scheduleType,
                'tax_rate' => $product->default_tax_rate ?? 18.0,
                'default_uom' => $product->uom,
                'sro_required' => $rules['requires_sro'],
                'sro_number' => $product->sro_reference ?? null,
                'mrp_required' => $rules['requires_mrp'],
                'mapping_status' => 'Mapped',
                'risk_weight' => 0,
            ]);
            $seeded++;
        }

        $sroRules = DB::table('special_sro_rules')
            ->where('is_active', true)
            ->get();

        foreach ($sroRules as $sro) {
            $hsCode = preg_replace('/[^0-9]/', '', $sro->hs_code ?? '');
            if (empty($hsCode)) continue;
            $existing = GlobalHsMaster::where('hs_code', $hsCode)->first();
            if ($existing) {
                if (empty($existing->sro_number)) {
                    $existing->update(['sro_number' => $sro->sro_number]);
                }
                if (empty($existing->sro_item_serial_no)) {
                    $existing->update(['sro_item_serial_no' => $sro->serial_no]);
                }
                $skipped++;
                continue;
            }
            $scheduleType = self::normalizeScheduleType($sro->schedule_type);
            $rules = ScheduleEngine::resolveValidationRules($scheduleType, $sro->concessionary_rate);
            GlobalHsMaster::create([
                'hs_code' => $hsCode,
                'description' => $sro->description ?? null,
                'schedule_type' => $scheduleType,
                'tax_rate' => $sro->concessionary_rate ?? 0,
                'sro_required' => $rules['requires_sro'],
                'sro_number' => $sro->sro_number,
                'sro_item_serial_no' => $sro->serial_no,
                'mrp_required' => $rules['requires_mrp'],
                'mapping_status' => 'Mapped',
                'risk_weight' => 0,
            ]);
            $seeded++;
        }

        $invoiceItems = DB::table('invoice_items')
            ->select('hs_code', 'description', 'pct_code', 'schedule_type', 'tax_rate', 'default_uom', 'sro_schedule_no', 'serial_no')
            ->whereNotNull('hs_code')
            ->where('hs_code', '!=', '')
            ->groupBy('hs_code', 'description', 'pct_code', 'schedule_type', 'tax_rate', 'default_uom', 'sro_schedule_no', 'serial_no')
            ->get();

        foreach ($invoiceItems as $item) {
            $hsCode = preg_replace('/[^0-9]/', '', $item->hs_code);
            if (empty($hsCode)) continue;
            $existing = GlobalHsMaster::where('hs_code', $hsCode)->first();
            if ($existing) {
                if (empty($existing->default_uom) && $item->default_uom) {
                    $existing->update(['default_uom' => $item->default_uom]);
                }
                $skipped++;
                continue;
            }
            $scheduleType = self::normalizeScheduleType($item->schedule_type);
            $rules = ScheduleEngine::resolveValidationRules($scheduleType, $item->tax_rate);
            GlobalHsMaster::create([
                'hs_code' => $hsCode,
                'description' => $item->description,
                'pct_code' => $item->pct_code,
                'schedule_type' => $scheduleType,
                'tax_rate' => $item->tax_rate ?? 18.0,
                'default_uom' => $item->default_uom,
                'sro_required' => $rules['requires_sro'],
                'sro_number' => $item->sro_schedule_no,
                'sro_item_serial_no' => $item->serial_no,
                'mrp_required' => $rules['requires_mrp'],
                'mapping_status' => 'Partial',
                'risk_weight' => 0,
            ]);
            $seeded++;
        }

        return ['seeded' => $seeded, 'skipped' => $skipped, 'total' => GlobalHsMaster::count()];
    }

    public static function getInsights(): array
    {
        $totalHs = GlobalHsMaster::count();
        $totalUnmapped = HsUnmappedLog::distinct('hs_code')->count('hs_code');
        $mappedCount = GlobalHsMaster::where('mapping_status', 'Mapped')->count();
        $partialCount = GlobalHsMaster::where('mapping_status', 'Partial')->count();
        $unmappedMasterCount = GlobalHsMaster::where('mapping_status', 'Unmapped')->count();

        $bySchedule = GlobalHsMaster::select('schedule_type', DB::raw('count(*) as count'))
            ->groupBy('schedule_type')
            ->pluck('count', 'schedule_type')
            ->toArray();

        $bySector = GlobalHsMaster::select('sector_tag', DB::raw('count(*) as count'))
            ->whereNotNull('sector_tag')
            ->groupBy('sector_tag')
            ->pluck('count', 'sector_tag')
            ->toArray();

        $topUnmapped = HsUnmappedLog::select('hs_code', DB::raw('SUM(frequency_count) as total_freq'), DB::raw('COUNT(DISTINCT company_id) as company_count'))
            ->groupBy('hs_code')
            ->orderByDesc('total_freq')
            ->limit(10)
            ->get()
            ->toArray();

        $sroRequired = GlobalHsMaster::where('sro_required', true)->count();
        $mrpRequired = GlobalHsMaster::where('mrp_required', true)->count();

        $riskDistribution = [
            'low' => GlobalHsMaster::where('risk_weight', '<', 30)->count(),
            'medium' => GlobalHsMaster::whereBetween('risk_weight', [30, 60])->count(),
            'high' => GlobalHsMaster::where('risk_weight', '>', 60)->count(),
        ];

        return [
            'total_hs' => $totalHs,
            'total_unmapped' => $totalUnmapped,
            'mapped' => $mappedCount,
            'partial' => $partialCount,
            'unmapped_master' => $unmappedMasterCount,
            'by_schedule' => $bySchedule,
            'by_sector' => $bySector,
            'top_unmapped' => $topUnmapped,
            'sro_required' => $sroRequired,
            'mrp_required' => $mrpRequired,
            'risk_distribution' => $riskDistribution,
        ];
    }

    private static function normalizeScheduleType(?string $type): string
    {
        if (empty($type)) return 'standard';
        $normalized = strtolower(trim($type));
        $normalized = str_replace([' ', '-'], '_', $normalized);
        $map = [
            '3rd_schedule' => '3rd_schedule',
            '3rd schedule' => '3rd_schedule',
            'third_schedule' => '3rd_schedule',
            'standard' => 'standard',
            'standard_rate' => 'standard',
            'reduced' => 'reduced',
            'reduced_rate' => 'reduced',
            'exempt' => 'exempt',
            'zero_rated' => 'zero_rated',
            'zero rated' => 'zero_rated',
        ];
        return $map[$normalized] ?? 'standard';
    }
}
