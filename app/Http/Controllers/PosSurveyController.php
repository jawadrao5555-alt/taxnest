<?php

namespace App\Http\Controllers;

use App\Models\Survey;
use App\Models\SurveyResponse;
use App\Models\SystemSetting;
use Illuminate\Http\Request;

/**
 * Task 1022: POS survey popup (Caller ID elaan / advice collection).
 *
 * POS side: submit answers / dismiss-for-session — admin/manager only
 * (same owner rule as What's New: cashiers/confined roles never see it,
 * so their POSTs are also refused). Admin side: surveys list + results.
 */
class PosSurveyController extends Controller
{
    // ============ POS SIDE ============

    /**
     * Server-side eligibility — the SINGLE gate for both write endpoints.
     * Blade-side filtering is presentation only; a direct POST must never
     * let an out-of-audience company pollute results or seen metrics.
     */
    private function eligibleSurvey($id, $user): ?Survey
    {
        $survey = Survey::find($id);
        if (!$survey || !$survey->isActive()) {
            return null;
        }
        if ($survey->audience === 'pos_restaurant' && !((bool) ($user->company?->restaurant_mode ?? false))) {
            return null;
        }

        return $survey;
    }

    /** Submit answers — one response per user, validated against the survey's own options. */
    public function respond(Request $request, $id)
    {
        $user = auth('pos')->user();
        if (!$user || !$user->isPosAdmin()) {
            return response()->json(['ok' => false], 403);
        }

        $survey = $this->eligibleSurvey($id, $user);
        if (!$survey) {
            return response()->json(['ok' => false, 'message' => 'Survey band ho chuka hai.'], 404);
        }

        // Validate against the survey's OWN questions/options — every question
        // required, answer must be one of that question's option keys.
        $answers = $request->input('answers');
        if (!is_array($answers)) {
            return response()->json(['ok' => false, 'message' => 'Jawab ghalat format mein hain.'], 422);
        }
        $clean = [];
        foreach ($survey->questions as $q) {
            $qKey = $q['key'] ?? null;
            $optKeys = array_map(fn ($o) => $o['key'] ?? null, $q['options'] ?? []);
            $given = $answers[$qKey] ?? null;
            if ($qKey === null || !is_string($given) || !in_array($given, $optKeys, true)) {
                return response()->json(['ok' => false, 'message' => 'Har sawal ka jawab chunein.'], 422);
            }
            $clean[$qKey] = $given;
        }

        $comment = null;
        if ($survey->allow_comment && is_string($request->input('comment'))) {
            $comment = mb_substr(trim($request->input('comment')), 0, 2000) ?: null;
        }

        // Atomic first-write-wins: get-or-create the (survey,user) row, then a
        // CONDITIONAL update guarded on answered_at IS NULL. Two concurrent
        // submits (incl. the dismissed-row path where the row already exists)
        // can both reach here — only one conditional update affects a row; the
        // loser reports 'already' and never overwrites the first answer.
        try {
            $row = SurveyResponse::firstOrCreate(
                ['survey_id' => $survey->id, 'user_id' => $user->id],
                ['company_id' => $user->company_id]
            );
        } catch (\Throwable $e) {
            // Unique-constraint race on create — the other request made the row.
            $row = SurveyResponse::where('survey_id', $survey->id)->where('user_id', $user->id)->first();
        }
        if (!$row) {
            return response()->json(['ok' => false], 500);
        }

        $won = SurveyResponse::where('id', $row->id)->whereNull('answered_at')->update([
            'answers' => json_encode($clean, JSON_UNESCAPED_UNICODE),
            'comment' => $comment,
            'answered_at' => now(),
            'company_id' => $user->company_id,
            'updated_at' => now(),
        ]);
        if ($won === 0) {
            // Already answered (double click / two tabs) — idempotent, never overwrite.
            return response()->json(['ok' => true, 'already' => true]);
        }

        return response()->json(['ok' => true]);
    }

    /** "Baad mein" — hides the popup for THIS session; pill/bell entry stays until answered. */
    public function dismiss(Request $request, $id)
    {
        $user = auth('pos')->user();
        if (!$user || !$user->isPosAdmin()) {
            return response()->json(['ok' => false], 403);
        }

        // Same eligibility gate as respond(): an out-of-audience or closed
        // survey never gets a seen row (would skew "companies saw" metrics).
        $survey = $this->eligibleSurvey($id, $user);
        if ($survey) {
            // Seen tracking for the admin "saw vs answered" counts.
            try {
                SurveyResponse::firstOrCreate(
                    ['survey_id' => $survey->id, 'user_id' => $user->id],
                    ['company_id' => $user->company_id]
                );
            } catch (\Throwable $e) {
                // race — row already exists
            }
            session(['pos_survey_dismissed_' . $survey->id => true]);
        }

        return response()->json(['ok' => true]);
    }

    // ============ ADMIN SIDE ============

    public function adminIndex()
    {
        $surveys = Survey::withCount([
            'responses as seen_count',
            'responses as answered_count' => fn ($q) => $q->whereNotNull('answered_at'),
        ])->orderByDesc('created_at')->paginate(15);

        $featureOn = SystemSetting::get('pos_surveys_enabled', '1') === '1';

        return view('admin.surveys', compact('surveys', 'featureOn'));
    }

    public function adminShow($id)
    {
        $survey = Survey::findOrFail($id);
        $responses = $survey->responses()->with(['user:id,name', 'company:id,name,restaurant_mode'])->get();

        $answered = $responses->filter(fn ($r) => $r->answered_at !== null);

        // Per-question option counts: overall + restaurant-mode companies split.
        $stats = [];
        foreach ($survey->questions as $q) {
            $row = ['text' => $q['text'] ?? '', 'options' => []];
            foreach (($q['options'] ?? []) as $opt) {
                $all = $answered->filter(fn ($r) => ($r->answers[$q['key']] ?? null) === $opt['key']);
                $row['options'][] = [
                    'label' => $opt['label'] ?? $opt['key'],
                    'count' => $all->count(),
                    'restaurant' => $all->filter(fn ($r) => (bool) ($r->company->restaurant_mode ?? false))->count(),
                ];
            }
            $stats[] = $row;
        }

        $comments = $answered->filter(fn ($r) => filled($r->comment))->sortByDesc('answered_at')->values();

        $seenCompanies = $responses->pluck('company_id')->unique()->count();
        $answeredCompanies = $answered->pluck('company_id')->unique()->count();

        return view('admin.survey-results', compact(
            'survey', 'stats', 'comments', 'seenCompanies', 'answeredCompanies', 'responses', 'answered'
        ));
    }

    /** Close (stop showing on POS, keep results) or reopen. */
    public function toggleClose($id)
    {
        $survey = Survey::findOrFail($id);
        $survey->update(['closed_at' => $survey->closed_at ? null : now()]);

        return redirect()->back()->with('success', 'Survey ' . ($survey->closed_at ? 'closed — POS par ab nahi dikhega (results mehfooz hain).' : 'reopened — POS par dobara dikhega.'));
    }

    /** Master kill switch for ALL survey popups/pills (same convention as What's New). */
    public function toggleFeature()
    {
        $on = SystemSetting::get('pos_surveys_enabled', '1') === '1';
        SystemSetting::set('pos_surveys_enabled', $on ? '0' : '1', 'POS survey popups master switch');

        return redirect()->back()->with('success', 'POS surveys ' . ($on ? 'DISABLED' : 'ENABLED') . ' for all POS users.');
    }
}
