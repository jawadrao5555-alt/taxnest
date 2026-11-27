<?php

namespace App\Http\Controllers;

use App\Services\SchoolErpWorkspace;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class SchoolErpAttendanceController extends Controller
{
    public function index(Request $request, SchoolErpWorkspace $workspace)
    {
        $company = $request->attributes->get('schoolErpCompany');
        $user = $request->attributes->get('schoolErpUser');
        $campuses = $workspace->campuses($user);
        $data = $request->validate([
            'campus_id' => ['nullable', 'integer', Rule::in($campuses->pluck('id')->all())],
            'attendance_on' => 'nullable|date_format:Y-m-d|before_or_equal:today',
        ]);
        $date = $data['attendance_on'] ?? now()->toDateString();
        $campusId = isset($data['campus_id']) ? (int) $data['campus_id'] : null;
        $writeCampuses = $workspace->campuses($user, true);
        $canWrite = $workspace->mayWrite($company) && ($user->isCompanyAdmin() || $user->role === 'employee');

        $attendance = $workspace->scopedRecords($user, 'school_erp_attendance', $campusId)
            ->join('school_erp_students as student', 'student.id', '=', 'school_erp_attendance.student_id')
            ->join('school_erp_terms as term', 'term.id', '=', 'school_erp_attendance.term_id')
            ->join('branches as campus', 'campus.id', '=', 'school_erp_attendance.campus_id')
            ->where('attendance_on', $date)->orderBy('student.student_reference')->orderBy('school_erp_attendance.id')
            ->paginate(25, ['student.student_reference', 'term.name as term_name', 'campus.name as campus_name', 'school_erp_attendance.status'], 'attendance_page')
            ->withQueryString();

        $eligible = $canWrite ? $workspace->scopedRecords($user, 'school_erp_students', $campusId)
            ->whereIn('school_erp_students.campus_id', $writeCampuses->pluck('id'))
            ->whereNotExists(function ($query) use ($date) {
                $query->selectRaw('1')->from('school_erp_campus_transfers as transfer')
                    ->whereColumn('transfer.student_id', 'school_erp_students.id')
                    ->where('transfer.created_at', '>=', \Illuminate\Support\Carbon::parse($date)->addDay()->toDateString());
            })
            ->join('school_erp_enrollments as enrollment', 'enrollment.student_id', '=', 'school_erp_students.id')
            ->join('school_erp_terms as term', 'term.id', '=', 'enrollment.term_id')
            ->leftJoin('school_erp_attendance as record', function ($join) use ($date) {
                $join->on('record.student_id', '=', 'school_erp_students.id')->where('record.attendance_on', '=', $date);
            })
            ->where('enrollment.admitted_on', '<=', $date)
            ->where('term.starts_on', '<=', $date)
            ->where('term.ends_on', '>=', $date)
            ->where(function ($query) {
                $query->whereNull('record.id')->orWhere(function ($match) {
                    $match->whereColumn('record.campus_id', 'school_erp_students.campus_id')
                        ->whereColumn('record.term_id', 'enrollment.term_id');
                });
            })
            ->orderBy('school_erp_students.display_name')->orderBy('enrollment.id')
            ->paginate(25, ['school_erp_students.id as student_id', 'school_erp_students.display_name', 'school_erp_students.student_reference',
                'term.id as term_id', 'term.name as term_name', 'record.status'], 'students_page')->withQueryString() : null;

        $statuses = SchoolErpWorkspace::ATTENDANCE_STATUSES;

        return view('school-erp.attendance', compact('campuses', 'campusId', 'date', 'attendance', 'eligible', 'statuses'));
    }

    public function store(Request $request, SchoolErpWorkspace $workspace)
    {
        $data = $request->validate([
            'student_id' => 'required|integer|min:1',
            'term_id' => 'required|integer|min:1',
            'attendance_on' => 'required|date_format:Y-m-d|before_or_equal:today',
            'status' => ['required', Rule::in(SchoolErpWorkspace::ATTENDANCE_STATUSES)],
        ]);
        $workspace->recordAttendance($request->attributes->get('schoolErpUser'), $data);

        return back()->with('status', __('school_erp.attendance_saved'));
    }
}
