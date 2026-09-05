<?php

namespace App\Http\Controllers\Health;

use App\Models\HealthAppointment;
use App\Models\HealthDoctor;
use App\Models\HealthVisit;
use App\Services\HealthAccessService;
use App\Services\HealthModuleService;
use App\Services\HealthRecordAccessService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * OPD reporting: who worked, how much was seen, and what was collected.
 *
 * Every figure on this page is aggregated from the encounters themselves under
 * the SAME branch and department boundary the operational screens use. A report
 * that quietly shows more than the screens it summarises is how a manager reads
 * another branch's numbers, and how a doctor reads a colleague's takings.
 *
 * Money columns come from what was recorded on each visit (gross, concession,
 * net) rather than being re-derived from the doctor's current fee schedule — a
 * fee that was changed last week must not silently rewrite last month's report.
 */
class HealthOpdReportController extends HealthPanelController
{
    public function index(Request $request)
    {
        $company = $this->company();

        // Reports open on TODAY. An "all time" report as the default is a slow
        // page nobody asked for.
        [$from, $to] = $this->resolveRange($request);

        $opdOn = HealthModuleService::isEnabled($company, 'opd');

        // A linked practitioner who is not running the place reads their OWN
        // numbers only. The role says "doctor"; the link says "this doctor".
        $ownDoctorIds = HealthAccessService::isAdministrative($this->user())
            ? []
            : $this->ownDoctorIds();
        $lockedToOwn = $ownDoctorIds !== [];

        $visitQuery = HealthVisit::query()
            ->whereBetween('visit_date', [$from, $to])
            ->where('status', '!=', HealthVisit::STATUS_CANCELLED);
        $this->scope($visitQuery);
        HealthRecordAccessService::hideConfidential($visitQuery, $this->user());

        if ($lockedToOwn) {
            $visitQuery->whereIn('health_doctor_id', $ownDoctorIds);
        }

        if ($request->filled('doctor_id')) {
            $visitQuery->where('health_doctor_id', (int) $request->query('doctor_id'));
        }

        // Doctor workload.
        $workload = (clone $visitQuery)
            ->select([
                'health_doctor_id',
                DB::raw('COUNT(*) as visits'),
                DB::raw("SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed"),
                DB::raw("SUM(CASE WHEN visit_type = 'new' THEN 1 ELSE 0 END) as new_cases"),
                DB::raw("SUM(CASE WHEN visit_type = 'follow_up' THEN 1 ELSE 0 END) as follow_ups"),
                DB::raw('SUM(fee_amount) as gross'),
                DB::raw('SUM(concession_amount) as concession'),
                DB::raw('SUM(net_fee) as net'),
                DB::raw("SUM(CASE WHEN fee_status = 'paid' THEN net_fee ELSE 0 END) as collected"),
                DB::raw("SUM(CASE WHEN fee_status = 'pending' THEN net_fee ELSE 0 END) as outstanding"),
            ])
            ->groupBy('health_doctor_id')
            ->orderByDesc('visits')
            ->get();

        $doctorNames = HealthDoctor::query()
            ->whereIn('id', $workload->pluck('health_doctor_id')->all() ?: [0])
            ->pluck('name', 'id');

        // Day-by-day consultation summary.
        $daily = (clone $visitQuery)
            ->select([
                'visit_date',
                DB::raw('COUNT(*) as visits'),
                DB::raw("SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed"),
                DB::raw('SUM(net_fee) as net'),
                DB::raw("SUM(CASE WHEN fee_status = 'paid' THEN net_fee ELSE 0 END) as collected"),
            ])
            ->groupBy('visit_date')
            ->orderBy('visit_date')
            ->get();

        // Appointment outcomes, including the no-shows — which by definition
        // never became a visit, so they cannot come from the visit table.
        $apptQuery = HealthAppointment::query()->whereBetween('appointment_date', [$from, $to]);
        $this->scope($apptQuery);
        if ($lockedToOwn) {
            $apptQuery->whereIn('health_doctor_id', $ownDoctorIds);
        }
        if ($request->filled('doctor_id')) {
            $apptQuery->where('health_doctor_id', (int) $request->query('doctor_id'));
        }

        $outcomes = (clone $apptQuery)
            ->select(['status', DB::raw('COUNT(*) as total')])
            ->groupBy('status')
            ->pluck('total', 'status');

        $kinds = (clone $apptQuery)
            ->select(['kind', DB::raw('COUNT(*) as total')])
            ->groupBy('kind')
            ->pluck('total', 'kind');

        $noShows = (clone $apptQuery)
            ->where('status', HealthAppointment::STATUS_NO_SHOW)
            ->with(['patient:id,mrn,name,phone', 'doctor:id,name'])
            ->orderByDesc('appointment_date')
            ->limit(100)
            ->get();

        $totalAppointments = max(1, (int) $outcomes->sum());
        $noShowRate = round(((int) ($outcomes[HealthAppointment::STATUS_NO_SHOW] ?? 0)) / $totalAppointments * 100, 1);

        return view('health.reports.opd', [
            'opdOn' => $opdOn,
            'from' => $from,
            'to' => $to,
            'workload' => $workload,
            'doctorNames' => $doctorNames,
            'daily' => $daily,
            'outcomes' => $outcomes,
            'kinds' => $kinds,
            'noShows' => $noShows,
            'noShowRate' => $noShowRate,
            'doctors' => $lockedToOwn
                ? $this->selectableDoctors()->whereIn('id', $ownDoctorIds)->values()
                : $this->selectableDoctors(),
            'lockedToOwnDoctor' => $lockedToOwn,
            'doctorFilter' => $request->filled('doctor_id') ? (int) $request->query('doctor_id') : null,
            'totals' => [
                'visits' => (int) $workload->sum('visits'),
                'completed' => (int) $workload->sum('completed'),
                'gross' => (float) $workload->sum('gross'),
                'concession' => (float) $workload->sum('concession'),
                'net' => (float) $workload->sum('net'),
                'collected' => (float) $workload->sum('collected'),
                'outstanding' => (float) $workload->sum('outstanding'),
            ],
        ]);
    }

    /** @return array{0:string,1:string} */
    private function resolveRange(Request $request): array
    {
        $today = now()->toDateString();

        try {
            $from = $request->filled('from') ? Carbon::parse($request->query('from'))->toDateString() : $today;
            $to = $request->filled('to') ? Carbon::parse($request->query('to'))->toDateString() : $today;
        } catch (\Throwable $e) {
            return [$today, $today];
        }

        return $from <= $to ? [$from, $to] : [$to, $from];
    }
}
