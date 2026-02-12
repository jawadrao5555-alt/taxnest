<?php

namespace App\Services;

use App\Models\HsIntelligenceLog;
use App\Models\HsMasterGlobal;
use App\Models\HsRejectionHistory;
use Illuminate\Support\Facades\DB;

class HsIntelligenceService
{
    const WEIGHT_4DIGIT_MATCH = 30;
    const WEIGHT_2DIGIT_CHAPTER = 15;
    const WEIGHT_TAX_FREQUENCY = 20;
    const WEIGHT_SCHEDULE_FREQUENCY = 15;
    const WEIGHT_REJECTION_HISTORY = 10;
    const WEIGHT_INDUSTRY_USAGE = 10;

    public static function generateSuggestion(string $hsCode): ?array
    {
        $normalizedHs = preg_replace('/[^0-9]/', '', $hsCode);
        if (strlen($normalizedHs) < 4) return null;

        $fourDigitPrefix = substr($normalizedHs, 0, 4);
        $twoDigitChapter = substr($normalizedHs, 0, 2);

        $fourDigitMatches = HsMasterGlobal::where('hs_code', 'like', $fourDigitPrefix . '%')
            ->where('is_active', true)
            ->get();

        $twoDigitMatches = collect();
        if ($fourDigitMatches->isEmpty()) {
            $twoDigitMatches = HsMasterGlobal::where('hs_code', 'like', $twoDigitChapter . '%')
                ->where('is_active', true)
                ->limit(100)
                ->get();
        }

        $invoiceItems = DB::table('invoice_items')
            ->where('hs_code', 'like', $fourDigitPrefix . '%')
            ->select('schedule_type', 'tax_rate', DB::raw('count(*) as cnt'))
            ->groupBy('schedule_type', 'tax_rate')
            ->orderByDesc('cnt')
            ->limit(10)
            ->get();

        $chapterInvoiceItems = collect();
        if ($invoiceItems->isEmpty()) {
            $chapterInvoiceItems = DB::table('invoice_items')
                ->where('hs_code', 'like', $twoDigitChapter . '%')
                ->select('schedule_type', 'tax_rate', DB::raw('count(*) as cnt'))
                ->groupBy('schedule_type', 'tax_rate')
                ->orderByDesc('cnt')
                ->limit(10)
                ->get();
        }

        $rejection = HsRejectionHistory::where('hs_code', $normalizedHs)->first();

        $scheduleScores = [];
        $taxRateScores = [];
        $sroFlags = [];
        $serialFlags = [];
        $mrpFlags = [];
        $totalRecords = 0;
        $breakdown = [];

        if ($fourDigitMatches->isNotEmpty()) {
            $totalRecords += $fourDigitMatches->count();
            foreach ($fourDigitMatches as $m) {
                $st = $m->schedule_type ?? 'standard';
                $scheduleScores[$st] = ($scheduleScores[$st] ?? 0) + self::WEIGHT_4DIGIT_MATCH;
                $tr = (string)($m->default_tax_rate ?? '18');
                $taxRateScores[$tr] = ($taxRateScores[$tr] ?? 0) + self::WEIGHT_4DIGIT_MATCH;
                if ($m->sro_required) $sroFlags[] = true;
                if ($m->serial_required) $serialFlags[] = true;
                if ($m->mrp_required) $mrpFlags[] = true;
            }
            $breakdown['4digit_match'] = ['weight' => self::WEIGHT_4DIGIT_MATCH, 'records' => $fourDigitMatches->count()];
        }

        if ($twoDigitMatches->isNotEmpty()) {
            $totalRecords += $twoDigitMatches->count();
            foreach ($twoDigitMatches as $m) {
                $st = $m->schedule_type ?? 'standard';
                $scheduleScores[$st] = ($scheduleScores[$st] ?? 0) + self::WEIGHT_2DIGIT_CHAPTER;
                $tr = (string)($m->default_tax_rate ?? '18');
                $taxRateScores[$tr] = ($taxRateScores[$tr] ?? 0) + self::WEIGHT_2DIGIT_CHAPTER;
                if ($m->sro_required) $sroFlags[] = true;
                if ($m->serial_required) $serialFlags[] = true;
                if ($m->mrp_required) $mrpFlags[] = true;
            }
            $breakdown['2digit_chapter'] = ['weight' => self::WEIGHT_2DIGIT_CHAPTER, 'records' => $twoDigitMatches->count()];
        }

        $historyItems = $invoiceItems->isNotEmpty() ? $invoiceItems : $chapterInvoiceItems;
        $historyWeight = $invoiceItems->isNotEmpty() ? self::WEIGHT_TAX_FREQUENCY : (int)(self::WEIGHT_TAX_FREQUENCY * 0.5);

        if ($historyItems->isNotEmpty()) {
            $totalHistoryCount = $historyItems->sum('cnt');
            $totalRecords += $totalHistoryCount;
            foreach ($historyItems as $hi) {
                $st = $hi->schedule_type ?? 'standard';
                $proportion = $totalHistoryCount > 0 ? ($hi->cnt / $totalHistoryCount) : 0;
                $scheduleScores[$st] = ($scheduleScores[$st] ?? 0) + (int)(self::WEIGHT_SCHEDULE_FREQUENCY * $proportion);
                $tr = (string)($hi->tax_rate ?? '18');
                $taxRateScores[$tr] = ($taxRateScores[$tr] ?? 0) + (int)($historyWeight * $proportion);
            }
            $breakdown['tax_frequency'] = ['weight' => $historyWeight, 'records' => $totalHistoryCount];
            $breakdown['schedule_frequency'] = ['weight' => self::WEIGHT_SCHEDULE_FREQUENCY, 'records' => $totalHistoryCount];
        }

        $rejectionFactor = 0;
        $fbrRejectionPenalty = 0;
        if ($rejection && $rejection->rejection_count > 0) {
            $rejectionFactor = min($rejection->rejection_count * 5, self::WEIGHT_REJECTION_HISTORY);

            if ($rejection->rejection_count > 10) {
                $fbrRejectionPenalty = 25;
            } elseif ($rejection->rejection_count > 5) {
                $fbrRejectionPenalty = 25;
            } elseif ($rejection->rejection_count > 3) {
                $fbrRejectionPenalty = 15;
            }

            $breakdown['rejection_history'] = [
                'weight' => self::WEIGHT_REJECTION_HISTORY,
                'penalty' => $rejectionFactor,
                'fbr_penalty' => $fbrRejectionPenalty,
                'count' => $rejection->rejection_count,
                'last_error' => $rejection->error_code,
                'environment' => $rejection->environment ?? 'unknown',
            ];
        }

        $industryFactor = 0;
        $sectorMatch = HsMasterGlobal::where('hs_code', 'like', $fourDigitPrefix . '%')
            ->whereNotNull('last_source')
            ->where('is_active', true)
            ->count();
        if ($sectorMatch > 0) {
            $industryFactor = min($sectorMatch * 3, self::WEIGHT_INDUSTRY_USAGE);
            $breakdown['industry_usage'] = ['weight' => self::WEIGHT_INDUSTRY_USAGE, 'factor' => $industryFactor, 'sources' => $sectorMatch];
        }

        if (empty($scheduleScores) && empty($taxRateScores)) {
            return null;
        }

        arsort($scheduleScores);
        arsort($taxRateScores);

        $suggestedSchedule = array_key_first($scheduleScores);
        $suggestedTaxRate = (float) array_key_first($taxRateScores);

        $maxScore = max(array_values($scheduleScores));
        $totalWeight = self::WEIGHT_4DIGIT_MATCH + self::WEIGHT_2DIGIT_CHAPTER +
                       self::WEIGHT_TAX_FREQUENCY + self::WEIGHT_SCHEDULE_FREQUENCY +
                       self::WEIGHT_INDUSTRY_USAGE;

        $rawConfidence = min(100, (int)(($maxScore / max($totalWeight, 1)) * 100));
        $rawConfidence = max(0, $rawConfidence - $rejectionFactor);
        $rawConfidence = max(0, $rawConfidence - $fbrRejectionPenalty);
        $rawConfidence = min(100, $rawConfidence + $industryFactor);

        if ($rejection && $rejection->rejection_count > 10) {
            $rawConfidence = min($rawConfidence, 40);
        }

        $suggestedSro = in_array($suggestedSchedule, ['3rd_schedule', 'exempt', 'zero_rated']);
        $suggestedSerial = ($suggestedSchedule === '3rd_schedule');
        $suggestedMrp = ($suggestedSchedule === '3rd_schedule' || $suggestedSchedule === 'standard');

        if (!empty($sroFlags)) $suggestedSro = (count(array_filter($sroFlags)) / count($sroFlags)) >= 0.5;
        if (!empty($serialFlags)) $suggestedSerial = (count(array_filter($serialFlags)) / count($serialFlags)) >= 0.5;
        if (!empty($mrpFlags)) $suggestedMrp = (count(array_filter($mrpFlags)) / count($mrpFlags)) >= 0.5;

        $suggestion = [
            'hs_code' => $normalizedHs,
            'suggested_schedule_type' => $suggestedSchedule,
            'suggested_tax_rate' => $suggestedTaxRate,
            'suggested_sro_required' => $suggestedSro,
            'suggested_serial_required' => $suggestedSerial,
            'suggested_mrp_required' => $suggestedMrp,
            'confidence_score' => $rawConfidence,
            'weight_breakdown' => $breakdown,
            'based_on_records_count' => $totalRecords,
            'rejection_factor' => $rejectionFactor + $fbrRejectionPenalty,
            'industry_factor' => $industryFactor,
        ];

        HsIntelligenceLog::create(array_merge($suggestion, [
            'weight_breakdown' => json_encode($breakdown),
            'created_at' => now(),
        ]));

        return $suggestion;
    }

    public static function getLatestSuggestion(string $hsCode): ?HsIntelligenceLog
    {
        $normalizedHs = preg_replace('/[^0-9]/', '', $hsCode);
        return HsIntelligenceLog::where('hs_code', $normalizedHs)
            ->orderByDesc('created_at')
            ->first();
    }

    public static function getRejectionHistory(string $hsCode): ?HsRejectionHistory
    {
        $normalizedHs = preg_replace('/[^0-9]/', '', $hsCode);
        return HsRejectionHistory::where('hs_code', $normalizedHs)->first();
    }

    public static function recordRejection(string $hsCode, string $reason): void
    {
        $normalizedHs = preg_replace('/[^0-9]/', '', $hsCode);
        $existing = HsRejectionHistory::where('hs_code', $normalizedHs)->first();

        if ($existing) {
            $existing->update([
                'rejection_count' => $existing->rejection_count + 1,
                'last_rejection_reason' => $reason,
                'last_seen_at' => now(),
                'updated_at' => now(),
            ]);
        } else {
            HsRejectionHistory::create([
                'hs_code' => $normalizedHs,
                'rejection_count' => 1,
                'last_rejection_reason' => $reason,
                'last_seen_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public static function recordFbrRejection(string $hsCode, ?string $errorCode, ?string $errorMessage, string $scheduleType, $taxRate, ?string $sroNumber, string $environment): void
    {
        $normalizedHs = preg_replace('/[^0-9]/', '', $hsCode);
        $existing = HsRejectionHistory::where('hs_code', $normalizedHs)->first();

        $reason = "FBR rejection: " . ($errorMessage ?? $errorCode ?? 'Unknown error');

        if ($existing) {
            $existing->update([
                'rejection_count' => $existing->rejection_count + 1,
                'last_rejection_reason' => $reason,
                'error_code' => $errorCode,
                'error_message' => $errorMessage,
                'last_rejected_at' => now(),
                'environment' => $environment,
                'last_seen_at' => now(),
                'updated_at' => now(),
            ]);
        } else {
            HsRejectionHistory::create([
                'hs_code' => $normalizedHs,
                'rejection_count' => 1,
                'last_rejection_reason' => $reason,
                'error_code' => $errorCode,
                'error_message' => $errorMessage,
                'last_rejected_at' => now(),
                'environment' => $environment,
                'last_seen_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public static function getTopRejectedHsCodes(int $days = 30, int $limit = 10)
    {
        return HsRejectionHistory::where('last_rejected_at', '>=', now()->subDays($days))
            ->orWhere(function ($q) use ($days) {
                $q->whereNull('last_rejected_at')
                  ->where('last_seen_at', '>=', now()->subDays($days));
            })
            ->where('rejection_count', '>', 0)
            ->orderByDesc('rejection_count')
            ->limit($limit)
            ->get();
    }

    public static function getRiskLevel(int $confidenceScore): string
    {
        if ($confidenceScore >= 90) return 'verified';
        if ($confidenceScore >= 71) return 'high';
        if ($confidenceScore >= 41) return 'medium';
        return 'low';
    }

    public static function getRiskColor(string $level): string
    {
        return match($level) {
            'verified' => 'green',
            'high' => 'blue',
            'medium' => 'amber',
            'low' => 'red',
            default => 'gray',
        };
    }

    public static function getConfidenceBadge(int $score): array
    {
        $level = self::getRiskLevel($score);
        $color = self::getRiskColor($level);
        $label = strtoupper($level);
        return ['level' => $level, 'color' => $color, 'label' => $label, 'score' => $score];
    }
}
