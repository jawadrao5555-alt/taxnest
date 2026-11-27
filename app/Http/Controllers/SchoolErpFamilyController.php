<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\SchoolErpAnnouncements;
use App\Services\SchoolErpFamilyAccess;
use App\Services\SchoolErpWorkspace;
use App\Support\IdentityScope;
use App\Support\NestErps;
use App\Support\SchoolErpPanel;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

final class SchoolErpFamilyController extends Controller
{
    public function index(Request $request, SchoolErpFamilyAccess $access, SchoolErpAnnouncements $announcements)
    {
        $user = $request->attributes->get('schoolErpUser');
        $students = $access->students($user)
            ->orderBy('student.display_name')->orderBy('student.id')
            ->get(['student.id', 'student.display_name', 'student.student_reference']);

        $notices = $announcements->forFamily($user, $access)->simplePaginate(15, pageName: 'announcements_page');

        return view('school-erp.family', compact('students', 'notices'));
    }

    public function student(Request $request, SchoolErpFamilyAccess $access, int $student)
    {
        $user = $request->attributes->get('schoolErpUser');
        $student = $access->students($user)->where('student.id', $student)
            ->first(['student.id', 'student.display_name', 'student.student_reference']);
        abort_unless($student, 404);

        $attendance = DB::table('school_erp_attendance')
            ->where('company_id', $user->company_id)->where('student_id', $student->id)
            ->orderByDesc('attendance_on')->simplePaginate(30, ['attendance_on', 'status'], 'attendance_page')->withQueryString();
        $grades = DB::table('school_erp_grades as grade')
            ->join('school_erp_assessments as assessment', function ($join) {
                $join->on('assessment.company_id', '=', 'grade.company_id')
                    ->on('assessment.class_id', '=', 'grade.class_id')->on('assessment.id', '=', 'grade.assessment_id');
            })
            ->join('school_erp_classes as class', function ($join) {
                $join->on('class.company_id', '=', 'grade.company_id')->on('class.id', '=', 'grade.class_id');
            })
            ->where('grade.company_id', $user->company_id)->where('grade.student_id', $student->id)
            ->orderByDesc('assessment.assessed_on')->orderByDesc('grade.id')
            ->simplePaginate(30, [
                'class.name as class_name', 'assessment.title', 'assessment.assessed_on', 'assessment.max_hundredths', 'grade.score_hundredths',
            ], 'grades_page')->withQueryString();

        return view('school-erp.family-student', compact('student', 'attendance', 'grades'));
    }

    public function manage(Request $request, SchoolErpWorkspace $workspace)
    {
        $owner = $this->owner($request);
        $accounts = User::where('company_id', $owner->company_id)->where('role', SchoolErpPanel::FAMILY_ROLE)
            ->where('is_active', true)->orderBy('name')->orderBy('id')->simplePaginate(30, ['id', 'name', 'email']);
        $grants = DB::table('school_erp_family_access as access')
            ->join('school_erp_students as student', function ($join) {
                $join->on('student.company_id', '=', 'access.company_id')->on('student.id', '=', 'access.student_id');
            })
            ->where('access.company_id', $owner->company_id)->whereIn('access.user_id', $accounts->getCollection()->pluck('id'))
            ->whereNull('access.revoked_at')->orderBy('student.display_name')->orderBy('access.id')
            ->get(['access.id', 'access.user_id', 'student.display_name', 'student.student_reference'])->groupBy('user_id');
        $canGrant = $workspace->mayWrite($request->attributes->get('schoolErpCompany'));

        return view('school-erp.family-access', compact('accounts', 'grants', 'canGrant'));
    }

    public function create(Request $request, SchoolErpFamilyAccess $access)
    {
        $owner = $this->owner($request);
        $data = $request->validate([
            'name' => 'required|string|max:120',
            'email' => ['required', 'email', 'max:255', IdentityScope::uniqueEmail(NestErps::PRODUCT_TYPE)],
            'password' => ['required', 'confirmed', Password::defaults()],
            'student_reference' => 'required|string|max:64',
        ]);
        try {
            $access->createAccount($owner, $data);
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['email' => __('school_erp.account_exists')]);
        }

        return redirect()->route('school-erp.family-access')->with('status', __('school_family.granted'));
    }

    public function link(Request $request, SchoolErpFamilyAccess $access, int $user)
    {
        $owner = $this->owner($request);
        $data = $request->validate(['student_reference' => 'required|string|max:64']);
        $access->linkStudent($owner, $user, $data['student_reference']);

        return redirect()->route('school-erp.family-access')->with('status', __('school_family.granted'));
    }

    public function revoke(Request $request, SchoolErpFamilyAccess $access, int $grant)
    {
        $access->revoke($this->owner($request), $grant);

        return redirect()->route('school-erp.family-access')->with('status', __('school_family.revoked'));
    }

    private function owner(Request $request): User
    {
        $user = $request->attributes->get('schoolErpUser');
        abort_unless($user->isCompanyAdmin(), 403);

        return $user;
    }
}
