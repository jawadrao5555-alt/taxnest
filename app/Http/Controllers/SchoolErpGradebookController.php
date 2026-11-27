<?php

namespace App\Http\Controllers;

use App\Services\SchoolErpGradebook;
use App\Services\SchoolErpWorkspace;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class SchoolErpGradebookController extends Controller
{
    public function index(Request $request, SchoolErpWorkspace $workspace)
    {
        $company = $request->attributes->get('schoolErpCompany');
        $user = $request->attributes->get('schoolErpUser');
        $selection = $request->validate([
            'class_id' => 'nullable|integer|min:1',
            'assessment_id' => 'nullable|integer|min:1',
        ]);
        $classes = $workspace->scopedRecords($user, 'school_erp_classes')
            ->join('branches as campus', 'campus.id', '=', 'school_erp_classes.campus_id')
            ->join('school_erp_terms as term', 'term.id', '=', 'school_erp_classes.term_id')
            ->select('school_erp_classes.*', 'campus.name as campus_name', 'term.name as term_name');
        $activeClass = isset($selection['class_id'])
            ? (clone $classes)->where('school_erp_classes.id', $selection['class_id'])->first()
            : null;
        abort_if(isset($selection['class_id']) && !$activeClass, 404);
        abort_if(isset($selection['assessment_id']) && !$activeClass, 404);

        $campuses = $workspace->campuses($user, true);
        $canCreate = $user->isCompanyAdmin() && $workspace->mayWrite($company);
        if (!$activeClass) {
            $list = $classes->orderByDesc('term.starts_on')->orderBy('school_erp_classes.name')
                ->orderBy('school_erp_classes.id')->paginate(25);
            $terms = DB::table('school_erp_terms')->where('company_id', $company->id)->orderByDesc('starts_on')->get();

            return view('school-erp.gradebook', compact('list', 'terms', 'campuses', 'canCreate'));
        }

        $canManage = ($user->isCompanyAdmin() || $user->role === 'employee')
            && $workspace->mayWrite($company) && $campuses->contains(fn ($campus) => (int) $campus->id === (int) $activeClass->campus_id);
        $identity = ['company_id' => $company->id, 'class_id' => $activeClass->id];
        $assessments = DB::table('school_erp_assessments')->where($identity)->orderByDesc('assessed_on')->orderBy('title')->get();
        $assessment = isset($selection['assessment_id']) ? $assessments->firstWhere('id', $selection['assessment_id']) : $assessments->first();
        abort_if(isset($selection['assessment_id']) && !$assessment, 404);

        $roster = DB::table('school_erp_class_students as roster')
            ->join('school_erp_students as student', 'student.id', '=', 'roster.student_id')
            ->join('school_erp_enrollments as enrollment', function ($join) {
                $join->on('enrollment.student_id', '=', 'roster.student_id')->on('enrollment.term_id', '=', 'roster.term_id');
            })
            ->leftJoin('school_erp_grades as grade', function ($join) use ($assessment) {
                $join->on('grade.class_id', '=', 'roster.class_id')->on('grade.student_id', '=', 'roster.student_id')
                    ->where('grade.assessment_id', $assessment?->id);
            })
            ->where('roster.company_id', $company->id)->where('roster.class_id', $activeClass->id)
            ->select([
                'roster.student_id', 'student.student_reference', 'student.display_name', 'student.campus_id',
                'grade.score_hundredths', 'enrollment.admitted_on',
            ])
            ->selectSub(fn ($query) => $query->from('school_erp_campus_transfers as transfer')->selectRaw('MAX(transfer.created_at)')
                ->whereColumn('transfer.company_id', 'roster.company_id')->whereColumn('transfer.student_id', 'roster.student_id'), 'transferred_at')
            ->orderBy('student.student_reference')->paginate(25, ['*'], 'roster_page')->withQueryString();

        $availableStudents = $canManage
            ? DB::table('school_erp_enrollments as enrollment')
                ->join('school_erp_students as student', 'student.id', '=', 'enrollment.student_id')
                ->where('enrollment.company_id', $company->id)->where('enrollment.term_id', $activeClass->term_id)
                ->where('student.campus_id', $activeClass->campus_id)
                ->whereNotExists(fn ($query) => $query->selectRaw('1')->from('school_erp_class_students as roster')
                    ->where('roster.class_id', $activeClass->id)->whereColumn('roster.student_id', 'student.id'))
                ->orderBy('student.display_name')->orderBy('student.id')
                ->paginate(25, ['student.id', 'student.student_reference', 'student.display_name'], 'eligible_page')->withQueryString()
            : null;

        $canGrade = $canManage && $assessment && $assessment->assessed_on <= now()->toDateString();

        return view('school-erp.gradebook-class', compact(
            'activeClass', 'assessments', 'assessment', 'roster', 'canManage', 'canGrade', 'availableStudents'
        ));
    }

    public function storeClass(Request $request, SchoolErpGradebook $gradebook)
    {
        $data = $request->validate([
            'campus_id' => 'required|integer|min:1',
            'term_id' => 'required|integer|min:1',
            'code' => 'required|string|max:32',
            'name' => 'required|string|max:120',
        ]);
        $gradebook->addClass($request->attributes->get('schoolErpUser'), $data);

        return back()->with('status', __('school_erp.class_saved'));
    }

    public function storeStudent(Request $request, int $class, SchoolErpGradebook $gradebook)
    {
        $data = $request->validate(['student_id' => 'required|integer|min:1']);
        $gradebook->addStudent($request->attributes->get('schoolErpUser'), $class, $data['student_id']);

        return back()->with('status', __('school_erp.roster_saved'));
    }

    public function storeAssessment(Request $request, int $class, SchoolErpGradebook $gradebook)
    {
        $data = $request->validate([
            'code' => 'required|string|max:32',
            'title' => 'required|string|max:120',
            'assessed_on' => 'required|date_format:Y-m-d',
            'max_points' => ['required', 'numeric', 'min:0.01', 'regex:/^[0-9]{1,5}(\.[0-9]{1,2})?$/D'],
        ]);
        $gradebook->addAssessment($request->attributes->get('schoolErpUser'), $class, $data);

        return back()->with('status', __('school_erp.assessment_saved'));
    }

    public function storeGrade(Request $request, int $class, SchoolErpGradebook $gradebook)
    {
        $data = $request->validate([
            'student_id' => 'required|integer|min:1',
            'assessment_id' => 'required|integer|min:1',
            'score' => ['required', 'numeric', 'min:0', 'regex:/^[0-9]{1,5}(\.[0-9]{1,2})?$/D'],
        ]);
        $gradebook->recordGrade($request->attributes->get('schoolErpUser'), $class, $data);

        return back()->with('status', __('school_erp.grade_saved'));
    }
}
