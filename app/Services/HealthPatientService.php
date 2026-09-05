<?php

namespace App\Services;

use App\Models\HealthPatient;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Patient identity: finding the same person again, and refusing to let the
 * front desk create a second file for them by accident.
 *
 * Duplicate detection is a WARNING, not a wall — with one exception. A CNIC is
 * a unique national identifier, so two active files carrying the same one are
 * always the same person and that is refused outright. Everything else (same
 * phone, same name and age) is a strong hint that reception must look at, but a
 * family really can share one phone number and two cousins really can share a
 * name, so the desk is shown the matches and allowed to say "no, this is
 * somebody new". A blocked registration would just be worked around by typing a
 * space into the name.
 */
class HealthPatientService
{
    /** Never offer more than this many candidates — the desk has a queue. */
    private const MAX_CANDIDATES = 8;

    /**
     * Existing files that might be the same person.
     *
     * @param  array{name?:string,phone?:string,cnic?:string,date_of_birth?:string,age_years?:int|string|null}  $data
     * @return Collection<int,array{patient:HealthPatient,reason:string,hard:bool}>
     */
    public static function findDuplicates(int $companyId, array $data, ?int $ignoreId = null): Collection
    {
        $phone = HealthPatient::normalizePhone($data['phone'] ?? null);
        $cnic = HealthPatient::normalizeCnic($data['cnic'] ?? null);
        $name = self::normalizeName($data['name'] ?? '');

        if ($phone === null && $cnic === null && $name === '') {
            return collect();
        }

        $query = HealthPatient::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->where('is_active', true);

        if ($ignoreId) {
            $query->where('id', '!=', $ignoreId);
        }

        $query->where(function ($q) use ($phone, $cnic, $name) {
            if ($cnic !== null) {
                $q->orWhere('cnic', $cnic);
            }
            if ($phone !== null) {
                $q->orWhere('phone_digits', $phone);
            }
            if ($name !== '') {
                // Cheap prefix match on the raw name; the exact comparison is
                // done in PHP below on the normalised form, so "Muhammad  Ali"
                // and "muhammad ali" still meet.
                $q->orWhere('name', 'like', mb_substr($data['name'] ?? '', 0, 40) . '%');
            }
        });

        $candidates = $query->orderByDesc('id')->limit(40)->get();

        $ageYears = isset($data['age_years']) && $data['age_years'] !== '' ? (int) $data['age_years'] : null;
        $dob = $data['date_of_birth'] ?? null;

        $matches = [];
        foreach ($candidates as $candidate) {
            $reason = null;
            $hard = false;

            if ($cnic !== null && $candidate->cnic === $cnic) {
                $reason = 'cnic';
                $hard = true;
            } elseif ($phone !== null && $candidate->phone_digits === $phone) {
                $reason = 'phone';
            } elseif ($name !== '' && self::normalizeName($candidate->name) === $name) {
                // A name on its own is far too common in Pakistan to flag. It
                // only counts as a hint when the age or birthday agrees too.
                $sameDob = $dob && $candidate->date_of_birth
                    && $candidate->date_of_birth->toDateString() === (string) $dob;
                $sameAge = $ageYears !== null && (int) $candidate->age_years === $ageYears;
                if ($sameDob || $sameAge) {
                    $reason = 'name_age';
                }
            }

            if ($reason !== null) {
                $matches[] = ['patient' => $candidate, 'reason' => $reason, 'hard' => $hard];
            }

            if (count($matches) >= self::MAX_CANDIDATES) {
                break;
            }
        }

        // Hard matches first: the one that will actually be refused should be
        // the first thing reception reads.
        usort($matches, fn ($a, $b) => ($b['hard'] <=> $a['hard']));

        return collect($matches);
    }

    /**
     * Register a patient and allocate their permanent medical record number.
     *
     * The number is consumed inside the same transaction as the insert, so a
     * failed registration cannot burn one and a successful one cannot share.
     */
    public static function register(int $companyId, array $attributes): HealthPatient
    {
        return DB::transaction(function () use ($companyId, $attributes) {
            $attributes['company_id'] = $companyId;
            $attributes['mrn'] = HealthNumberService::medicalRecordNumber($companyId);
            $attributes['phone_digits'] = HealthPatient::normalizePhone($attributes['phone'] ?? null);
            $attributes['cnic'] = HealthPatient::normalizeCnic($attributes['cnic'] ?? null);

            return HealthPatient::create($attributes);
        });
    }

    /**
     * Free-text search across the front desk's four handles: record number,
     * name, phone and CNIC. Digits are matched against the normalised phone so
     * "0300-1234567" finds a row stored as "3001234567".
     */
    public static function applySearch($query, ?string $term)
    {
        $term = trim((string) $term);
        if ($term === '') {
            return $query;
        }

        $digits = preg_replace('/\D+/', '', $term) ?? '';
        $phone = HealthPatient::normalizePhone($term);

        return $query->where(function ($q) use ($term, $digits, $phone) {
            $q->where('mrn', 'like', '%' . $term . '%')
                ->orWhere('name', 'like', '%' . $term . '%')
                ->orWhere('guardian_name', 'like', '%' . $term . '%');

            if ($digits !== '') {
                $q->orWhere('phone', 'like', '%' . $digits . '%')
                    ->orWhere('cnic', 'like', '%' . $digits . '%');
            }
            if ($phone !== null) {
                $q->orWhere('phone_digits', 'like', '%' . $phone . '%');
            }
        });
    }

    /** Case-folded, whitespace-collapsed name used for duplicate comparison. */
    public static function normalizeName(?string $name): string
    {
        $clean = preg_replace('/\s+/u', ' ', trim((string) $name)) ?? '';

        return mb_strtolower($clean);
    }

    public static function duplicateReasonKey(string $reason): string
    {
        return 'health.dup_reason_' . $reason;
    }
}
