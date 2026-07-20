<?php

namespace App\Http\Controllers;

use App\Models\AppUpdate;
use App\Models\AppUpdateSeen;
use App\Models\SystemSetting;
use Illuminate\Http\Request;

class AppUpdateController extends Controller
{
    // ============ ADMIN SIDE ============

    public function index()
    {
        $updates = AppUpdate::withCount('seens')->orderByDesc('created_at')->paginate(20);
        $featureOn = SystemSetting::get('pos_whats_new_enabled', '1') === '1';

        return view('admin.app-updates', compact('updates', 'featureOn'));
    }

    public function store(Request $request)
    {
        $request->validate([
            'title' => 'required|string|max:150',
            'points_text' => 'required|string|max:3000',
        ]);

        $points = $this->parsePoints($request->points_text);
        if (empty($points)) {
            return redirect('/admin/app-updates')->with('error', 'At least one feature point is required.');
        }

        AppUpdate::create([
            'title' => $request->title,
            'points' => $points,
            'audience' => 'pos',
            'is_published' => $request->boolean('is_published', true),
            'created_by' => auth()->id(),
        ]);

        return redirect('/admin/app-updates')->with('success', 'Update published. POS users will see it on their next page load.');
    }

    public function update(Request $request, $id)
    {
        $appUpdate = AppUpdate::findOrFail($id);

        $request->validate([
            'title' => 'required|string|max:150',
            'points_text' => 'required|string|max:3000',
        ]);

        $points = $this->parsePoints($request->points_text);
        if (empty($points)) {
            return redirect('/admin/app-updates')->with('error', 'At least one feature point is required.');
        }

        $appUpdate->update([
            'title' => $request->title,
            'points' => $points,
        ]);

        return redirect('/admin/app-updates')->with('success', 'Update saved.');
    }

    public function toggle($id)
    {
        $appUpdate = AppUpdate::findOrFail($id);
        $appUpdate->update(['is_published' => !$appUpdate->is_published]);

        return redirect('/admin/app-updates')->with('success', 'Update ' . ($appUpdate->is_published ? 'published' : 'unpublished') . '.');
    }

    public function destroy($id)
    {
        $appUpdate = AppUpdate::findOrFail($id);
        AppUpdateSeen::where('app_update_id', $appUpdate->id)->delete();
        $appUpdate->delete();

        return redirect('/admin/app-updates')->with('success', 'Update deleted.');
    }

    public function toggleFeature()
    {
        $on = SystemSetting::get('pos_whats_new_enabled', '1') === '1';
        SystemSetting::set('pos_whats_new_enabled', $on ? '0' : '1', 'POS What\'s New notifications (popup + bell) master switch');

        return redirect('/admin/app-updates')->with('success', 'What\'s New notifications ' . ($on ? 'DISABLED' : 'ENABLED') . ' for all POS users.');
    }

    private function parsePoints(string $text): array
    {
        $points = array_values(array_filter(array_map('trim', preg_split('/\r\n|\r|\n/', $text)), fn ($p) => $p !== ''));

        return array_slice($points, 0, 15);
    }

    // ============ POS SIDE ============

    /**
     * Mark published POS updates as seen for the logged-in POS user.
     * Called by the one-time popup ("Samajh Gaya") and by opening the bell dropdown.
     */
    public function markSeen(Request $request)
    {
        $user = auth('pos')->user();
        if (!$user) {
            return response()->json(['ok' => false], 401);
        }

        $ids = AppUpdate::where('audience', 'pos')->published()->pluck('id');
        $already = AppUpdateSeen::where('user_id', $user->id)->whereIn('app_update_id', $ids)->pluck('app_update_id')->all();

        foreach ($ids as $id) {
            if (!in_array($id, $already)) {
                try {
                    AppUpdateSeen::create(['app_update_id' => $id, 'user_id' => $user->id]);
                } catch (\Throwable $e) {
                    // Unique-constraint race (double click / two tabs) — already seen, ignore.
                }
            }
        }

        return response()->json(['ok' => true]);
    }
}
