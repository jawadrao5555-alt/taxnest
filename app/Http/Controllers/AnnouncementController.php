<?php

namespace App\Http\Controllers;

use App\Models\AdminAnnouncement;
use App\Models\AnnouncementDismissal;
use App\Models\Company;
use Illuminate\Http\Request;

class AnnouncementController extends Controller
{
    public function index(Request $request)
    {
        $query = AdminAnnouncement::with(['creator', 'targetCompany'])->orderBy('created_at', 'desc');

        if ($request->filled('status')) {
            if ($request->status === 'active') {
                $query->where('is_active', true);
            } else {
                $query->where('is_active', false);
            }
        }

        $announcements = $query->paginate(20);
        $companies = Company::orderBy('name')->get(['id', 'name']);

        return view('admin.announcements', compact('announcements', 'companies'));
    }

    public function store(Request $request)
    {
        $request->validate([
            'title' => 'required|string|max:255',
            'message' => 'required|string|max:2000',
            'type' => 'required|in:info,warning,urgent,success',
            'target' => 'required|in:all,specific',
            'target_company_id' => 'required_if:target,specific|nullable|exists:companies,id',
            'expires_at' => 'nullable|date|after:now',
        ]);

        AdminAnnouncement::create([
            'title' => $request->title,
            'message' => $request->message,
            'type' => $request->type,
            'target' => $request->target,
            'target_company_id' => $request->target === 'specific' ? $request->target_company_id : null,
            'expires_at' => $request->expires_at,
            'is_active' => true,
            'created_by' => auth()->id(),
        ]);

        return redirect('/admin/announcements')->with('success', 'Announcement published successfully.');
    }

    public function toggle($id)
    {
        $announcement = AdminAnnouncement::findOrFail($id);
        $announcement->update(['is_active' => !$announcement->is_active]);
        return redirect('/admin/announcements')->with('success', 'Announcement ' . ($announcement->is_active ? 'activated' : 'deactivated') . '.');
    }

    public function destroy($id)
    {
        AdminAnnouncement::findOrFail($id)->delete();
        return redirect('/admin/announcements')->with('success', 'Announcement deleted.');
    }

    public function dismiss(Request $request, $id)
    {
        AnnouncementDismissal::firstOrCreate([
            'announcement_id' => $id,
            'user_id' => auth()->id(),
        ]);
        return response()->json(['success' => true]);
    }
}
