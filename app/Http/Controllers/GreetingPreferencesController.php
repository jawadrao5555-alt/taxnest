<?php

namespace App\Http\Controllers;

use App\Services\CompanyGreetingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class GreetingPreferencesController extends Controller
{
    private function owner(Request $request): int
    {
        $actor = $request->user();
        $current = $actor ? \App\Models\User::find($actor->id) : null;
        // Generic auth/session-generation middleware does not refresh role or membership.
        abort_unless($current && $current->is_active && $current->role === 'company_admin'
            && $current->company_id && (int) $current->company_id === (int) $actor->company_id
            && DB::table('companies')->where('id', $current->company_id)->exists(), 403);

        return (int) $current->company_id;
    }

    public function index(Request $request)
    {
        $companyId = $this->owner($request);

        return response()->view('greetings.preferences', ['preferences' => DB::table('greeting_preferences')->where('company_id', $companyId)->first(), 'recipients' => DB::table('greeting_recipients')->where('company_id', $companyId)->get(), 'deliveries' => DB::table('greeting_deliveries')->where('company_id', $companyId)->latest('id')->limit(50)->get()])->header('Cache-Control', 'private, no-store');
    }

    public function save(Request $request)
    {
        $id = $this->owner($request);
        $data = $request->validate(['enabled' => 'required|boolean', 'timezone' => 'required|timezone', 'templates' => 'required|array']);
        $templates = CompanyGreetingService::validateTemplates($data['templates']);
        DB::table('greeting_preferences')->updateOrInsert(['company_id' => $id], ['enabled' => $data['enabled'], 'timezone' => $data['timezone'], 'templates' => json_encode($templates), 'updated_by' => $request->user()->id, 'updated_at' => now(), 'created_at' => now()]);

        return back()->with('success', 'Greeting preferences saved. Provider release and recipient consent remain required.');
    }

    public function recipient(Request $request)
    {
        $id = $this->owner($request);
        $data = $request->validate(['id' => 'nullable|integer', 'name' => 'required|string|max:120', 'email' => 'required|email|max:255', 'event' => 'required|in:welcome,birthday,anniversary', 'event_date' => 'required|date_format:Y-m-d', 'email_consent' => 'required|boolean']);
        $recipientId = $data['id'] ?? null;
        unset($data['id']);
        $data += ['recorded_by' => $request->user()->id, 'consented_at' => $data['email_consent'] ? now() : null, 'updated_at' => now()];
        if ($recipientId) {
            abort_unless(DB::table('greeting_recipients')->where('company_id', $id)->where('id', $recipientId)->exists(), 404);
            DB::table('greeting_recipients')->where('company_id', $id)->where('id', $recipientId)->update($data);
        } else {
            DB::table('greeting_recipients')->insert($data + ['company_id' => $id, 'created_at' => now()]);
        }

        return back();
    }

    public function preview(Request $request)
    {
        $this->owner($request);
        $data = $request->validate(['template' => 'required|string|max:500']);
        CompanyGreetingService::validateTemplates(['welcome' => $data['template']]);

        return response()->json(['preview' => CompanyGreetingService::render($data['template'], 'Example recipient', 'Example company'), 'sent' => false]);
    }
}
