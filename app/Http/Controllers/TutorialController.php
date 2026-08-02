<?php

namespace App\Http\Controllers;

use App\Models\TutorialVideo;

/**
 * Urdu tutorial video library (owner request, 2 Aug 2026):
 * - Public page /tutorials linked from the marketing top nav.
 * - In-app page /pos/tutorials for every company login (pos.auth only, NO
 *   company.approval — pending companies may learn while they wait, same
 *   precedent as the Madadgar bot).
 */
class TutorialController extends Controller
{
    public function publicIndex()
    {
        return view('tutorials', [
            'groups' => TutorialVideo::groupedForDisplay(),
        ]);
    }

    public function posIndex()
    {
        return view('pos.tutorials', [
            'groups' => TutorialVideo::groupedForDisplay(),
        ]);
    }
}
