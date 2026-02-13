<?php

namespace App\Services;

use App\Models\HsUsagePattern;
use App\Models\Invoice;
use Illuminate\Support\Facades\Log;

class HsUsagePatternService
{
    public static function recordFromInvoiceCreation(array $items): void
    {
        try {
            foreach ($items as $item) {
                $hsCode = $item['hs_code'] ?? null;
                $sro = $item['sro_schedule_no'] ?? null;
                $serial = $item['serial_no'] ?? null;

                if (empty($hsCode) || (empty($sro) && empty($serial))) continue;

                $scheduleType = $item['schedule_type'] ?? 'standard';
                $taxRate = isset($item['tax_rate']) ? floatval($item['tax_rate']) : null;

                $pattern = HsUsagePattern::firstOrCreate(
                    [
                        'hs_code' => $hsCode,
                        'schedule_type' => $scheduleType,
                        'tax_rate' => $taxRate,
                    ],
                    [
                        'sro_schedule_no' => $sro,
                        'sro_item_serial_no' => $serial,
                        'mrp_required' => !empty($item['mrp']),
                        'sale_type' => $item['sale_type'] ?? null,
                        'success_count' => 0,
                        'rejection_count' => 0,
                        'confidence_score' => 0,
                        'admin_status' => 'auto',
                    ]
                );

                if ($sro) $pattern->sro_schedule_no = $sro;
                if ($serial) $pattern->sro_item_serial_no = $serial;
                $pattern->last_used_at = now();
                $pattern->integrity_hash = hash('sha256', $pattern->hs_code . $pattern->schedule_type . $pattern->tax_rate . $pattern->success_count . $pattern->rejection_count);
                $pattern->save();
            }
        } catch (\Exception $e) {
            Log::warning("HsUsagePatternService::recordFromInvoiceCreation failed: " . $e->getMessage());
        }
    }

    public static function recordSuccess(Invoice $invoice): void
    {
        try {
            $invoice->loadMissing('items');

            foreach ($invoice->items as $item) {
                if (empty($item->hs_code)) continue;

                $pattern = HsUsagePattern::firstOrCreate(
                    [
                        'hs_code' => $item->hs_code,
                        'schedule_type' => $item->schedule_type ?? 'standard',
                        'tax_rate' => $item->tax_rate,
                    ],
                    [
                        'sro_schedule_no' => $item->sro_schedule_no,
                        'sro_item_serial_no' => $item->serial_no,
                        'mrp_required' => !empty($item->mrp),
                        'sale_type' => $item->sale_type,
                        'success_count' => 0,
                        'rejection_count' => 0,
                        'confidence_score' => 0,
                        'admin_status' => 'auto',
                    ]
                );

                $pattern->success_count += 1;
                $pattern->last_used_at = now();
                $pattern->sro_schedule_no = $item->sro_schedule_no ?? $pattern->sro_schedule_no;
                $pattern->sro_item_serial_no = $item->serial_no ?? $pattern->sro_item_serial_no;
                $pattern->mrp_required = !empty($item->mrp) ? true : $pattern->mrp_required;
                $pattern->sale_type = $item->sale_type ?? $pattern->sale_type;
                $pattern->confidence_score = self::calculateConfidence($pattern->success_count, $pattern->rejection_count);
                $pattern->integrity_hash = hash('sha256', $pattern->hs_code . $pattern->schedule_type . $pattern->tax_rate . $pattern->success_count . $pattern->rejection_count);

                if ($pattern->success_count >= 3 && $pattern->admin_status === 'auto') {
                    $pattern->admin_status = 'approved';
                }

                $pattern->save();
            }
        } catch (\Exception $e) {
            Log::warning("HsUsagePatternService::recordSuccess failed for invoice #{$invoice->id}: " . $e->getMessage());
        }
    }

    public static function recordRejection(string $hsCode, string $scheduleType, $taxRate): void
    {
        try {
            $pattern = HsUsagePattern::firstOrCreate(
                [
                    'hs_code' => $hsCode,
                    'schedule_type' => $scheduleType,
                    'tax_rate' => $taxRate,
                ],
                [
                    'success_count' => 0,
                    'rejection_count' => 0,
                    'confidence_score' => 0,
                    'admin_status' => 'auto',
                ]
            );

            $pattern->rejection_count += 1;
            $pattern->confidence_score = self::calculateConfidence($pattern->success_count, $pattern->rejection_count);
            $pattern->integrity_hash = hash('sha256', $pattern->hs_code . $pattern->schedule_type . $pattern->tax_rate . $pattern->success_count . $pattern->rejection_count);
            $pattern->save();
        } catch (\Exception $e) {
            Log::warning("HsUsagePatternService::recordRejection failed for HS {$hsCode}: " . $e->getMessage());
        }
    }

    public static function calculateConfidence(int $successCount, int $rejectionCount): float
    {
        $base = $successCount * 5;
        $penalty = $rejectionCount * 10;
        $confidence = max(0, $base - $penalty);
        return min(95, $confidence);
    }

    public static function getSuggestions(string $hsCode): ?array
    {
        $patterns = HsUsagePattern::where('hs_code', $hsCode)
            ->where(function ($q) {
                $q->where('admin_status', 'approved')
                  ->orWhere(function ($q2) {
                      $q2->where('admin_status', 'auto')
                         ->where('success_count', '>=', 3);
                  });
            })
            ->where(function ($q) {
                $q->where('confidence_score', '>=', 60)
                  ->orWhere('success_count', '>=', 3);
            })
            ->whereNotNull('sro_schedule_no')
            ->where('sro_schedule_no', '!=', '')
            ->orderByDesc('confidence_score')
            ->orderByDesc('success_count')
            ->get();

        if ($patterns->isEmpty()) {
            return null;
        }

        $suggestions = [];
        foreach ($patterns as $pattern) {
            $suggestions[] = [
                'label' => 'Community Pattern Suggestion',
                'schedule_type' => $pattern->schedule_type,
                'tax_rate' => (float) $pattern->tax_rate,
                'sro_schedule_no' => $pattern->sro_schedule_no,
                'sro_item_serial_no' => $pattern->sro_item_serial_no,
                'mrp_required' => $pattern->mrp_required,
                'sale_type' => $pattern->sale_type,
                'success_count' => $pattern->success_count,
            ];
        }

        return $suggestions;
    }

    public static function getSroSuggestionForHs(string $hsCode, ?string $scheduleType = null): ?array
    {
        $query = HsUsagePattern::where('hs_code', $hsCode)
            ->whereNotNull('sro_schedule_no')
            ->where('sro_schedule_no', '!=', '');

        if ($scheduleType) {
            $query->where('schedule_type', $scheduleType);
        }

        $pattern = $query->orderByDesc('success_count')
            ->orderByDesc('confidence_score')
            ->first();

        if (!$pattern || (empty($pattern->sro_schedule_no) && empty($pattern->sro_item_serial_no))) {
            return null;
        }

        return [
            'sro_schedule_no' => $pattern->sro_schedule_no,
            'serial_no' => $pattern->sro_item_serial_no,
            'schedule_type' => $pattern->schedule_type,
            'tax_rate' => (float) $pattern->tax_rate,
            'source' => 'learned',
            'success_count' => $pattern->success_count,
        ];
    }
}
