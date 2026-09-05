<?php

namespace App\Http\Controllers\Health;

use App\Models\HealthAdmission;
use App\Models\HealthAdmissionCharge;
use App\Models\HealthAdmissionPayment;
use App\Models\HealthBed;
use App\Models\HealthDoctor;
use App\Models\HealthOperation;
use App\Models\HealthProcedure;
use App\Models\HealthWard;
use App\Services\HealthAccessService;
use App\Services\HealthRecordAccessService;
use App\Services\HealthScopeService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Inpatient and theatre reporting: occupancy, movement, the procedure register,
 * doctor activity and what the ward actually billed.
 *
 * Every figure is aggregated under the SAME branch and department boundary the
 * operational screens use, and money comes from what was POSTED on each stay
 * rather than being re-derived from today's bed rates — a rate changed last week
 * must not silently rewrite last month's revenue.
 *
 * Occupancy is deliberately measured two ways, because they answer different
 * questions and a hospital that only has one of them argues about it: LIVE
 * occupancy is the bed board right now, and BED-DAYS is what the ward actually
 * sold over the period.
 */
class HealthIpdReportController extends HealthPanelController
{
    public function index(Request $request)
    {
        $user = $this->user();
        [$from, $to] = $this->resolveRange($request);

        // A linked surgeon who does not run the place reads their OWN theatre
        // numbers only — the same rule the OPD report follows.
        $ownDoctorIds = HealthAccessService::isAdministrative($user) ? [] : $this->ownDoctorIds();
        $lockedToOwn = $ownDoctorIds !== [];

        return view('health.ipd.reports', [
            'from' => $from,
            'to' => $to,
            'range' => $request->query('range', 'today'),
            'lockedToOwn' => $lockedToOwn,
            'live' => $this->liveOccupancy(),
            'movement' => $this->movement($from, $to),
            'stays' => $this->stays($from, $to),
            'wardUsage' => $this->wardUsage($from, $to),
            'charges' => $this->chargeBreakdown($from, $to),
            'collection' => $this->collection($from, $to),
            'procedures' => $this->procedureRegister($from, $to, $lockedToOwn ? $ownDoctorIds : null),
            'surgeons' => $this->surgeonActivity($from, $to, $lockedToOwn ? $ownDoctorIds : null),
            'cancellations' => $this->cancellations($from, $to, $lockedToOwn ? $ownDoctorIds : null),
            'doctorActivity' => $this->doctorActivity($from, $to, $lockedToOwn ? $ownDoctorIds : null),
        ]);
    }

    /* ───────────────── occupancy ───────────────── */

    /** The bed board as it stands this second, ward by ward. */
    private function liveOccupancy(): array
    {
        $query = HealthBed::query()->where('is_active', true);
        HealthScopeService::applyBranchScope($query, $this->user());

        $rows = (clone $query)
            ->selectRaw('health_ward_id, status, COUNT(*) as beds')
            ->groupBy('health_ward_id', 'status')
            ->get();

        $wardNames = HealthWard::query()
            ->whereIn('id', $rows->pluck('health_ward_id')->filter()->unique()->all() ?: [0])
            ->pluck('name', 'id');

        $byWard = [];
        $totals = array_fill_keys(HealthBed::STATUSES, 0);
        $totals['total'] = 0;

        foreach ($rows as $row) {
            $wardId = (int) $row->health_ward_id;
            if (!isset($byWard[$wardId])) {
                $byWard[$wardId] = array_fill_keys(HealthBed::STATUSES, 0)
                    + ['name' => $wardNames[$wardId] ?? '—', 'total' => 0, 'rate' => 0.0];
            }

            $count = (int) $row->beds;
            $byWard[$wardId][$row->status] = $count;
            $byWard[$wardId]['total'] += $count;
            $totals[$row->status] = ($totals[$row->status] ?? 0) + $count;
            $totals['total'] += $count;
        }

        foreach ($byWard as $wardId => $ward) {
            $byWard[$wardId]['rate'] = $ward['total'] > 0
                ? round(($ward[HealthBed::STATUS_OCCUPIED] / $ward['total']) * 100, 1)
                : 0.0;
        }

        $totals['rate'] = $totals['total'] > 0
            ? round(($totals[HealthBed::STATUS_OCCUPIED] / $totals['total']) * 100, 1)
            : 0.0;

        uasort($byWard, fn ($a, $b) => strcmp((string) $a['name'], (string) $b['name']));

        return ['wards' => $byWard, 'totals' => $totals];
    }

    /** Admissions in, discharges out, and what was turned away. */
    private function movement(Carbon $from, Carbon $to): array
    {
        $admitted = (clone $this->admissionBase())
            ->whereBetween('admitted_at', [$from, $to])
            ->count();

        $discharged = (clone $this->admissionBase())
            ->whereBetween('discharged_at', [$from, $to])
            ->count();

        $requested = (clone $this->admissionBase())
            ->whereBetween('created_at', [$from, $to])
            ->count();

        $cancelled = (clone $this->admissionBase())
            ->where('status', HealthAdmission::STATUS_CANCELLED)
            ->whereBetween('updated_at', [$from, $to])
            ->count();

        $byType = (clone $this->admissionBase())
            ->whereBetween('admitted_at', [$from, $to])
            ->selectRaw('admission_type, COUNT(*) as total')
            ->groupBy('admission_type')
            ->pluck('total', 'admission_type')
            ->all();

        $byDischargeType = (clone $this->admissionBase())
            ->whereBetween('discharged_at', [$from, $to])
            ->selectRaw('discharge_type, COUNT(*) as total')
            ->groupBy('discharge_type')
            ->pluck('total', 'discharge_type')
            ->all();

        return [
            'requested' => $requested,
            'admitted' => $admitted,
            'discharged' => $discharged,
            'cancelled' => $cancelled,
            'still_in' => (clone $this->admissionBase())->whereIn('status', HealthAdmission::OPEN_STATUSES)->count(),
            'by_type' => $byType,
            'by_discharge_type' => $byDischargeType,
        ];
    }

    /**
     * Length of stay, over the stays that actually FINISHED in the period.
     *
     * Averaging in patients who are still on the ward would drag the figure
     * down every single day and make a long stay look like a short one.
     */
    private function stays(Carbon $from, Carbon $to)
    {
        $rows = (clone $this->admissionBase())
            ->whereBetween('discharged_at', [$from, $to])
            ->whereNotNull('admitted_at')
            ->with(['patient:id,name,mrn', 'ward:id,name', 'doctor:id,name'])
            ->orderByDesc('discharged_at')
            ->limit(200)
            ->get();

        $days = $rows->map(fn (HealthAdmission $a) => $a->lengthOfStayDays())->filter(fn ($d) => $d > 0);

        return [
            'rows' => $rows,
            'count' => $rows->count(),
            'avg_days' => $days->count() > 0 ? round($days->avg(), 1) : 0.0,
            'total_days' => (int) $days->sum(),
            'longest' => $days->count() > 0 ? (int) $days->max() : 0,
        ];
    }

    /** Bed-days actually sold, from the room charges themselves. */
    private function wardUsage(Carbon $from, Carbon $to)
    {
        $rows = (clone $this->chargeBase())
            ->whereBetween('charge_date', [$from->toDateString(), $to->toDateString()])
            ->where('category', HealthAdmissionCharge::CAT_ROOM)
            ->selectRaw('health_admission_id, SUM(quantity) as days, SUM(net_amount) as net')
            ->groupBy('health_admission_id')
            ->get();

        return [
            'bed_days' => round((float) $rows->sum('days'), 1),
            'room_revenue' => round((float) $rows->sum('net'), 2),
            'stays' => $rows->count(),
        ];
    }

    /** What the ward billed, category by category. */
    private function chargeBreakdown(Carbon $from, Carbon $to): array
    {
        $rows = (clone $this->chargeBase())
            ->whereBetween('charge_date', [$from->toDateString(), $to->toDateString()])
            ->selectRaw('category, status, SUM(gross_amount) as gross, SUM(concession_amount) as concession, SUM(net_amount) as net, COUNT(*) as lines')
            ->groupBy('category', 'status')
            ->get();

        $posted = [];
        $reversedTotal = 0.0;
        $reversedLines = 0;
        $totals = ['gross' => 0.0, 'concession' => 0.0, 'net' => 0.0, 'lines' => 0];

        foreach ($rows as $row) {
            if ($row->status === HealthAdmissionCharge::STATUS_REVERSED) {
                $reversedTotal += (float) $row->net;
                $reversedLines += (int) $row->lines;
                continue;
            }

            $posted[$row->category] = [
                'gross' => round((float) $row->gross, 2),
                'concession' => round((float) $row->concession, 2),
                'net' => round((float) $row->net, 2),
                'lines' => (int) $row->lines,
            ];

            $totals['gross'] += (float) $row->gross;
            $totals['concession'] += (float) $row->concession;
            $totals['net'] += (float) $row->net;
            $totals['lines'] += (int) $row->lines;
        }

        arsort($posted);

        return [
            'by_category' => $posted,
            'totals' => array_map(fn ($v) => is_float($v) ? round($v, 2) : $v, $totals),
            'reversed' => round($reversedTotal, 2),
            'reversed_lines' => $reversedLines,
        ];
    }

    /**
     * Money actually taken, against money billed.
     *
     * These two never tie exactly and are not meant to: an advance is taken
     * before the charge exists. Showing both is the point — the gap IS the
     * reconciliation question.
     */
    private function collection(Carbon $from, Carbon $to): array
    {
        $rows = (clone $this->paymentBase())
            ->whereBetween('created_at', [$from, $to])
            ->selectRaw('kind, method, SUM(amount) as total, COUNT(*) as entries')
            ->groupBy('kind', 'method')
            ->get();

        $byMethod = [];
        $advances = 0.0;
        $refunds = 0.0;

        foreach ($rows as $row) {
            $signed = $row->kind === HealthAdmissionPayment::KIND_REFUND
                ? -(float) $row->total
                : (float) $row->total;

            $byMethod[$row->method] = round(($byMethod[$row->method] ?? 0) + $signed, 2);

            if ($row->kind === HealthAdmissionPayment::KIND_REFUND) {
                $refunds += (float) $row->total;
            } else {
                $advances += (float) $row->total;
            }
        }

        arsort($byMethod);

        return [
            'by_method' => $byMethod,
            'advances' => round($advances, 2),
            'refunds' => round($refunds, 2),
            'net' => round($advances - $refunds, 2),
        ];
    }

    /* ───────────────── theatre ───────────────── */

    /** The procedure register: what was done, how often, for how much. */
    private function procedureRegister(Carbon $from, Carbon $to, ?array $doctorIds)
    {
        $query = (clone $this->operationBase($doctorIds))
            ->where('status', HealthOperation::STATUS_COMPLETED)
            ->whereBetween('actual_end', [$from, $to])
            ->selectRaw('health_procedure_id, COUNT(*) as total, SUM(price) as gross, SUM(concession_amount) as concession, SUM(price - concession_amount) as net')
            ->groupBy('health_procedure_id')
            ->orderByDesc('total');

        $rows = $query->get();

        $names = HealthProcedure::query()
            ->whereIn('id', $rows->pluck('health_procedure_id')->filter()->unique()->all() ?: [0])
            ->pluck('name', 'id');

        return $rows->map(fn ($row) => [
            'name' => $row->health_procedure_id ? ($names[$row->health_procedure_id] ?? '—') : null,
            'total' => (int) $row->total,
            'gross' => round((float) $row->gross, 2),
            'concession' => round((float) $row->concession, 2),
            'net' => round((float) $row->net, 2),
        ]);
    }

    /** Who operated, how much, and how it went. */
    private function surgeonActivity(Carbon $from, Carbon $to, ?array $doctorIds)
    {
        $rows = (clone $this->operationBase($doctorIds))
            ->whereBetween('scheduled_start', [$from, $to])
            ->selectRaw('primary_surgeon_id')
            ->selectRaw('COUNT(*) as booked')
            ->selectRaw("SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed")
            ->selectRaw("SUM(CASE WHEN status = 'cancelled' THEN 1 ELSE 0 END) as cancelled")
            ->selectRaw("SUM(CASE WHEN status = 'postponed' THEN 1 ELSE 0 END) as postponed")
            ->selectRaw("SUM(CASE WHEN outcome = 'complications' THEN 1 ELSE 0 END) as complications")
            ->selectRaw("SUM(CASE WHEN status = 'completed' THEN price - concession_amount ELSE 0 END) as net")
            ->groupBy('primary_surgeon_id')
            ->orderByDesc('booked')
            ->get();

        $names = HealthDoctor::query()
            ->whereIn('id', $rows->pluck('primary_surgeon_id')->filter()->unique()->all() ?: [0])
            ->pluck('name', 'id');

        return $rows->map(fn ($row) => [
            'name' => $row->primary_surgeon_id ? ($names[$row->primary_surgeon_id] ?? '—') : null,
            'booked' => (int) $row->booked,
            'completed' => (int) $row->completed,
            'cancelled' => (int) $row->cancelled,
            'postponed' => (int) $row->postponed,
            'complications' => (int) $row->complications,
            'net' => round((float) $row->net, 2),
        ]);
    }

    /** Called-off lists, with the reason attached — that is the useful part. */
    private function cancellations(Carbon $from, Carbon $to, ?array $doctorIds)
    {
        return (clone $this->operationBase($doctorIds))
            ->whereIn('status', [HealthOperation::STATUS_CANCELLED, HealthOperation::STATUS_POSTPONED])
            ->whereBetween('cancelled_at', [$from, $to])
            ->with(['patient:id,name,mrn', 'procedure:id,name', 'surgeon:id,name'])
            ->orderByDesc('cancelled_at')
            ->limit(100)
            ->get();
    }

    /** Consultants by inpatient load, not theatre load. */
    private function doctorActivity(Carbon $from, Carbon $to, ?array $doctorIds)
    {
        $query = (clone $this->admissionBase())
            ->whereBetween('admitted_at', [$from, $to])
            ->selectRaw('health_doctor_id, COUNT(*) as admissions')
            ->selectRaw("SUM(CASE WHEN status = 'discharged' THEN 1 ELSE 0 END) as discharged")
            ->groupBy('health_doctor_id')
            ->orderByDesc('admissions');

        if ($doctorIds !== null) {
            $query->whereIn('health_doctor_id', $doctorIds ?: [0]);
        }

        $rows = $query->get();

        $names = HealthDoctor::query()
            ->whereIn('id', $rows->pluck('health_doctor_id')->filter()->unique()->all() ?: [0])
            ->pluck('name', 'id');

        return $rows->map(fn ($row) => [
            'name' => $row->health_doctor_id ? ($names[$row->health_doctor_id] ?? '—') : null,
            'admissions' => (int) $row->admissions,
            'discharged' => (int) $row->discharged,
        ]);
    }

    /* ───────────────── scoped bases ───────────────── */

    private function admissionBase()
    {
        $query = HealthAdmission::query();
        $this->scope($query);
        HealthRecordAccessService::hideConfidential($query, $this->user(), 'health_patient_id');

        return $query;
    }

    private function operationBase(?array $doctorIds)
    {
        $query = HealthOperation::query();
        $this->scope($query);
        HealthRecordAccessService::hideConfidential($query, $this->user(), 'health_patient_id');

        if ($doctorIds !== null) {
            $query->whereIn('primary_surgeon_id', $doctorIds ?: [0]);
        }

        return $query;
    }

    /**
     * Charges, narrowed to the stays this person may see.
     *
     * The charge table has no branch column of its own, so the boundary is
     * applied by joining to the admissions the viewer can already reach — the
     * report must never total money from a ward its reader cannot open.
     */
    private function chargeBase()
    {
        return HealthAdmissionCharge::query()
            ->whereIn('health_admission_id', (clone $this->admissionBase())->select('id'));
    }

    private function paymentBase()
    {
        return HealthAdmissionPayment::query()
            ->whereIn('health_admission_id', (clone $this->admissionBase())->select('id'));
    }

    /**
     * The reporting window. Opens on TODAY — an "all time" default is a slow
     * page nobody asked for.
     */
    private function resolveRange(Request $request): array
    {
        $range = $request->query('range', 'today');

        return match ($range) {
            'yesterday' => [now()->subDay()->startOfDay(), now()->subDay()->endOfDay()],
            'week' => [now()->startOfWeek(), now()->endOfDay()],
            'month' => [now()->startOfMonth(), now()->endOfDay()],
            'last_month' => [now()->subMonthNoOverflow()->startOfMonth(), now()->subMonthNoOverflow()->endOfMonth()],
            'custom' => [
                $request->filled('from') ? Carbon::parse($request->query('from'))->startOfDay() : now()->startOfMonth(),
                $request->filled('to') ? Carbon::parse($request->query('to'))->endOfDay() : now()->endOfDay(),
            ],
            default => [now()->startOfDay(), now()->endOfDay()],
        };
    }
}
