<?php

namespace App\Http\Controllers;

use App\Models\FeatureSuggestion;
use App\Models\MadadgarMessage;
use App\Models\Company;
use App\Services\MadadgarService;
use Illuminate\Http\Request;

/**
 * Madadgar AI support bot (PRA POS) — owner request 22 Jul 2026.
 *
 * ALL POS roles allowed, including cashiers (owner explicit). Routes live in a
 * pos.auth-ONLY group (NO company.approval — pending companies may chat; their
 * POSTs would otherwise be 403'd).
 *
 * Escalation is a real state machine: the model's tool call only produces a
 * pending card; the FeatureSuggestion row is created EXCLUSIVELY in escalate()
 * when the user taps "Haan" (bypasses the isPosAdmin gate of the suggestion
 * form on purpose — bot escalations are open to every role; the /pos/suggestions
 * VIEW page stays admin/manager-only).
 */
class MadadgarController extends Controller
{
    private const DAILY_MESSAGE_CAP = 30;   // user messages per user per day
    private const DAILY_ESCALATION_CAP = 5; // confirmed escalations per user per day
    private const CONTEXT_MESSAGES = 12;    // rows sent to the model per turn

    /**
     * Panel detection (Task 1275): the same three endpoints serve both the PRA
     * panel (/pos/madadgar/*, guard 'pos') and the FBR panel
     * (/fbr-pos/madadgar/*, guard 'fbrpos'). Guard name === product name on
     * both panels, so one string drives auth, KB choice and escalation product.
     */
    private function panelProduct(Request $request): string
    {
        return $request->is('fbr-pos/*') ? 'fbrpos' : 'pos';
    }

    public function history(Request $request)
    {
        $user = auth($this->panelProduct($request))->user();
        $sessionId = $this->sessionId($request);

        $messages = MadadgarMessage::where('user_id', $user->id)
            ->where('company_id', $user->company_id)
            ->where('session_id', $sessionId)
            ->orderBy('id')
            ->limit(60)
            ->get(['role', 'content', 'escalation_id', 'created_at']);

        return response()->json([
            'enabled' => MadadgarService::enabled(),
            'messages' => $messages->map(fn ($m) => [
                'role' => $m->role,
                'content' => $m->content,
                'escalated' => (bool) $m->escalation_id,
            ])->values(),
            'remaining' => max(0, self::DAILY_MESSAGE_CAP - $this->todayCount($user->id)),
        ]);
    }

    public function message(Request $request)
    {
        $product = $this->panelProduct($request);
        $user = auth($product)->user();

        if (!MadadgarService::enabled()) {
            return response()->json(['error' => 'Madadgar abhi dastyab nahi — WhatsApp par rabta karein.'], 503);
        }

        $request->validate([
            'content' => 'required|string|max:1000',
            'session_id' => 'required|uuid',
        ]);
        $sessionId = $this->sessionId($request);

        if ($this->todayCount($user->id) >= self::DAILY_MESSAGE_CAP) {
            return response()->json(['error' => 'Aaj ke sawalat ki limit poori ho gayi — kal dobara pooch sakte hain, ya WhatsApp par rabta karein.'], 429);
        }

        $userRow = MadadgarMessage::create([
            'company_id' => $user->company_id,
            'user_id' => $user->id,
            'session_id' => $sessionId,
            'role' => 'user',
            'content' => trim($request->content),
        ]);

        $history = MadadgarMessage::where('user_id', $user->id)
            ->where('company_id', $user->company_id)
            ->where('session_id', $sessionId)
            ->orderByDesc('id')
            ->limit(self::CONTEXT_MESSAGES)
            ->get(['role', 'content'])
            ->reverse()
            ->map(fn ($m) => ['role' => $m->role, 'content' => $m->content])
            ->values()
            ->all();

        $question = trim($request->content);
        $company = Company::find($user->company_id);

        try {
            $result = MadadgarService::respond($history, $question, $product, $company);
        } catch (\Throwable $e) {
            // Sirf-OpenAI mode only (hybrid/local degrade internally): failed
            // turn must not eat the daily cap or leave a reply-less row.
            $userRow->delete();

            return response()->json(['error' => 'Maazrat, is waqt jawab nahi mil saka — thori der baad koshish karein ya WhatsApp par rabta karein.'], 502);
        }

        // Fallback turns ("maloom nahi") don't burn the daily cap — the user
        // row stays for admin visibility of missed questions, but todayCount()
        // excludes source='fallback' user rows.
        if ($result['source'] === 'fallback') {
            $userRow->update(['source' => 'fallback']);
        }

        // Rule-based escalation for turns OpenAI didn't handle: complaint /
        // "admin ko batao" wording, or any unanswered (fallback) turn. Card
        // only — the FeatureSuggestion row is still created EXCLUSIVELY in
        // escalate() on the user's "Haan".
        if ($result['source'] !== 'openai' && $result['escalation'] === null) {
            $result['escalation'] = \App\Services\MadadgarLocalEngine::ruleEscalation(
                $question,
                $result['source'] === 'fallback'
            );
        }

        // Escalation-limit pre-check (customer report, 22 Jul 2026): agar aaj ki
        // escalation limit poori ho chuki hai to confirm card BILKUL mat bhejo —
        // warna user "Haan, bhej dein" dabata hai aur upar red error, neeche
        // phir bhi card — confusing. Card ki jagah reply mein hi saaf bata do.
        if ($result['escalation'] !== null) {
            $todayEsc = FeatureSuggestion::where('user_id', $user->id)
                ->where('source', 'madadgar')
                ->whereDate('created_at', now()->toDateString())
                ->count();
            if ($todayEsc >= self::DAILY_ESCALATION_CAP) {
                $result['escalation'] = null;
                $result['text'] = rtrim($result['text'])
                    ."\n\nNote: aaj admin team ko bhejne ki limit poori ho chuki hai — kal dobara bhej sakte hain, ya foran madad ke liye WhatsApp par rabta karein.";
            }
        }

        MadadgarMessage::create([
            'company_id' => $user->company_id,
            'user_id' => $user->id,
            'session_id' => $sessionId,
            'role' => 'assistant',
            'content' => $result['text'],
            'source' => $result['source'],
        ]);

        return response()->json([
            'reply' => $result['text'],
            'escalation' => $result['escalation'], // null | {title, summary, kind} => confirm card
            'remaining' => max(0, self::DAILY_MESSAGE_CAP - $this->todayCount($user->id)),
        ]);
    }

    public function escalate(Request $request)
    {
        $product = $this->panelProduct($request);
        $user = auth($product)->user();

        if (!MadadgarService::enabled()) {
            return response()->json(['error' => 'Madadgar abhi dastyab nahi — WhatsApp par rabta karein.'], 503);
        }

        $request->validate([
            'title' => 'required|string|max:150',
            'summary' => 'nullable|string|max:1500',
            'kind' => 'required|in:problem,feature_request',
            'session_id' => 'required|uuid',
        ]);
        $sessionId = $this->sessionId($request);

        $todayEsc = FeatureSuggestion::where('user_id', $user->id)
            ->where('source', 'madadgar')
            ->whereDate('created_at', now()->toDateString())
            ->count();
        if ($todayEsc >= self::DAILY_ESCALATION_CAP) {
            return response()->json(['error' => 'Aaj admin ko bhejne ki limit poori ho gayi — kal dobara, ya WhatsApp par rabta karein.'], 429);
        }

        $kindLabel = $request->kind === 'feature_request' ? 'Feature Request' : 'Masla / Problem';
        $suggestion = FeatureSuggestion::create([
            'company_id' => $user->company_id,
            'user_id' => $user->id,
            'product' => $product,
            'title' => mb_substr(trim($request->title), 0, 150),
            'details' => '['.$kindLabel.'] '.trim((string) $request->summary),
            'status' => 'pending',
            'source' => 'madadgar',
        ]);

        $confirmText = 'Aap ki baat admin team ko bhej di gayi hai (Ref #'.$suggestion->id.'). Jaise hi is par kaam hoga, status update ho jayega. Shukriya!';
        MadadgarMessage::create([
            'company_id' => $user->company_id,
            'user_id' => $user->id,
            'session_id' => $sessionId,
            'role' => 'assistant',
            'content' => $confirmText,
            'escalation_id' => $suggestion->id,
        ]);

        return response()->json(['reply' => $confirmText, 'ref' => $suggestion->id]);
    }

    // ============ ADMIN SIDE (admin.auth group) ============

    public function adminChats(Request $request)
    {
        $sessions = MadadgarMessage::selectRaw('session_id, company_id, user_id, COUNT(*) as msg_count, MAX(created_at) as last_at, MAX(escalation_id) as any_escalation')
            ->groupBy('session_id', 'company_id', 'user_id')
            ->orderByDesc('last_at')
            ->paginate(20)
            ->withQueryString();

        $companies = \App\Models\Company::whereIn('id', $sessions->pluck('company_id')->unique())->pluck('name', 'id');
        $users = \App\Models\User::whereIn('id', $sessions->pluck('user_id')->unique())->pluck('name', 'id');

        $activeSession = null;
        $activeMessages = collect();
        if ($request->filled('session')) {
            $sid = (string) $request->query('session');
            if (preg_match('/^[0-9a-fA-F-]{36}$/', $sid)) {
                $activeSession = strtolower($sid);
                $activeMessages = MadadgarMessage::with(['user', 'company'])
                    ->where('session_id', $activeSession)
                    ->orderBy('id')
                    ->limit(200)
                    ->get();
            }
        }

        $keySource = 'none';
        if (\App\Models\SystemSetting::get(MadadgarService::SETTING_KEY_ENC)) {
            $keySource = 'admin';
        } elseif (trim((string) config('services.openai.key')) !== '') {
            $keySource = 'env';
        }

        // Answer-source totals (owner: "kharcha bachy" — show the saving).
        // Assistant rows only; escalation-confirm rows excluded; legacy rows
        // (pre-source column) were all OpenAI-era.
        $sourceStats = ['local' => 0, 'cache' => 0, 'openai' => 0, 'fallback' => 0];
        try {
            $rows = MadadgarMessage::where('role', 'assistant')
                ->whereNull('escalation_id')
                ->selectRaw("COALESCE(source, 'openai') as src, COUNT(*) as c")
                ->groupBy('src')
                ->pluck('c', 'src');
            foreach ($rows as $src => $c) {
                if (array_key_exists($src, $sourceStats)) {
                    $sourceStats[$src] = (int) $c;
                }
            }
        } catch (\Throwable $e) {
            // source column not migrated yet — keep zeros, page must render.
        }

        return view('admin.madadgar-chats', [
            'sessions' => $sessions,
            'companies' => $companies,
            'users' => $users,
            'activeSession' => $activeSession,
            'activeMessages' => $activeMessages,
            'botEnabled' => \App\Models\SystemSetting::get(MadadgarService::SETTING_ENABLED, '1') === '1',
            'keySource' => $keySource,
            'botLive' => MadadgarService::enabled(),
            'botMode' => MadadgarService::mode(),
            'sourceStats' => $sourceStats,
        ]);
    }

    public function adminSettings(Request $request)
    {
        $request->validate([
            'enabled' => 'required|in:0,1',
            'mode' => 'required|in:hybrid,local,openai',
            'api_key' => 'nullable|string|max:300',
            'clear_key' => 'nullable|in:1',
        ]);

        \App\Models\SystemSetting::set(MadadgarService::SETTING_ENABLED, $request->enabled, 'Madadgar AI bot master switch');
        \App\Models\SystemSetting::set(MadadgarService::SETTING_MODE, $request->mode, 'Madadgar bot mode (hybrid/local/openai)');

        if ($request->input('clear_key') === '1') {
            \App\Models\SystemSetting::where('key', MadadgarService::SETTING_KEY_ENC)->delete();
        } elseif ($request->filled('api_key')) {
            \App\Models\SystemSetting::set(
                MadadgarService::SETTING_KEY_ENC,
                \Illuminate\Support\Facades\Crypt::encryptString(trim($request->api_key)),
                'Madadgar OpenAI API key (encrypted)'
            );
        }

        return redirect()->route('admin.madadgar-chats')->with('success', 'Madadgar settings saved.');
    }

    private function sessionId(Request $request): string
    {
        $sid = (string) $request->input('session_id', $request->query('session_id', ''));
        if (!preg_match('/^[0-9a-fA-F-]{36}$/', $sid)) {
            abort(422, 'Invalid session');
        }

        return strtolower($sid);
    }

    private function todayCount(int $userId): int
    {
        // Fallback turns (bot had no answer) don't count against the cap —
        // the row is kept only so the admin can see missed questions.
        return MadadgarMessage::where('user_id', $userId)
            ->where('role', 'user')
            ->whereDate('created_at', now()->toDateString())
            ->where(function ($q) {
                $q->whereNull('source')->orWhere('source', '!=', 'fallback');
            })
            ->count();
    }
}
