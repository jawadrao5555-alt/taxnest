<?php

namespace App\Http\Controllers\Health;

use App\Models\HealthBed;
use App\Models\HealthRoom;
use App\Models\HealthWard;
use App\Services\HealthIpdService;
use App\Services\HealthScopeService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The inpatient facility: wards, the rooms inside them, and the beds inside
 * those.
 *
 * Gated on `wards.manage` for every write, deliberately NOT on `ipd.manage`.
 * The setup screen carries the DAY RATE, and a ward sister who admits patients
 * all day must not be able to change what a bed costs — the same separation
 * that keeps a receptionist out of the consultation fee schedule.
 *
 * Nothing here is ever deleted. A bed is DEACTIVATED, because stays, timelines
 * and room-day charges are all filed against it, and a discharge bill that
 * cannot name the bed it charged for is worthless.
 */
class HealthWardController extends HealthPanelController
{
    public function index()
    {
        $user = $this->user();

        $wardQuery = HealthWard::query()->with(['branch:id,name', 'department:id,name'])->orderBy('name');
        $wards = $this->scope($wardQuery)->get();

        $wardIds = $wards->pluck('id')->all() ?: [0];

        $rooms = HealthRoom::query()
            ->whereIn('health_ward_id', $wardIds)
            ->orderBy('name')
            ->get();

        $beds = HealthBed::query()
            ->whereIn('health_ward_id', $wardIds)
            ->with(['admission:id,admission_no,health_patient_id', 'admission.patient:id,name,mrn'])
            ->orderBy('code')
            ->get();

        return view('health.ipd.facility', [
            'wards' => $wards,
            'rooms' => $rooms,
            'beds' => $beds,
            'branches' => $this->branches(),
            'departments' => HealthScopeService::selectableDepartments($user),
            'mayManage' => $this->can('wards.manage'),
        ]);
    }

    /* ───────────────── wards ───────────────── */

    public function storeWard(Request $request)
    {
        $data = $this->validateWard($request);

        if (!HealthScopeService::canAccessBranch($this->user(), $data['branch_id'] ?? null)) {
            return back()->withInput()->with('error', __('health.dept_branch_not_yours'));
        }

        $data['company_id'] = $this->company()->id;
        $data['is_active'] = true;
        HealthWard::create($data);

        return redirect()->route('health.ipd.facility')->with('success', __('health.ward_created'));
    }

    public function updateWard(Request $request, $id)
    {
        $ward = $this->findWard($id);
        $data = $this->validateWard($request, $ward->id);

        if (!HealthScopeService::canAccessBranch($this->user(), $data['branch_id'] ?? null)) {
            return back()->withInput()->with('error', __('health.dept_branch_not_yours'));
        }

        $ward->fill($data)->save();

        return redirect()->route('health.ipd.facility')->with('success', __('health.ward_updated'));
    }

    public function toggleWard($id)
    {
        $ward = $this->findWard($id);

        // A ward cannot be retired while patients are still in it: the board
        // would stop showing beds that are demonstrably occupied.
        if ($ward->is_active) {
            $occupied = HealthBed::query()
                ->where('health_ward_id', $ward->id)
                ->where('status', HealthBed::STATUS_OCCUPIED)
                ->count();

            if ($occupied > 0) {
                return back()->with('error', __('health.ward_has_patients', ['count' => $occupied]));
            }
        }

        $ward->is_active = !$ward->is_active;
        $ward->save();

        return back()->with('success', $ward->is_active
            ? __('health.ward_reactivated')
            : __('health.ward_deactivated'));
    }

    /* ───────────────── rooms ───────────────── */

    public function storeRoom(Request $request)
    {
        $data = $this->validateRoom($request);
        $ward = $this->findWard($data['health_ward_id']);

        $data['company_id'] = $this->company()->id;
        $data['branch_id'] = $ward->branch_id;
        $data['is_active'] = true;

        HealthRoom::create($data);

        return redirect()->route('health.ipd.facility')->with('success', __('health.room_created'));
    }

    public function updateRoom(Request $request, $id)
    {
        $room = $this->findRoom($id);
        $data = $this->validateRoom($request, $room->id);
        $ward = $this->findWard($data['health_ward_id']);

        $data['branch_id'] = $ward->branch_id;
        $room->fill($data)->save();

        return redirect()->route('health.ipd.facility')->with('success', __('health.room_updated'));
    }

    public function toggleRoom($id)
    {
        $room = $this->findRoom($id);
        $room->is_active = !$room->is_active;
        $room->save();

        return back()->with('success', __('health.saved'));
    }

    /* ───────────────── beds ───────────────── */

    public function storeBed(Request $request)
    {
        $data = $this->validateBed($request);
        $ward = $this->findWard($data['health_ward_id']);

        $data['company_id'] = $this->company()->id;
        $data['branch_id'] = $ward->branch_id;
        $data['status'] = HealthBed::STATUS_AVAILABLE;
        $data['status_changed_at'] = now();
        $data['is_active'] = true;

        HealthBed::create($data);

        return redirect()->route('health.ipd.facility')->with('success', __('health.bed_created'));
    }

    public function updateBed(Request $request, $id)
    {
        $bed = $this->findBed($id);
        $data = $this->validateBed($request, $bed->id);
        $ward = $this->findWard($data['health_ward_id']);

        // Moving an OCCUPIED bed to another ward would silently move the
        // patient with it and leave the stay pointing at the wrong ward.
        if ($bed->status === HealthBed::STATUS_OCCUPIED && (int) $bed->health_ward_id !== (int) $ward->id) {
            return back()->withInput()->with('error', __('health.bed_occupied_locked'));
        }

        $data['branch_id'] = $ward->branch_id;
        $bed->fill($data)->save();

        return redirect()->route('health.ipd.facility')->with('success', __('health.bed_updated'));
    }

    public function toggleBed($id)
    {
        $bed = $this->findBed($id);

        if ($bed->is_active && $bed->status === HealthBed::STATUS_OCCUPIED) {
            return back()->with('error', __('health.bed_occupied_locked'));
        }

        $bed->is_active = !$bed->is_active;
        $bed->save();

        return back()->with('success', $bed->is_active
            ? __('health.bed_reactivated')
            : __('health.bed_deactivated'));
    }

    /**
     * Housekeeping / maintenance from the bed board.
     *
     * Reachable with `ipd.manage` rather than `wards.manage`: marking a bed
     * dirty or out of order is ward work, not pricing work.
     */
    public function setBedStatus(Request $request, $id)
    {
        $this->require('ipd.manage');

        $bed = $this->findBed($id);

        $request->validate([
            'status' => ['required', Rule::in(HealthBed::MANUAL_STATUSES)],
            'status_note' => ['nullable', 'string', 'max:300'],
        ]);

        try {
            HealthIpdService::setBedStatus($bed, $request->input('status'), $request->input('status_note'), $this->user());
        } catch (\RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', __('health.bed_status_saved'));
    }

    /* ───────────────── internals ───────────────── */

    /**
     * A room or bed is reached BY ID from a form post, so the branch boundary
     * has to be re-checked here and not just on the page that listed it. Model
     * scoping only proves the row belongs to the company; a manager confined to
     * one site could otherwise reprice — or move — another site's beds.
     */
    private function findRoom($id): HealthRoom
    {
        $room = HealthRoom::query()->findOrFail($id);

        if (!HealthScopeService::canAccessBranch($this->user(), $room->branch_id)) {
            abort(403, __('health.denied_no_permission'));
        }

        return $room;
    }

    private function findBed($id): HealthBed
    {
        $bed = HealthBed::query()->findOrFail($id);

        if (!HealthScopeService::canAccessBranch($this->user(), $bed->branch_id)) {
            abort(403, __('health.denied_no_permission'));
        }

        return $bed;
    }

    private function findWard($id): HealthWard
    {
        $ward = HealthWard::query()->findOrFail($id);

        if (!HealthScopeService::canAccessBranch($this->user(), $ward->branch_id)) {
            abort(403, __('health.denied_no_permission'));
        }

        return $ward;
    }

    private function validateWard(Request $request, $ignoreId = null): array
    {
        $companyId = $this->company()->id;

        return $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'code' => [
                'nullable', 'string', 'max:32',
                Rule::unique('health_wards', 'code')
                    ->where(fn ($q) => $q->where('company_id', $companyId))
                    ->ignore($ignoreId),
            ],
            'type' => ['required', Rule::in(HealthWard::TYPES)],
            'gender_policy' => ['required', Rule::in(HealthWard::GENDER_POLICIES)],
            'floor' => ['nullable', 'string', 'max:40'],
            'branch_id' => ['nullable', 'integer', 'exists:branches,id'],
            'health_department_id' => ['nullable', 'integer', 'exists:health_departments,id'],
            'daily_rate' => ['required', 'numeric', 'min:0', 'max:99999999'],
            'nursing_daily_rate' => ['required', 'numeric', 'min:0', 'max:99999999'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);
    }

    private function validateRoom(Request $request, $ignoreId = null): array
    {
        $companyId = $this->company()->id;

        return $request->validate([
            'health_ward_id' => ['required', 'integer', 'exists:health_wards,id'],
            'name' => [
                'required', 'string', 'max:60',
                Rule::unique('health_rooms', 'name')
                    ->where(fn ($q) => $q->where('company_id', $companyId)
                        ->where('health_ward_id', $request->input('health_ward_id')))
                    ->ignore($ignoreId),
            ],
            'room_type' => ['required', Rule::in(HealthRoom::TYPES)],
            // NULL means "inherit the ward", which is why these are nullable
            // rather than defaulted to zero. A blank rate must never make a
            // stay free.
            'daily_rate' => ['nullable', 'numeric', 'min:0', 'max:99999999'],
            'nursing_daily_rate' => ['nullable', 'numeric', 'min:0', 'max:99999999'],
            'notes' => ['nullable', 'string', 'max:300'],
        ]);
    }

    private function validateBed(Request $request, $ignoreId = null): array
    {
        $companyId = $this->company()->id;

        return $request->validate([
            'health_ward_id' => ['required', 'integer', 'exists:health_wards,id'],
            'health_room_id' => ['nullable', 'integer', 'exists:health_rooms,id'],
            'code' => [
                'required', 'string', 'max:40',
                Rule::unique('health_beds', 'code')
                    ->where(fn ($q) => $q->where('company_id', $companyId))
                    ->ignore($ignoreId),
            ],
            'label' => ['nullable', 'string', 'max:60'],
            'daily_rate' => ['nullable', 'numeric', 'min:0', 'max:99999999'],
            'nursing_daily_rate' => ['nullable', 'numeric', 'min:0', 'max:99999999'],
        ]);
    }
}
