<?php

namespace App\Http\Controllers\SaasAdmin;

use App\Http\Controllers\Controller;
use App\Models\AdminAuditLog;
use App\Models\Company;
use App\Models\CompanyGroup;
use App\Models\CompanyGroupMember;
use App\Models\CompanyIdentityKey;
use App\Models\Subscription;
use App\Services\CompanyGroupService;
use App\Support\ProductCatalog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Business Groups — the admin-only view of "these accounts are one customer".
 *
 * One NTN can be registered with both FBR and PRA, so the same businessman
 * legitimately runs a hotel on PRA POS, a Tier-1 outlet on FBR POS and a
 * distribution house on Digital Invoice. Since 5 Sep 2026 those are three
 * fully separate accounts (own login, own subscription, own data) and nothing
 * here is exposed to him — this section exists so WE can see the whole
 * customer: support gets the full picture, sales sees the product he does not
 * have yet, and repeat free trials from one person stop being invisible.
 *
 * Membership is derived automatically from shared identity. CNIC/NTN are
 * strong evidence; email/phone are weak (an accountant's email can sit on two
 * unrelated businesses), so every row carries its reason and can be detached.
 */
class AdminGroupController extends Controller
{
    /** Products a group can be sold; anything missing is a cross-sell hint. */
    private const SELLABLE = [ProductCatalog::POS, ProductCatalog::FBRPOS, ProductCatalog::DI];

    public function index(Request $request)
    {
        if (!CompanyGroupService::enabled()) {
            return view('saas-admin.groups.index', [
                'groups' => collect(), 'cards' => [], 'q' => '', 'disabled' => true, 'stats' => [],
            ]);
        }

        $q = trim((string) $request->query('q', ''));

        $query = CompanyGroup::query()->withCount('members');

        if ($q !== '') {
            // Search reaches the members too: a group is found by its own code
            // or by ANY account inside it — name, account code, NTN, CNIC,
            // email or phone (normalised, so 0300-1234567 finds 3001234567).
            $digits = preg_replace('/\D+/', '', $q);
            $companyIds = Company::withTrashed()
                ->where(function ($w) use ($q, $digits) {
                    $w->where('name', 'like', "%{$q}%")
                      ->orWhere('owner_name', 'like', "%{$q}%")
                      ->orWhere('email', 'like', "%{$q}%")
                      ->orWhere('ntn', 'like', "%{$q}%");
                    if (\Illuminate\Support\Facades\Schema::hasColumn('companies', 'account_code')) {
                        $w->orWhere('account_code', 'like', "%{$q}%");
                    }
                    if ($digits !== '') {
                        $w->orWhere('cnic', 'like', "%{$digits}%")
                          ->orWhere('phone', 'like', "%{$digits}%")
                          ->orWhere('mobile', 'like', "%{$digits}%");
                    }
                })
                ->pluck('id');

            $keyIds = CompanyIdentityKey::where('key_value', 'like', '%' . ($digits !== '' ? $digits : $q) . '%')
                ->pluck('company_id');

            $groupIds = CompanyGroupMember::whereIn('company_id', $companyIds->merge($keyIds)->unique())
                ->pluck('company_group_id')
                ->unique();

            $query->where(function ($w) use ($q, $groupIds) {
                $w->where('code', 'like', "%{$q}%")
                  ->orWhere('name', 'like', "%{$q}%")
                  ->orWhereIn('id', $groupIds);
            });
        }

        $groups = $query->orderByDesc('id')->paginate(25)->withQueryString();

        $cards = $this->summarise($groups->getCollection());

        return view('saas-admin.groups.index', [
            'groups'   => $groups,
            'cards'    => $cards,
            'q'        => $q,
            'disabled' => false,
            'stats'    => $this->stats(),
        ]);
    }

    public function show($id)
    {
        abort_unless(CompanyGroupService::enabled(), 404);

        $group = CompanyGroup::with(['members.company'])->findOrFail($id);
        $cards = $this->summarise(collect([$group]));

        $excluded = DB::table('company_group_exclusions')
            ->where('company_group_id', $group->id)
            ->pluck('company_id');
        $excludedCompanies = $excluded->isEmpty()
            ? collect()
            : Company::withTrashed()->whereIn('id', $excluded)->get();

        return view('saas-admin.groups.show', [
            'group'             => $group,
            'card'              => $cards[$group->id] ?? null,
            'excludedCompanies' => $excludedCompanies,
        ]);
    }

    public function update(Request $request, $id)
    {
        $group = CompanyGroup::findOrFail($id);
        $request->validate([
            'name'  => 'nullable|string|max:190',
            'notes' => 'nullable|string|max:2000',
        ]);

        $group->update([
            'name'  => $request->input('name') ?: null,
            'notes' => $request->input('notes') ?: null,
        ]);

        AdminAuditLog::log(auth('admin')->id(), 'Business group updated', 'CompanyGroup', $group->id, [
            'code' => $group->code,
        ]);

        return back()->with('success', 'Group updated.');
    }

    /**
     * Detach one account. The decision is remembered, so the automatic pass
     * never silently puts it back — a weak email match on an accountant's
     * address must stay broken once an admin says these are different people.
     */
    public function detach(Request $request, $id, $companyId)
    {
        $group = CompanyGroup::findOrFail($id);
        $company = Company::withTrashed()->findOrFail($companyId);

        abort_unless(
            CompanyGroupMember::where('company_group_id', $group->id)->where('company_id', $company->id)->exists(),
            404
        );

        CompanyGroupService::detach($company);

        AdminAuditLog::log(auth('admin')->id(), 'Company detached from group', 'CompanyGroup', $group->id, [
            'code' => $group->code, 'company_id' => $company->id, 'company' => $company->name,
        ]);

        // The group may have dissolved (a group of one is noise).
        return CompanyGroup::find($group->id)
            ? back()->with('success', "{$company->name} detached from {$group->code}.")
            : redirect()->route('saas.admin.groups')->with('success', "{$company->name} detached; {$group->code} had nobody left and was dissolved.");
    }

    /** Link an account an admin knows belongs here (account code / NTN / name). */
    public function link(Request $request, $id)
    {
        $group = CompanyGroup::findOrFail($id);
        $request->validate(['company_ref' => 'required|string|max:190']);

        $ref = trim($request->input('company_ref'));
        $company = $this->resolveCompany($ref);

        if (!$company) {
            return back()->with('error', "No account matched \"{$ref}\". Try the account code (PRA-00026), the NTN, or the exact company name.");
        }

        $existing = CompanyGroupMember::where('company_id', $company->id)->first();
        if ($existing && $existing->company_group_id !== $group->id) {
            $other = CompanyGroup::find($existing->company_group_id);

            return back()->with('error', "{$company->name} already belongs to {$other?->code}. Detach it there first.");
        }

        CompanyGroupService::attach($group, $company, 'manual', null, true);

        AdminAuditLog::log(auth('admin')->id(), 'Company linked to group', 'CompanyGroup', $group->id, [
            'code' => $group->code, 'company_id' => $company->id, 'company' => $company->name,
        ]);

        return back()->with('success', "{$company->name} linked to {$group->code}.");
    }

    /** Re-run the automatic pass (after a data clean-up, or a manual detach spree). */
    public function resync(Request $request)
    {
        abort_unless(CompanyGroupService::enabled(), 404);
        @set_time_limit(300);

        $count = CompanyGroupService::rebuild();

        AdminAuditLog::log(auth('admin')->id(), 'Business groups re-synced', 'CompanyGroup', null, [
            'companies' => $count,
        ]);

        return back()->with('success', "Re-checked {$count} accounts for group matches.");
    }

    // ------------------------------------------------------------------ util

    private function resolveCompany(string $ref): ?Company
    {
        $query = Company::query();

        if (\Illuminate\Support\Facades\Schema::hasColumn('companies', 'account_code')) {
            $byCode = (clone $query)->where('account_code', $ref)->first();
            if ($byCode) {
                return $byCode;
            }
        }

        if (ctype_digit($ref)) {
            $byId = Company::find((int) $ref);
            if ($byId) {
                return $byId;
            }
        }

        return Company::where('ntn', $ref)->first()
            ?? Company::where('name', $ref)->first()
            ?? Company::where('name', 'like', "%{$ref}%")->orderBy('id')->first();
    }

    /**
     * Per-group summary used by both screens: which products the group already
     * runs, which are still missing (cross-sell), and whether it smells like
     * repeated free trials.
     *
     * @param  \Illuminate\Support\Collection<int,CompanyGroup>  $groups
     * @return array<int,array<string,mixed>>
     */
    private function summarise($groups): array
    {
        if ($groups->isEmpty()) {
            return [];
        }

        // A plain Collection (the show page passes one group) has no
        // loadMissing — eager-load per model so both callers work.
        foreach ($groups as $group) {
            $group->loadMissing('members.company');
        }

        $companyIds = $groups->flatMap(fn ($g) => $g->members->pluck('company_id'))->unique()->values();
        $subs = Subscription::whereIn('company_id', $companyIds)
            ->where('active', true)
            ->get()
            ->keyBy('company_id');

        $cards = [];
        foreach ($groups as $group) {
            $products = [];
            $trials = 0;
            $duplicateProducts = [];
            $latest = null;

            foreach ($group->members as $member) {
                $company = $member->company;
                if (!$company) {
                    continue;
                }
                $type = ProductCatalog::normalize($company->product_type) ?: ProductCatalog::DI;
                $products[$type] = ($products[$type] ?? 0) + 1;

                $sub = $subs->get($company->id);
                if ($sub && $sub->trial_ends_at) {
                    $trials++;
                }

                $latest = $latest === null || $company->created_at > $latest ? $company->created_at : $latest;
            }

            foreach ($products as $type => $count) {
                if ($count > 1) {
                    $duplicateProducts[] = $type;
                }
            }

            $missing = array_values(array_diff(self::SELLABLE, array_keys($products)));

            $cards[$group->id] = [
                'products'       => $products,
                'missing'        => $missing,
                'trials'         => $trials,
                'duplicates'     => $duplicateProducts,
                // Two accounts of the SAME product under one identity, or more
                // than one live trial at once, is what an abuser looks like.
                'trial_abuse'    => $duplicateProducts !== [] || $trials > 1,
                'last_signup'    => $latest,
            ];
        }

        return $cards;
    }

    private function stats(): array
    {
        $memberCounts = CompanyGroupMember::select('company_group_id', DB::raw('COUNT(*) as c'))
            ->groupBy('company_group_id')
            ->pluck('c', 'company_group_id');

        return [
            'groups'      => $memberCounts->count(),
            'accounts'    => (int) $memberCounts->sum(),
            'biggest'     => (int) ($memberCounts->max() ?? 0),
            'multi'       => $memberCounts->filter(fn ($c) => $c > 2)->count(),
        ];
    }
}
