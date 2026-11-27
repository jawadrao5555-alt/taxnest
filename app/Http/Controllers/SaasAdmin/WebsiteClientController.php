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
    public function index(Request $request)
    {
        AdminUser::requireReleaseAdministrator();

        $search = trim($request->validate(['q' => ['nullable', 'string', 'max:120']])['q'] ?? '');
        $entries = WebsiteClient::query()->select('id', 'display_name', 'product', 'logo_mime', 'revision', 'published_at')
            ->latest('id')->paginate(25)->withQueryString();

        return view('saas-admin.website-clients.index', [
            'entries' => $entries,
            'publicIds' => WebsiteClient::query()->published()->whereKey($entries->modelKeys())->pluck('id')->all(),
            'search' => $search,
            'candidates' => WebsiteClient::companySources()
                ->whereNotIn('companies.id', WebsiteClient::query()->select('company_id')->whereNotNull('company_id'))
                ->when($search !== '', fn ($query) => $query->where(fn ($matches) => $matches
                    ->where('companies.name', 'like', '%'.$search.'%')->orWhere('companies.account_code', $search)))
                ->latest('companies.id')->paginate(15, ['*'], 'candidates_page')->withQueryString(),
        ]);
    }

    public function edit(Request $request, ?int $client = null)
    {
        AdminUser::requireReleaseAdministrator();

        $entry = $client === null ? new WebsiteClient : WebsiteClient::findOrFail($client);
        $source = $entry->company_id ? WebsiteClient::companySources()->find($entry->company_id) : null;

        if (!$entry->exists && $request->filled('company')) {
            $companyId = $request->validate(['company' => ['required', 'integer', 'min:1']])['company'];
            $source = WebsiteClient::companySources()->findOrFail($companyId);
            $existing = WebsiteClient::query()->where('company_id', $source->id)->first();
            if ($existing) {
                return redirect()->route('saas.admin.website-clients.edit', $existing->id);
            }

            $entry->fill(['display_name' => mb_substr($source->name, 0, 120), 'product' => $source->website_product]);
        }

        return view('saas-admin.website-clients.edit', [
            'entry' => $entry,
            'source' => $source,
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
            'company_id' => $client === null ? ['nullable', 'integer', 'min:1'] : ['prohibited'],
            'display_name' => ['required', 'string', 'max:120'],
            'product' => ['required', Rule::in(array_keys(WebsiteClient::PRODUCTS))],
            'logo' => ['nullable', 'file', 'mimetypes:image/png,image/jpeg,image/webp', 'max:256', 'dimensions:max_width=1200,max_height=1200'],
            'remove_logo' => ['sometimes', 'boolean'],
            'revision' => [$client === null ? 'nullable' : 'required', 'integer', 'min:1'],
        ]);

        DB::transaction(function () use ($request, $validated, $client, $actor) {
            $entry = $client === null ? new WebsiteClient : WebsiteClient::lockForUpdate()->findOrFail($client);
            $companyId = $entry->company_id ?? $validated['company_id'] ?? null;
            if ($companyId !== null) {
                $this->checkCompanyProduct((int) $companyId, $validated['product']);
                if (!$entry->exists && WebsiteClient::query()->where('company_id', $companyId)->exists()) {
                    throw ValidationException::withMessages(['company_id' => 'This company already has a website client entry. Review its saved entry.']);
                }
                $entry->company_id = $companyId;
            }

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
        }, 3);

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
            if ($publish && $entry->company_id !== null) {
                $this->checkCompanyProduct((int) $entry->company_id, $entry->product);
            }
            if ($publish !== ($entry->published_at !== null)) {
                $entry->published_at = $publish ? now() : null;
                $entry->revision++;
                $entry->save();
                AdminAuditLog::log($actor->id, $publish ? 'website_client_published' : 'website_client_unpublished', 'website_client', $entry->id);
            }
        }, 3);

        return redirect()->route('saas.admin.website-clients.index')->with('success', $publish ? 'Client published.' : 'Client unpublished.');
    }

    private function checkRevision(WebsiteClient $entry, int $revision): void
    {
        if ((int) $entry->revision !== $revision) {
            throw ValidationException::withMessages(['revision' => 'This entry changed. Review the latest details before saving.']);
        }
    }

    private function checkCompanyProduct(int $companyId, string $product): void
    {
        $source = WebsiteClient::companySources()->lockForUpdate()->find($companyId);
        if (!$source) {
            throw ValidationException::withMessages(['company_id' => 'The company is no longer eligible for a website client entry.']);
        }
        if ($source->website_product !== $product) {
            throw ValidationException::withMessages(['product' => 'The company product has changed. Review the current product and save the draft again.']);
        }
    }
}
