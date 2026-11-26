<?php

namespace App\Http\Controllers\SaasAdmin;

use App\Http\Controllers\Controller;
use App\Http\Controllers\WebsiteClientLogoController;
use App\Models\AdminAuditLog;
use App\Models\AdminUser;
use App\Models\WebsiteClient;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class WebsiteClientController extends Controller
{
    public function index()
    {
        AdminUser::requireReleaseAdministrator();

        return view('saas-admin.website-clients.index', [
            'entries' => WebsiteClient::query()->select('id', 'display_name', 'product', 'logo_mime', 'revision', 'published_at')
                ->latest('id')->paginate(25),
        ]);
    }

    public function edit(?int $client = null)
    {
        AdminUser::requireReleaseAdministrator();

        return view('saas-admin.website-clients.edit', [
            'entry' => $client === null ? new WebsiteClient : WebsiteClient::findOrFail($client),
        ]);
    }

    public function logo(int $client, int $revision)
    {
        AdminUser::requireReleaseAdministrator();
        $entry = WebsiteClient::query()->whereKey($client)->where('revision', $revision)
            ->whereNotNull('logo_data')->firstOrFail();

        return WebsiteClientLogoController::imageResponse($entry);
    }

    public function save(Request $request, ?int $client = null)
    {
        $actor = AdminUser::requireReleaseAdministrator();
        $validated = $request->validate([
            'display_name' => ['required', 'string', 'max:120'],
            'product' => ['required', Rule::in(array_keys(WebsiteClient::PRODUCTS))],
            'logo' => ['nullable', 'file', 'mimetypes:image/png,image/jpeg,image/webp', 'max:256', 'dimensions:max_width=1200,max_height=1200'],
            'remove_logo' => ['sometimes', 'boolean'],
            'revision' => [$client === null ? 'nullable' : 'required', 'integer', 'min:1'],
        ]);

        DB::transaction(function () use ($request, $validated, $client, $actor) {
            $entry = $client === null ? new WebsiteClient : WebsiteClient::lockForUpdate()->findOrFail($client);
            if ($entry->exists) {
                $this->checkRevision($entry, (int) $validated['revision']);
                $entry->revision++;
            }
            $entry->fill(Arr::only($validated, ['display_name', 'product']));
            if ($file = $request->file('logo')) {
                $entry->logo_data = base64_encode($file->get());
                $entry->logo_mime = $file->getMimeType();
            } elseif ($request->boolean('remove_logo')) {
                $entry->logo_data = $entry->logo_mime = null;
            }
            $entry->published_at = null;
            $entry->save();

            AdminAuditLog::log($actor->id, 'website_client_draft_saved', 'website_client', $entry->id);
        });

        return redirect()->route('saas.admin.website-clients.index')->with('success', 'Client saved as a draft.');
    }

    public function visibility(Request $request, int $client)
    {
        $actor = AdminUser::requireReleaseAdministrator();
        $validated = $request->validate([
            'revision' => ['required', 'integer', 'min:1'],
            'action' => ['required', Rule::in(['publish', 'unpublish'])],
        ]);
        $publish = $validated['action'] === 'publish';
        if ($publish) {
            $request->validate(['consent' => ['accepted'], 'live_use' => ['accepted']]);
        }

        DB::transaction(function () use ($client, $validated, $publish, $actor) {
            $entry = WebsiteClient::lockForUpdate()->findOrFail($client);
            $this->checkRevision($entry, (int) $validated['revision']);
            if ($publish !== ($entry->published_at !== null)) {
                $entry->published_at = $publish ? now() : null;
                $entry->revision++;
                $entry->save();
                AdminAuditLog::log($actor->id, $publish ? 'website_client_published' : 'website_client_unpublished', 'website_client', $entry->id);
            }
        });

        return redirect()->route('saas.admin.website-clients.index')->with('success', $publish ? 'Client published.' : 'Client unpublished.');
    }

    private function checkRevision(WebsiteClient $entry, int $revision): void
    {
        if ((int) $entry->revision !== $revision) {
            throw ValidationException::withMessages(['revision' => 'This entry changed. Review the latest details before saving.']);
        }
    }
}
