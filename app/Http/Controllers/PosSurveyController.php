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

    /** Create a new survey (draft or immediately published). */
    public function adminStore(\Illuminate\Http\Request $request)
    {
        $validated = $request->validate([
            'title'          => 'required|string|max:200',
            'intro'          => 'nullable|string|max:1000',
            'questions_json' => 'required|string',
            'allow_comment'  => 'nullable',
            'audience'       => 'required|in:pos_all,pos_restaurant',
            'is_published'   => 'nullable',
        ]);

        $questions = json_decode($validated['questions_json'], true);
        $error = $this->validateQuestionsPayload($questions);
        if ($error) {
            return back()->with('error', $error)->withInput();
        }

        Survey::create([
            'title'         => $validated['title'],
            'intro'         => $validated['intro'] ?? null,
            'questions'     => $questions,
            'allow_comment' => $request->boolean('allow_comment'),
            'audience'      => $validated['audience'],
            'is_published'  => $request->boolean('is_published'),
        ]);

        return redirect()->route('admin.surveys')->with('success', 'Survey bana diya gaya!');
    }

    /** Update an existing survey. Blocked once published AND responses exist. */
    public function adminUpdate(\Illuminate\Http\Request $request, $id)
    {
        $survey = Survey::findOrFail($id);
        $hasResponses = $survey->responses()->exists();

        if ($hasResponses && $survey->is_published) {
            return back()->with('error', 'Published survey mein responses aa chuke hain — results consistent rakhne ke liye editing band hai. Pehle survey band karein, phir new survey banayein.');
        }

        $validated = $request->validate([
            'title'          => 'required|string|max:200',
            'intro'          => 'nullable|string|max:1000',
            'questions_json' => 'required|string',
            'allow_comment'  => 'nullable',
            'audience'       => 'required|in:pos_all,pos_restaurant',
            'is_published'   => 'nullable',
        ]);

        $questions = json_decode($validated['questions_json'], true);
        $error = $this->validateQuestionsPayload($questions);
        if ($error) {
            return back()->with('error', $error)->withInput();
        }

        $survey->update([
            'title'         => $validated['title'],
            'intro'         => $validated['intro'] ?? null,
            'questions'     => $questions,
            'allow_comment' => $request->boolean('allow_comment'),
            'audience'      => $validated['audience'],
            'is_published'  => $request->boolean('is_published'),
        ]);

        return redirect()->route('admin.surveys')->with('success', 'Survey update ho gaya!');
    }

    /** Delete a survey — only allowed if no responses exist. */
    public function adminDestroy($id)
    {
        $survey = Survey::findOrFail($id);
        if ($survey->responses()->exists()) {
            return back()->with('error', 'Is survey mein responses hain — delete nahi kar sakte. Results ke liye band karein.');
        }
        $survey->delete();

        return redirect()->route('admin.surveys')->with('success', 'Survey delete ho gaya.');
    }

    /** Shared question-structure validator. Returns an error string or null. */
    private function validateQuestionsPayload($questions): ?string
    {
        if (!is_array($questions) || count($questions) < 1) {
            return 'Kam se kam ek sawal zaroori hai.';
        }

        $qKeys = [];
        foreach ($questions as $i => $q) {
            $n = $i + 1;
            if (empty(trim($q['text'] ?? ''))) {
                return "Sawal #{$n} ka text khali hai.";
            }
            $qKey = $q['key'] ?? null;
            if (empty($qKey) || !is_string($qKey)) {
                return "Sawal #{$n} ki key missing ya invalid hai.";
            }
            if (in_array($qKey, $qKeys, true)) {
                return "Sawal #{$n} ki key duplicate hai — har sawal ki key unique honi chahiye.";
            }
            $qKeys[] = $qKey;

            if (empty($q['options']) || !is_array($q['options']) || count($q['options']) < 2) {
                return "Sawal #{$n} mein kam se kam 2 options chahiye.";
            }
            $optKeys = [];
            foreach ($q['options'] as $j => $opt) {
                if (empty(trim($opt['label'] ?? ''))) {
                    return "Sawal #{$n}, Option #" . ($j + 1) . " ka label khali hai.";
                }
                $optKey = $opt['key'] ?? null;
                if (empty($optKey) || !is_string($optKey)) {
                    return "Sawal #{$n}, Option #" . ($j + 1) . " ki key missing ya invalid hai.";
                }
                if (in_array($optKey, $optKeys, true)) {
                    return "Sawal #{$n}, Option #" . ($j + 1) . " ki key duplicate hai.";
                }
                $optKeys[] = $optKey;
            }
        }

        return null;
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
