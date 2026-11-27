<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\SchoolErpAnnouncements;
use App\Services\SchoolErpWorkspace;
use Illuminate\Http\Request;

final class SchoolErpAnnouncementController extends Controller
{
    public function index(Request $request, SchoolErpWorkspace $workspace, SchoolErpAnnouncements $announcements)
    {
        $owner = $this->owner($request);
        $canPublish = $workspace->mayWrite($request->attributes->get('schoolErpCompany'));
        $campuses = $workspace->campuses($owner);
        $notices = $announcements->forSchool($owner->company_id)->simplePaginate(20);

        return view('school-erp.announcements', compact('canPublish', 'campuses', 'notices'));
    }

    public function publish(Request $request, SchoolErpAnnouncements $announcements)
    {
        $owner = $this->owner($request);
        $data = $request->validate([
            'publication_key' => 'required|uuid',
            'title' => 'required|string|max:120',
            'body' => 'required|string|max:2000',
            'campus_id' => 'nullable|integer|min:1',
            'expires_on' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:'.now()->toDateString()],
        ]);
        $announcements->publish($owner, $data);

        return redirect()->route('school-erp.announcements')->with('status', __('school_announcement.published'));
    }

    public function withdraw(Request $request, SchoolErpAnnouncements $announcements, int $notice)
    {
        $announcements->withdraw($this->owner($request), $notice);

        return redirect()->route('school-erp.announcements')->with('status', __('school_announcement.withdrawn'));
    }

    private function owner(Request $request): User
    {
        $user = $request->attributes->get('schoolErpUser');
        abort_unless($user->isCompanyAdmin(), 403);

        return $user;
    }
}
