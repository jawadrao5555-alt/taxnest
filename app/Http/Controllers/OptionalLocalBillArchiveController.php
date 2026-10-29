<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Services\BranchContextService;
use App\Services\OptionalLocalBillArchive;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Separate, read-only support copies; never part of live sales or fiscal submission. */
class OptionalLocalBillArchiveController extends Controller
{
    private function ready(): void
    {
        abort_unless(Schema::hasTable('support_bill_archive_prefs'), 503, 'Optional support copies are not available yet.');
    }

    private function ownerContext(Request $request, OptionalLocalBillArchive $archive): array
    {
        $product = $request->is('fbr-pos/*') ? 'fbrpos' : 'pos';
        $user = auth($product)->user();
        abort_unless($user && $user->company_id, 403);
        // Both admin sessions and explicit impersonation are ineligible, including full-access mode.
        abort_if(auth('admin')->check() || $request->session()->has('impersonation'), 403);
        $company = Company::where('product_type', $product)->findOrFail($user->company_id);
        $archive->assertOwner($user, (int) $company->id);
        $this->ready();
        return [$company, $product, $user];
    }

    private function adminContext(int $companyId): array
    {
        abort_unless(auth('admin')->user()?->isSuperAdmin(), 403);
        $company = Company::whereIn('product_type', ['pos', 'fbrpos'])->findOrFail($companyId);
        $this->ready();
        return [$company, $company->product_type, auth('admin')->user()];
    }

    private function scopedCopies(OptionalLocalBillArchive $archive, int $companyId, string $product, bool $owner)
    {
        $query = $archive->available($companyId)->where('product', $product);
        if ($owner) {
            app(BranchContextService::class)->applyToQuery($query, 'branch_id');
        }
        return $query;
    }

    private function pageData(Company $company, string $product, bool $owner, OptionalLocalBillArchive $archive): array
    {
        $preference = DB::table('support_bill_archive_prefs')->where('company_id', $company->id)->first();
        $grant = $preference?->grant_id
            ? DB::table('support_bill_archive_consents')->where('company_id', $company->id)->where('id', $preference->grant_id)->first()
            : null;
        return [
            'company' => $company, 'product' => $product, 'ownerCanChange' => $owner,
            'preference' => $preference, 'grant' => $grant,
            'enabled' => (bool) ($preference?->enabled && $grant && Carbon::parse($grant->valid_until)->isFuture()),
            'copies' => $this->scopedCopies($archive, (int) $company->id, $product, $owner)->orderByDesc('id')->paginate(25),
            'consents' => DB::table('support_bill_archive_consents')->where('company_id', $company->id)->orderByDesc('id')->limit(10)->get(),
        ];
    }

    public function index(Request $request, OptionalLocalBillArchive $archive)
    {
        [$company, $product, $user] = $this->ownerContext($request, $archive);
        $archive->audit((int) $company->id, (int) $user->id, $product, 'list', null);
        return response()->view(($product === 'pos' ? 'pos' : 'fbr-pos').'.optional-local-bill-archive',
            $this->pageData($company, $product, true, $archive))->header('Cache-Control', 'private, no-store');
    }

    public function update(Request $request, OptionalLocalBillArchive $archive)
    {
        [$company, $product, $user] = $this->ownerContext($request, $archive);
        $values = $request->validate([
            'action' => 'required|in:grant,stop,purge', 'confirm' => 'required|accepted',
            'retention_days' => 'required_if:action,grant|nullable|integer|min:1|max:365',
            'valid_until' => 'required_if:action,grant|nullable|date_format:Y-m-d',
        ]);
        try {
            if ($values['action'] === 'grant') {
                $archive->grant((int) $company->id, $user, (int) $values['retention_days'], Carbon::parse($values['valid_until'])->endOfDay());
                $message = 'Future eligible local-bill deletions will keep a support copy for your chosen period. Existing bills were not copied.';
            } elseif ($values['action'] === 'stop') {
                $archive->stop((int) $company->id, $user);
                $message = 'Future support copies stopped. Existing copies keep their original expiry.';
            } else {
                $archive->purge((int) $company->id, $user);
                $message = 'Future support copies stopped and existing optional copies purged. Mandatory financial records were not changed.';
            }
        } catch (\RuntimeException $e) {
            return back()->withInput()->withErrors(['archive' => 'Archive choice could not be completed. Please retry. Existing live bills and billing settings were not changed.']);
        }
        return redirect()->route($product.'.local-support-copies')->with('success', $message);
    }

    private function downloadCopy(OptionalLocalBillArchive $archive, Company $company, string $product, $user, bool $owner, int $id)
    {
        $copy = $this->scopedCopies($archive, (int) $company->id, $product, $owner)->where('id', $id)->first();
        abort_unless($copy, 404);
        $guard = $owner ? $product : 'admin';
        $archive->audit((int) $company->id, (int) $user->id, $guard, 'export_attempt', $id);
        try {
            $payload = $archive->readPayload($copy);
        } catch (\RuntimeException $e) {
            $archive->audit((int) $company->id, (int) $user->id, $guard, 'unavailable', $id);
            return response()->json(['error' => 'This optional support copy is no longer available.'], 410)->header('Cache-Control', 'private, no-store');
        }
        $archive->audit((int) $company->id, (int) $user->id, $guard, 'export', $id);
        return response()->json($payload, 200, [
            'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff',
            'Content-Disposition' => 'attachment; filename="taxnest-support-copy-'.$product.'-'.$company->id.'-'.$id.'.json"',
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    }

    public function download(Request $request, int $id, OptionalLocalBillArchive $archive)
    {
        [$company, $product, $user] = $this->ownerContext($request, $archive);
        return $this->downloadCopy($archive, $company, $product, $user, true, $id);
    }

    public function adminIndex(int $companyId, OptionalLocalBillArchive $archive)
    {
        [$company, $product, $user] = $this->adminContext($companyId);
        $archive->audit((int) $company->id, (int) $user->id, 'admin', 'list', null);
        return response()->view('saas-admin.optional-local-bill-archive',
            $this->pageData($company, $product, false, $archive))->header('Cache-Control', 'private, no-store');
    }

    public function adminDownload(int $companyId, int $id, OptionalLocalBillArchive $archive)
    {
        [$company, $product, $user] = $this->adminContext($companyId);
        return $this->downloadCopy($archive, $company, $product, $user, false, $id);
    }
}
