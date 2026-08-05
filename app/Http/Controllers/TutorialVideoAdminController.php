<?php

namespace App\Http\Controllers;

use App\Models\TutorialVideo;
use App\Services\PosFeatureService;
use Illuminate\Http\Request;

/**
 * Super-admin management of tutorial videos (/admin/tutorial-videos):
 * per-video switches for "published at all" and "visible on the public
 * landing page", plus the subscription feature-gate used inside company
 * logins. Video files/rows themselves ship via migrations + the recording
 * pipeline — this page only controls visibility.
 */
class TutorialVideoAdminController extends Controller
{
    public function index()
    {
        TutorialVideo::applyOwnerControls();

        $videos = TutorialVideo::orderBy('product')->orderBy('sort')->orderBy('id')->get();

        return view('admin.tutorial-videos', [
            'videos' => $videos,
            'gateOptions' => self::gateOptions(),
            'roleOptions' => self::roleOptions(),
        ]);
    }

    public function togglePublished($id)
    {
        $video = TutorialVideo::findOrFail($id);
        $video->update(['is_published' => !$video->is_published]);

        return redirect('/admin/tutorial-videos')->with(
            'success',
            "'{$video->title}' is now " . ($video->is_published ? 'PUBLISHED (companies with the feature can watch it).' : 'UNPUBLISHED (hidden everywhere).')
        );
    }

    public function togglePublic($id)
    {
        $video = TutorialVideo::findOrFail($id);
        $video->update(['show_public' => !$video->show_public]);

        return redirect('/admin/tutorial-videos')->with(
            'success',
            "'{$video->title}' is now " . ($video->show_public ? 'SHOWN on the public landing page.' : 'HIDDEN from the public landing page.')
        );
    }

    public function setGate(Request $request, $id)
    {
        $video = TutorialVideo::findOrFail($id);
        $gate = (string) $request->input('required_feature', '');

        if ($gate !== '' && !array_key_exists($gate, self::gateOptions())) {
            return redirect('/admin/tutorial-videos')->with('error', 'Unknown feature gate.');
        }

        $video->update(['required_feature' => $gate === '' ? null : $gate]);

        return redirect('/admin/tutorial-videos')->with(
            'success',
            "'{$video->title}' gate: " . ($gate === '' ? 'everyone (core feature).' : $gate . ' subscriptions only.')
        );
    }

    /** Role tier inside company logins (ZFC, 5 Aug 2026): staff-filtering. */
    public function setRole(Request $request, $id)
    {
        $video = TutorialVideo::findOrFail($id);
        $role = (string) $request->input('min_role', 'any');

        if (!array_key_exists($role, self::roleOptions())) {
            return redirect('/admin/tutorial-videos')->with('error', 'Unknown role tier.');
        }

        $video->update(['min_role' => $role]);

        return redirect('/admin/tutorial-videos')->with(
            'success',
            "'{$video->title}' role tier: " . self::roleOptions()[$role] . '.'
        );
    }

    /** Valid min_role values => human labels for the dropdown. */
    public static function roleOptions(): array
    {
        return [
            'any' => 'Everyone (waiter/kitchen/rider too)',
            'cashier' => 'Cashier & up',
            'admin' => 'Admin/Manager only',
        ];
    }

    /** Valid gate keys => human labels for the dropdown. */
    public static function gateOptions(): array
    {
        $opts = ['restaurant' => 'Restaurant module'];
        foreach (PosFeatureService::PLAN_GATES as $col) { // plain list of column names
            $opts[$col] = ucwords(str_replace(['_enabled', '_'], ['', ' '], $col));
        }

        return $opts;
    }
}
