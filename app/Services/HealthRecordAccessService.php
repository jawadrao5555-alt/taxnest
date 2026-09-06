<?php

namespace App\Services;

use App\Models\Company;
use App\Models\HealthDoctor;
use App\Models\HealthPatient;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Record-level privacy, on top of the capability layer.
 *
 * HealthAccessService answers "may this role read clinical records at all?".
 * That is not the whole question. A patient may ask that THEIR file not be
 * browsable by every clinician in the building — a colleague's family member, a
 * public figure, an HIV or psychiatric case. Marking the file confidential says
 * so, and from that moment the clinical record opens only for:
 *
 *   - the organisation's owner or administrator, and
 *   - a doctor who has actually treated this patient.
 *
 * "Has actually treated" is deliberately evidence-based: it is derived from the
 * visits on file, not from a tick somebody could set. A doctor who has never
 * seen the patient has no clinical reason to read the notes, and the panel does
 * not ask them to justify it — it simply does not open.
 *
 * The demographic record (name, phone, next of kin) stays visible to anyone
 * with patients.view, because reception still has to be able to book them in.
 * Confidentiality protects the clinical content, not the patient's existence.
 */
class HealthRecordAccessService
{
    /** Per-request memo: "userId:patientId" => bool. */
    protected static array $treatingCache = [];

    /** Per-request memo of confidential views already recorded. */
    protected static array $viewLogged = [];

    /**
     * May this person open the clinical record (notes, diagnosis, prescription)
     * of this patient?
     */
    public static function canOpenClinical(?User $user, ?HealthPatient $patient, ?Company $company = null): bool
    {
        if (!$user || !$patient) {
            return false;
        }

        // Reading the file is either a clinician's job (clinical.view) or a
        // nurse's (nursing.record) — the ward cannot take a blood pressure on a
        // record it may not open. What each of them may WRITE is a separate
        // question, answered by canWriteClinical() below.
        if (!HealthAccessService::canAny($user, 'clinical.view|nursing.record', $company)) {
            return false;
        }

        if (!$patient->is_confidential) {
            return true;
        }

        if (HealthAccessService::isAdministrative($user)) {
            return self::recordSensitiveOpen($user, $patient, 'administrative');
        }

        return self::isTreatingClinician($user, $patient)
            ? self::recordSensitiveOpen($user, $patient, 'treating_clinician')
            : false;
    }

    /**
     * Record that a confidential file was opened, and say yes.
     *
     * Recording lives HERE rather than in each controller on purpose: this
     * method is the single door every confidential clinical record opens
     * through, so a new screen cannot reach one without the trail hearing about
     * it. A gate that is enforced in one place and logged in six is a gate that
     * will eventually be enforced in seven.
     *
     * Only CONFIDENTIAL files are recorded. Logging every ordinary consultation
     * a doctor opens would bury the handful of views that actually matter under
     * the hospital's normal day.
     *
     * The event carries who, when and which file — never what was in it.
     */
    protected static function recordSensitiveOpen(User $user, HealthPatient $patient, string $basis): bool
    {
        $key = $user->id . ':' . $patient->id;

        // Once per request. One screen can ask the same question several times
        // while it renders, and three identical rows are not three views.
        if (isset(self::$viewLogged[$key])) {
            return true;
        }
        self::$viewLogged[$key] = true;

        \App\Services\HealthAudit\HealthAuditRecorder::record('record_view.confidential', [
            'actor' => $user,
            'company_id' => $patient->company_id,
            'branch_id' => $patient->branch_id,
            'category' => 'record_view',
            'action' => 'viewed',
            'entity_type' => 'health_patients',
            'entity_id' => $patient->id,
            'entity_label' => $patient->mrn,
            'health_patient_id' => $patient->id,
            'sensitive' => true,
            'meta' => ['basis' => $basis],
        ]);

        return true;
    }

    /**
     * May this person WRITE into the clinical record of this patient?
     *
     * Same confidentiality rule, plus the write capability. Note that an
     * administrator can read a confidential file but still cannot write into it
     * unless their role carries clinical.write — reading for governance and
     * authoring a medical note are different acts.
     */
    public static function canWriteClinical(?User $user, ?HealthPatient $patient, ?Company $company = null): bool
    {
        if (!$user || !$patient) {
            return false;
        }

        if (!HealthAccessService::can($user, 'clinical.write', $company)) {
            return false;
        }

        if (!$patient->is_confidential) {
            return true;
        }

        return HealthAccessService::isAdministrative($user) || self::isTreatingClinician($user, $patient);
    }

    /**
     * Is this user a practitioner who has an encounter on this patient's file?
     *
     * Matched through health_doctors.user_id — the link between a login and a
     * practitioner profile. A visiting consultant with no login can never be
     * this user, which is correct: they are not signing in either.
     */
    public static function isTreatingClinician(?User $user, ?HealthPatient $patient): bool
    {
        if (!$user || !$patient) {
            return false;
        }

        $key = $user->id . ':' . $patient->id;
        if (array_key_exists($key, self::$treatingCache)) {
            return self::$treatingCache[$key];
        }

        $result = false;
        try {
            if (Schema::hasTable('health_doctors') && Schema::hasTable('health_visits')) {
                $doctorIds = HealthDoctor::withoutGlobalScopes()
                    ->where('company_id', $user->company_id)
                    ->where('user_id', $user->id)
                    ->pluck('id');

                if ($doctorIds->isNotEmpty()) {
                    $result = DB::table('health_visits')
                        ->where('company_id', $user->company_id)
                        ->where('health_patient_id', $patient->id)
                        ->whereIn('health_doctor_id', $doctorIds)
                        ->exists();
                }
            }
        } catch (\Throwable $e) {
            $result = false;
        }

        return self::$treatingCache[$key] = $result;
    }

    /**
     * Restrict a patient-joined query so confidential files a person may not
     * read never appear in a clinical LIST at all.
     *
     * Hiding them is the right default for a list: a row that says
     * "confidential — you may not open this" still tells a curious colleague
     * that the person attended the clinic today, which is exactly what the
     * patient asked not to happen.
     *
     * @param  string  $patientColumn  the foreign key on the query's own table
     */
    public static function hideConfidential($query, ?User $user, string $patientColumn = 'health_patient_id')
    {
        if (!$user || HealthAccessService::isAdministrative($user)) {
            return $query;
        }

        $doctorIds = [];
        try {
            if (Schema::hasTable('health_doctors')) {
                $doctorIds = HealthDoctor::withoutGlobalScopes()
                    ->where('company_id', $user->company_id)
                    ->where('user_id', $user->id)
                    ->pluck('id')
                    ->map(fn ($id) => (int) $id)
                    ->all();
            }
        } catch (\Throwable $e) {
            $doctorIds = [];
        }

        $table = $query->getModel()->getTable();
        $qualified = $table . '.' . $patientColumn;
        $companyId = $user->company_id;

        return $query->where(function ($q) use ($qualified, $doctorIds, $companyId) {
            $q->whereNotExists(function ($sub) use ($qualified, $companyId) {
                $sub->select(DB::raw(1))
                    ->from('health_patients')
                    ->whereColumn('health_patients.id', $qualified)
                    ->where('health_patients.company_id', $companyId)
                    ->where('health_patients.is_confidential', true);
            });

            if (!empty($doctorIds)) {
                // …unless this clinician has treated them, in which case the
                // encounter is theirs to see.
                $q->orWhereExists(function ($sub) use ($qualified, $doctorIds, $companyId) {
                    $sub->select(DB::raw(1))
                        ->from('health_visits')
                        ->whereColumn('health_visits.health_patient_id', $qualified)
                        ->where('health_visits.company_id', $companyId)
                        ->whereIn('health_visits.health_doctor_id', $doctorIds);
                });
            }
        });
    }

    public static function forget(): void
    {
        self::$treatingCache = [];
    }
}
