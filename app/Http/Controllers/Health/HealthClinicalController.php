<?php

namespace App\Http\Controllers\Health;

use App\Models\HealthPrescription;
use App\Models\HealthPrescriptionItem;
use App\Models\HealthVisit;
use App\Models\HealthVisitAttachment;
use App\Services\HealthNumberService;
use App\Services\HealthOpdService;
use App\Services\HealthPlatformService;
use App\Services\HealthRecordAccessService;
use App\Services\HealthScopeService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

/**
 * The consultation room: the doctor's queue and the encounter record.
 *
 * Two rules run through everything here.
 *
 * 1. Vitals and the clinical narrative are separate saves behind separate
 *    capabilities. A nurse records the observation; only a clinician writes the
 *    diagnosis. Merging them into one form would mean granting nursing staff
 *    write access to the diagnosis to let them enter a blood pressure.
 *
 * 2. A confidential patient's record opens only for the people who need it —
 *    checked per record, not per role, by HealthRecordAccessService.
 */
class HealthClinicalController extends HealthPanelController
{
    /** The doctor's working queue for a day. */
    public function queue(Request $request)
    {
        $user = $this->user();
        $date = $this->resolveDate($request->query('date'));

        $ownDoctorIds = $this->ownDoctorIds();
        $requested = $request->query('doctor_id');
        // A linked practitioner lands on their OWN list. Anything else is a
        // deliberate choice, not a default.
        $doctorFilter = $requested !== null && $requested !== ''
            ? (int) $requested
            : ($ownDoctorIds[0] ?? null);

        $query = HealthVisit::query()
            ->with([
                'patient:id,mrn,name,phone,gender,age_years,age_months,date_of_birth,allergies,is_confidential',
                'doctor:id,name,specialty,room',
                'department:id,name',
            ])
            ->whereDate('visit_date', $date)
            ->orderByRaw("CASE status WHEN 'in_consultation' THEN 0 WHEN 'waiting' THEN 1 ELSE 2 END")
            ->orderBy('id');

        $this->scope($query);
        HealthRecordAccessService::hideConfidential($query, $user);

        if ($doctorFilter) {
            $query->where('health_doctor_id', $doctorFilter);
        }

        $visits = $query->get();

        return view('health.clinical.queue', [
            'visits' => $visits,
            'date' => $date,
            'doctors' => $this->selectableDoctors(),
            'doctorFilter' => $doctorFilter,
            'ownDoctorIds' => $ownDoctorIds,
            'canWrite' => $this->can('clinical.write'),
            'canRecordVitals' => $this->can('nursing.record') || $this->can('clinical.write'),
        ]);
    }

    /** The encounter itself. */
    public function show($id)
    {
        $visit = $this->findVisit($id, [
            'patient', 'doctor', 'branch:id,name', 'department:id,name',
            'attachments', 'prescriptions.items', 'prescriptions.doctor:id,name',
        ]);

        $this->guardClinicalRead($visit);

        // Previous encounters give the doctor the context they would otherwise
        // have to go hunting for — capped, because a chronic patient can have
        // hundreds and the consultation room is not a research desk.
        $historyQuery = HealthVisit::query()
            ->where('health_patient_id', $visit->health_patient_id)
            ->where('id', '!=', $visit->id)
            ->with(['doctor:id,name'])
            ->orderByDesc('visit_date')
            ->orderByDesc('id');
        HealthScopeService::applyBranchScope($historyQuery, $this->user());
        $history = $historyQuery->limit(15)->get();

        return view('health.clinical.visit', [
            'visit' => $visit,
            'patient' => $visit->patient,
            'history' => $history,
            'canWrite' => HealthRecordAccessService::canWriteClinical($this->user(), $visit->patient, $this->company()),
            'canRecordVitals' => $this->can('nursing.record') || $this->can('clinical.write'),
            'canManageFee' => $this->can('appointments.manage'),
            'forms' => HealthPrescriptionItem::FORMS,
            'routes' => HealthPrescriptionItem::ROUTES,
        ]);
    }

    /** The doctor has called this patient in. */
    public function start($id)
    {
        $visit = $this->findVisit($id, ['patient']);
        $this->guardClinicalWrite($visit);

        HealthOpdService::startConsultation($visit);

        return redirect()->route('health.clinical.visit', $visit->id);
    }

    /**
     * Vitals.
     *
     * Behind nursing.record OR clinical.write: on a busy OPD floor the person
     * with the thermometer is usually not the person with the prescription pad.
     */
    public function saveVitals(Request $request, $id)
    {
        $visit = $this->findVisit($id, ['patient']);

        if (!$this->can('nursing.record') && !$this->can('clinical.write')) {
            abort(403, __('health.denied_no_permission'));
        }
        if (!HealthRecordAccessService::canOpenClinical($this->user(), $visit->patient, $this->company())) {
            abort(403, __('health.clinical_confidential_blocked'));
        }
        if ($visit->status === HealthVisit::STATUS_CANCELLED) {
            return back()->with('error', __('health.visit_closed_readonly'));
        }

        $data = $request->validate([
            'temperature_c' => 'nullable|numeric|min:25|max:45',
            'pulse_bpm' => 'nullable|integer|min:20|max:300',
            'respiratory_rate' => 'nullable|integer|min:5|max:90',
            'bp_systolic' => 'nullable|integer|min:40|max:300',
            'bp_diastolic' => 'nullable|integer|min:20|max:200',
            'spo2' => 'nullable|integer|min:40|max:100',
            'weight_kg' => 'nullable|numeric|min:0.5|max:400',
            'height_cm' => 'nullable|numeric|min:20|max:250',
            'blood_sugar' => 'nullable|numeric|min:10|max:900',
        ]);

        // A blank field means "not measured", so it is stored as NULL rather
        // than 0. A zero pulse is not a missing reading, it is a dead patient.
        foreach ($data as $field => $value) {
            $visit->{$field} = ($value === null || $value === '') ? null : $value;
        }

        $visit->vitals_recorded_by = $this->user()?->id;
        $visit->vitals_recorded_at = now();
        $visit->save();

        return back()->with('success', __('health.vitals_saved'));
    }

    /** The clinical narrative: complaint, history, examination, diagnosis, plan. */
    public function saveNotes(Request $request, $id)
    {
        $visit = $this->findVisit($id, ['patient']);
        $this->guardClinicalWrite($visit);

        if (in_array($visit->status, [HealthVisit::STATUS_CANCELLED], true)) {
            return back()->with('error', __('health.visit_closed_readonly'));
        }

        $data = $request->validate([
            'visit_type' => ['nullable', Rule::in(HealthVisit::TYPES)],
            'chief_complaint' => 'nullable|string|max:2000',
            'history' => 'nullable|string|max:5000',
            'examination' => 'nullable|string|max:5000',
            'diagnosis' => 'nullable|string|max:2000',
            'procedures' => 'nullable|string|max:2000',
            'advice' => 'nullable|string|max:5000',
            'clinical_notes' => 'nullable|string|max:5000',
            'follow_up_date' => 'nullable|date|after_or_equal:today',
            'follow_up_notes' => 'nullable|string|max:500',
            'complete' => 'nullable|boolean',
        ]);

        $visit->fill(collect($data)->except(['complete'])->all());

        if ($visit->status === HealthVisit::STATUS_WAITING) {
            $visit->status = HealthVisit::STATUS_IN_CONSULTATION;
            $visit->consultation_started_at = $visit->consultation_started_at ?? now();
        }
        $visit->save();

        if ($request->boolean('complete')) {
            HealthOpdService::complete($visit, $this->user());

            return redirect()->route('health.clinical', ['date' => Carbon::parse($visit->visit_date)->toDateString()])
                ->with('success', __('health.visit_completed', ['visit' => $visit->visit_no]));
        }

        return back()->with('success', __('health.clinical_saved'));
    }

    /**
     * Reopen a completed encounter.
     *
     * Allowed, because a doctor who spots their own mistake five minutes later
     * must be able to correct it rather than open a second consultation for the
     * same patient. The reopen is stamped, so the correction is visible.
     */
    public function reopen($id)
    {
        $visit = $this->findVisit($id, ['patient']);
        $this->guardClinicalWrite($visit);

        if ($visit->status !== HealthVisit::STATUS_COMPLETED) {
            return back()->with('error', __('health.visit_not_completed'));
        }

        $visit->status = HealthVisit::STATUS_IN_CONSULTATION;
        $visit->closed_at = null;
        $visit->closed_by = null;
        $visit->save();

        if ($visit->health_appointment_id) {
            DB::table('health_appointments')
                ->where('id', $visit->health_appointment_id)
                ->update(['status' => 'in_consultation', 'completed_at' => null, 'updated_at' => now()]);
        }

        return back()->with('success', __('health.visit_reopened'));
    }

    /* ─────────────────────────── attachments ─────────────────────────── */

    public function uploadAttachment(Request $request, $id)
    {
        $visit = $this->findVisit($id, ['patient']);
        $this->guardClinicalWrite($visit);

        $request->validate([
            'file' => 'required|file|max:8192|mimes:pdf,jpg,jpeg,png,webp,heic,doc,docx',
            'caption' => 'nullable|string|max:300',
            'kind' => ['nullable', Rule::in(HealthVisitAttachment::KINDS)],
        ]);

        $file = $request->file('file');
        // Private disk, never public/: a lab report in the web root is a lab
        // report anyone with the URL can read.
        $path = HealthPlatformService::storeFile((int) $visit->company_id, $file, 'visits');

        if (!$path) {
            return back()->with('error', __('health.attachment_failed'));
        }

        HealthVisitAttachment::create([
            'company_id' => $visit->company_id,
            'health_visit_id' => $visit->id,
            'health_patient_id' => $visit->health_patient_id,
            'path' => $path,
            'original_name' => mb_substr((string) $file->getClientOriginalName(), 0, 255),
            'mime' => $file->getClientMimeType(),
            'size_bytes' => $file->getSize(),
            'kind' => $request->input('kind', 'other'),
            'caption' => $request->input('caption'),
            'uploaded_by' => $this->user()?->id,
        ]);

        return back()->with('success', __('health.attachment_saved'));
    }

    public function downloadAttachment($id)
    {
        $query = HealthVisitAttachment::query()->with(['visit.patient']);
        $attachment = $query->findOrFail($id);

        $visit = $attachment->visit;
        if (!$visit || (int) $visit->company_id !== (int) $this->company()?->id) {
            abort(404);
        }
        if (!HealthScopeService::canAccessBranch($this->user(), $visit->branch_id)) {
            abort(403, __('health.denied_no_permission'));
        }
        $this->guardClinicalRead($visit);

        if (!Storage::disk('local')->exists($attachment->path)) {
            abort(404);
        }

        return Storage::disk('local')->download($attachment->path, $attachment->original_name ?: 'attachment');
    }

    public function deleteAttachment($id)
    {
        $attachment = HealthVisitAttachment::query()->with(['visit.patient'])->findOrFail($id);
        $visit = $attachment->visit;

        if (!$visit || (int) $visit->company_id !== (int) $this->company()?->id) {
            abort(404);
        }
        $this->guardClinicalWrite($visit);

        try {
            Storage::disk('local')->delete($attachment->path);
        } catch (\Throwable $e) {
            // The row goes either way — a file we cannot delete must not leave
            // a pointer behind claiming it is still there.
        }
        $attachment->delete();

        return back()->with('success', __('health.attachment_deleted'));
    }

    /* ─────────────────────────── prescription ────────────────────────── */

    /**
     * Save the prescription for this encounter.
     *
     * Structured lines, not a paragraph: the pharmacy module has to read WHICH
     * medicine at WHAT dose for HOW long. Saved as one whole set each time —
     * a half-replaced prescription is a dosing error waiting to happen.
     */
    public function savePrescription(Request $request, $id)
    {
        $visit = $this->findVisit($id, ['patient', 'doctor']);
        $this->guardClinicalWrite($visit);

        $data = $request->validate([
            'general_instructions' => 'nullable|string|max:2000',
            'valid_until' => 'nullable|date|after_or_equal:today',
            'issue' => 'nullable|boolean',
            'items' => 'nullable|array|max:40',
            'items.*.medicine_name' => 'nullable|string|max:200',
            'items.*.generic_name' => 'nullable|string|max:200',
            'items.*.strength' => 'nullable|string|max:60',
            'items.*.form' => 'nullable|string|max:30',
            'items.*.dose' => 'nullable|string|max:60',
            'items.*.route' => 'nullable|string|max:30',
            'items.*.frequency' => 'nullable|string|max:60',
            'items.*.duration_days' => 'nullable|integer|min:0|max:3650',
            'items.*.quantity' => 'nullable|numeric|min:0|max:100000',
            'items.*.instructions' => 'nullable|string|max:300',
        ]);

        $rows = collect($data['items'] ?? [])
            ->filter(fn ($row) => trim((string) ($row['medicine_name'] ?? '')) !== '')
            ->values();

        if ($rows->isEmpty()) {
            return back()->with('error', __('health.presc_needs_a_line'));
        }

        DB::transaction(function () use ($visit, $data, $rows, &$prescription) {
            $prescription = HealthPrescription::query()
                ->where('health_visit_id', $visit->id)
                ->orderByDesc('id')
                ->first();

            if (!$prescription) {
                $prescription = new HealthPrescription([
                    'company_id' => $visit->company_id,
                    'branch_id' => $visit->branch_id,
                    'health_visit_id' => $visit->id,
                    'health_patient_id' => $visit->health_patient_id,
                    'health_doctor_id' => $visit->health_doctor_id,
                    'prescription_no' => HealthNumberService::prescriptionNumber((int) $visit->company_id),
                    'created_by' => $this->user()?->id,
                ]);
            }

            $prescription->general_instructions = $data['general_instructions'] ?? null;
            $prescription->valid_until = $data['valid_until'] ?? null;

            if (!empty($data['issue'])) {
                $prescription->status = HealthPrescription::STATUS_ISSUED;
                $prescription->issued_at = $prescription->issued_at ?? now();
            } elseif (!$prescription->exists) {
                $prescription->status = HealthPrescription::STATUS_DRAFT;
            }

            $prescription->save();

            HealthPrescriptionItem::query()
                ->where('health_prescription_id', $prescription->id)
                ->delete();

            $line = 0;
            foreach ($rows as $row) {
                $line++;
                HealthPrescriptionItem::create([
                    'company_id' => $visit->company_id,
                    'health_prescription_id' => $prescription->id,
                    'line_no' => $line,
                    'medicine_name' => $row['medicine_name'],
                    'generic_name' => $row['generic_name'] ?? null,
                    'strength' => $row['strength'] ?? null,
                    'form' => $row['form'] ?? null,
                    'dose' => $row['dose'] ?? null,
                    'route' => $row['route'] ?? null,
                    'frequency' => $row['frequency'] ?? null,
                    'duration_days' => $row['duration_days'] ?? null,
                    'quantity' => $row['quantity'] ?? null,
                    'instructions' => $row['instructions'] ?? null,
                ]);
            }
        });

        return back()->with('success', $request->boolean('issue')
            ? __('health.presc_issued', ['no' => $prescription->prescription_no])
            : __('health.presc_saved'));
    }

    /** The patient's copy — a plain print page, not a download. */
    public function printPrescription($id)
    {
        $prescription = HealthPrescription::query()
            ->with(['items', 'patient', 'doctor', 'visit', 'branch:id,name,address,phone'])
            ->findOrFail($id);

        if (!HealthScopeService::canAccessBranch($this->user(), $prescription->branch_id)) {
            abort(403, __('health.denied_no_permission'));
        }
        if (!HealthRecordAccessService::canOpenClinical($this->user(), $prescription->patient, $this->company())) {
            abort(403, __('health.clinical_confidential_blocked'));
        }

        return view('health.clinical.prescription-print', [
            'prescription' => $prescription,
            'company' => $this->company(),
        ]);
    }

    /* ───────────────────────────── helpers ───────────────────────────── */

    private function resolveDate($value): string
    {
        try {
            return $value ? Carbon::parse($value)->toDateString() : now()->toDateString();
        } catch (\Throwable $e) {
            return now()->toDateString();
        }
    }

    private function findVisit($id, array $with = []): HealthVisit
    {
        $query = HealthVisit::query()->with($with);

        return $this->scope($query)->findOrFail($id);
    }

    private function guardClinicalRead(HealthVisit $visit): void
    {
        if (!HealthRecordAccessService::canOpenClinical($this->user(), $visit->patient, $this->company())) {
            abort(403, __('health.clinical_confidential_blocked'));
        }
    }

    private function guardClinicalWrite(HealthVisit $visit): void
    {
        if (!HealthRecordAccessService::canWriteClinical($this->user(), $visit->patient, $this->company())) {
            abort(403, __('health.clinical_confidential_blocked'));
        }
    }
}
