<?php

namespace App\Http\Controllers;

use App\Services\SchoolErpWorkspace;
use App\Support\IdentityScope;
use App\Support\NestErps;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

final class SchoolErpWorkspaceController extends Controller
{
    public function index(Request $request, SchoolErpWorkspace $workspace)
    {
        $company = $request->attributes->get('schoolErpCompany');
        $user = $request->attributes->get('schoolErpUser');
        $campuses = $workspace->campuses($user);
        $selection = $request->validate(['campus_id' => ['nullable', 'integer', Rule::in($campuses->pluck('id')->all())]]);
        $campusId = isset($selection['campus_id']) ? (int) $selection['campus_id'] : null;

        $students = $workspace->scopedRecords($user, 'school_erp_students', $campusId)
            ->leftJoin('branches', 'branches.id', '=', 'school_erp_students.campus_id')
            ->orderBy('school_erp_students.display_name')->orderBy('school_erp_students.id')
            ->paginate(25, ['school_erp_students.*', 'branches.name as campus_name'], 'students_page')->withQueryString();
        $enrollments = $workspace->scopedRecords($user, 'school_erp_enrollments', $campusId)
            ->join('school_erp_students as student', 'student.id', '=', 'school_erp_enrollments.student_id')
            ->join('school_erp_terms as term', 'term.id', '=', 'school_erp_enrollments.term_id')
            ->leftJoin('branches', 'branches.id', '=', 'school_erp_enrollments.campus_id')
            ->orderByDesc('school_erp_enrollments.admitted_on')->orderByDesc('school_erp_enrollments.id')
            ->paginate(25, ['student.student_reference', 'term.name as term_name', 'branches.name as campus_name', 'school_erp_enrollments.admitted_on'], 'admissions_page')
            ->withQueryString();
        $terms = DB::table('school_erp_terms')->where('company_id', $company->id)->orderByDesc('starts_on')->orderByDesc('id')->get();
        $canManage = $user->isCompanyAdmin();
        $canWrite = $workspace->mayWrite($company);
        $writeCampuses = $workspace->campuses($user, true);
        $canAdmit = $canWrite && ($canManage || ($user->role === 'employee' && $writeCampuses->isNotEmpty()));

        return view('school-erp.dashboard', compact(
            'company', 'campuses', 'campusId', 'students', 'enrollments', 'terms', 'canManage', 'canWrite', 'canAdmit', 'writeCampuses'
        ));
    }

    public function campus(Request $request, SchoolErpWorkspace $workspace)
    {
        $data = $request->validate(['campus_name' => 'required|string|max:120']);
        $workspace->addCampus($request->attributes->get('schoolErpUser'), $data['campus_name']);

        return back()->with('status', __('school_erp.campus_saved'));
    }

    public function student(Request $request, SchoolErpWorkspace $workspace)
    {
        $data = $request->validate([
            'student_reference' => 'required|string|max:64',
            'display_name' => 'required|string|max:160',
            'campus_id' => 'nullable|integer|min:1',
        ]);
        $workspace->addStudent($request->attributes->get('schoolErpUser'), $data);

        return back()->with('status', __('school_erp.student_saved'));
    }

    public function term(Request $request, SchoolErpWorkspace $workspace)
    {
        $data = $request->validate([
            'code' => 'required|string|max:32',
            'name' => 'required|string|max:120',
            'starts_on' => 'required|date_format:Y-m-d',
            'ends_on' => 'required|date_format:Y-m-d|after_or_equal:starts_on',
        ]);
        $workspace->addTerm($request->attributes->get('schoolErpUser'), $data);

        return back()->with('status', __('school_erp.term_saved'));
    }

    public function admit(Request $request, SchoolErpWorkspace $workspace)
    {
        $data = $request->validate([
            'student_id' => 'required|integer|min:1',
            'term_id' => 'required|integer|min:1',
            'admitted_on' => 'required|date_format:Y-m-d',
        ]);
        $workspace->admit($request->attributes->get('schoolErpUser'), $data);

        return back()->with('status', __('school_erp.admission_saved'));
    }

    public function transfer(Request $request, int $student, SchoolErpWorkspace $workspace)
    {
        $data = $request->validate(['campus_id' => 'required|integer|min:1']);
        $workspace->transfer($request->attributes->get('schoolErpUser'), $student, $data['campus_id']);

        return back()->with('status', __('school_erp.transfer_saved'));
    }

    public function staff(Request $request, SchoolErpWorkspace $workspace)
    {
        $data = $request->validate([
            'staff_name' => 'required|string|max:120',
            'staff_email' => ['required', 'email', 'max:255', IdentityScope::uniqueEmail(NestErps::PRODUCT_TYPE)],
            'staff_campus_id' => 'required|integer|min:1',
            'staff_access' => ['required', Rule::in(['full', 'read_only'])],
            'password' => ['required', 'confirmed', Password::defaults()],
        ]);
        $workspace->addStaff($request->attributes->get('schoolErpUser'), $data);

        return back()->with('status', __('school_erp.staff_saved'));
    }
}
