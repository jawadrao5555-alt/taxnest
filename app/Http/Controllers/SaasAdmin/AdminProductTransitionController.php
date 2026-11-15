<?php
namespace App\Http\Controllers\SaasAdmin;

use App\Http\Controllers\Controller;
use App\Models\{Company, User, AdminAuditLog};
use App\Services\CredentialLedgerService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{DB, Hash};

/** Product compatibility only. No credential revelation, deletion or access grants. */
final class AdminProductTransitionController extends Controller
{
    public function productReview(Request $request, $id)
    {
        \App\Models\AdminUser::requireSuperAdministrator();
        $data = $request->validate(['product_type' => 'nullable|' . \App\Support\ProductCatalog::validationRule(),
            'products' => 'nullable|array|min:1', 'products.*' => 'required|' . \App\Support\ProductCatalog::validationRule()]);
        $company = Company::findOrFail($id);
        $cloning = !empty($data['products']);
        $targets = array_values(array_unique(array_map(fn ($type) => \App\Support\ProductCatalog::normalize($type) ?? $type,
            $cloning ? $data['products'] : [($data['product_type'] ?? '')])));
        abort_if(in_array('', $targets, true), 422, 'Select a target product.');
        return response()->view('saas-admin.companies.product-review', compact('company', 'targets', 'cloning'))->header('Cache-Control', 'private, no-store');
    }

    public function changeProductType(Request $request, $id)
    {
        \App\Models\AdminUser::requireSuperAdministrator();
        if (!$request->filled('target_package')) {
            $request->validate(['product_type' => 'required|' . \App\Support\ProductCatalog::validationRule()]);
            return redirect()->route('saas.admin.companies.productReview', ['id' => $id, 'product_type' => $request->product_type]);
        }
        $request->validate(['product_type' => 'required|' . \App\Support\ProductCatalog::validationRule(),
            'target_category' => 'nullable|string|max:50', 'target_package' => 'required|string|max:255']);
        $company = Company::findOrFail($id);
        $old = $company->product_type;
        $new = \App\Support\ProductCatalog::normalize($request->product_type) ?? $request->product_type;
        abort_if($new === $old, 422, 'Use the dedicated category controls for this product.');
        $target = \App\Services\ProductTransitionCompatibility::target($new, $request->target_category, $request->target_package);
        DB::transaction(function () use ($company, $new, $target, $request) {
            $locked = Company::whereKey($company->id)->lockForUpdate()->firstOrFail();
            \App\Services\ProductTransitionCompatibility::assertPristine($locked);
            foreach (User::where('company_id', $company->id)->get() as $user) {
                $other = \App\Support\IdentityScope::findUserByEmail($user->email, $new);
                if ($other && $other->company_id !== $company->id) throw \Illuminate\Validation\ValidationException::withMessages(['product_type' => 'Owner or team identity already exists in target product. Use the separate existing account.']);
            }
            $changes = \App\Services\ProductTransitionCompatibility::attributes($new, $target);
            if (\App\Support\NestErps::isProductType($new) && \Illuminate\Support\Facades\Schema::hasColumn('companies', \App\Support\NestErps::VERTICAL_COLUMN)) {
                $changes[\App\Support\NestErps::VERTICAL_COLUMN] = \App\Support\NestErps::normalizeVertical($request->input('erps_vertical'));
            }
            $locked->update($changes);
            if (\Illuminate\Support\Facades\Schema::hasColumn('users', 'product_type')) User::where('company_id', $company->id)->update(['product_type' => $new]);
        });
        AdminAuditLog::log(auth('admin')->id(), 'Company type changed', 'Company', $id, ['name' => $company->name, 'from' => $old, 'to' => $new]);
        return back()->with('success', 'Product changed after compatibility validation. No paid entitlement or fiscal reporting was enabled.');
    }

    /**
     * Clone this customer onto ANOTHER product line (owner ruling, 5 Sep 2026).
     *
     * "Change type" moves one account; this one keeps it and opens a sibling.
     * The same businessman legitimately runs a hotel on PRA POS, a Tier-1
     * outlet on FBR POS and a distribution house on Digital Invoice, so the
     * profile is copied, each sibling gets its OWN trial and subscription, and
     * grouping ties them together automatically (shared CNIC/NTN/email).
     *
     * Only the OWNER account is cloned — team accounts are not, because each
     * product has its own staff and the owner creates them there himself.
     */
    public function cloneToProduct(Request $request, $id)
    {
        \App\Models\AdminUser::requireSuperAdministrator();
        $source = Company::findOrFail($id);

        $request->validate([
            'products'       => 'required|array|min:1',
            'products.*'     => 'required|' . \App\Support\ProductCatalog::validationRule(),
            'admin_password' => ['nullable', 'string', \Illuminate\Validation\Rules\Password::defaults()],
            'erps_vertical'  => 'nullable|string|max:50',
        ]);

        foreach (array_unique($request->input('products', [])) as $rawTarget) {
            $targetType = \App\Support\ProductCatalog::normalize($rawTarget) ?? $rawTarget;
            if (!$request->filled('target_packages.'.$targetType)) {
                return redirect()->route('saas.admin.companies.productReview', ['id' => $id, 'products' => $request->input('products')]);
            }
        }

        foreach (array_unique($request->input('products', [])) as $rawTarget) {
            $targetType = \App\Support\ProductCatalog::normalize($rawTarget) ?? $rawTarget;
            \App\Services\ProductTransitionCompatibility::target($targetType, $request->input('target_categories.'.$targetType), $request->input('target_packages.'.$targetType));
        }

        $owner = User::where('company_id', $source->id)
            ->whereIn('role', ['company_admin', 'admin', 'owner'])
            ->orderBy('id')
            ->first()
            ?? User::where('company_id', $source->id)->orderBy('id')->first();

        if (!$owner) {
            return back()->with('error', 'This company has no owner account to clone.');
        }

        $sourceType = \App\Support\ProductCatalog::normalize($source->product_type);
        $created = [];
        $skipped = [];

        foreach (array_unique($request->input('products', [])) as $raw) {
            $target = \App\Support\ProductCatalog::normalize($raw) ?? $raw;

            if ($target === $sourceType) {
                $skipped[] = \App\Support\ProductCatalog::label($target) . ' (already this product)';
                continue;
            }

            $existing = $this->existingSibling($source, $owner, $target);
            if ($existing) {
                $skipped[] = \App\Support\ProductCatalog::label($target) . " (already exists: {$existing->name})";
                continue;
            }

            // Each product is its own all-or-nothing unit: if one panel's
            // columns reject the copy, the half-built account is rolled back
            // and the other products still get created, instead of a 500 that
            // leaves an orphan company with no owner login behind.
            try {
                $clone = DB::transaction(fn () => $this->createSibling($source, $owner, $target, $request));
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::error('Company clone failed', [
                    'company' => $source->id, 'target' => $target, 'error' => $e->getMessage(),
                ]);
                $skipped[] = \App\Support\ProductCatalog::label($target) . ' (failed: ' . $e->getMessage() . ')';
                continue;
            }

            $created[] = $clone;

            AdminAuditLog::log(auth('admin')->id(), 'Company cloned to product', 'Company', $clone->id, [
                'from_company' => $source->id,
                'from_type'    => $sourceType,
                'to_type'      => $target,
                'name'         => $clone->name,
            ]);
        }

        $message = $created
            ? 'Created ' . collect($created)->map(fn ($c) => \App\Support\ProductCatalog::label($c->product_type) . " ({$c->account_code})")->join(', ')
                . '. Each one starts its own 14-day trial; the owner signs in with the same email on that panel.'
            : '';
        if ($skipped) {
            $message = trim($message . ' Skipped: ' . implode(', ', $skipped) . '.');
        }

        if (!$created) {
            return back()->with('error', $message ?: 'Nothing to clone.');
        }

        return count($created) === 1
            ? redirect()->route('saas.admin.companies.show', $created[0]->id)->with('success', $message)
            : back()->with('success', $message);
    }

    /**
     * Is this customer already on that product? Same NTN or CNIC, or the same
     * owner email inside that product line — any of those means a sibling
     * exists and a second one would just be a duplicate account.
     */
    private function existingSibling(Company $source, User $owner, string $target): ?Company
    {
        $stored = \App\Support\NestErps::storedTypesFor($target);

        $byCompany = Company::whereIn('product_type', $stored)
            ->where('id', '!=', $source->id)
            ->where(function ($w) use ($source) {
                $matched = false;
                if (filled($source->ntn)) {
                    $w->orWhere('ntn', $source->ntn);
                    $matched = true;
                }
                if (filled($source->cnic)) {
                    $w->orWhere('cnic', $source->cnic);
                    $matched = true;
                }
                if (!$matched) {
                    $w->whereRaw('1 = 0');
                }
            })
            ->first();
        if ($byCompany) {
            return $byCompany;
        }

        $user = \App\Support\IdentityScope::findUserByEmail($owner->email, $target);

        return $user?->company_id ? Company::find($user->company_id) : null;
    }

    /** Build the sibling company + its single owner account. */
    private function createSibling(Company $source, User $owner, string $target, Request $request): Company
    {
        $data = [
            'name'              => $source->name,
            'owner_name'        => $source->owner_name,
            'product_type'      => $target,
            'email'             => $source->email,
            'ntn'               => $source->ntn,
            'cnic'              => $source->cnic,
            'phone'             => $source->phone,
            'mobile'            => $source->mobile,
            'address'           => $source->address,
            'city'              => $source->city,
            'province'          => $source->province,
            'business_activity' => $source->business_activity,
            'website'           => $source->website,
            'status'            => 'approved',
            'company_status'    => 'active',
            // Same product defaults a fresh signup on that panel would get.
            'standard_tax_rate'     => $target === 'di' ? 18.00 : 16.00,
            'fbr_pos_enabled'       => $target === 'fbrpos',
            'fbr_reporting_enabled' => $target === 'fbrpos',
        ];

        $targetConfig = \App\Services\ProductTransitionCompatibility::target($target, $request->input('target_categories.'.$target), $request->input('target_packages.'.$target));
        $data = array_replace($data, \App\Services\ProductTransitionCompatibility::attributes($target, $targetConfig));

        // Only an FBR POS sibling names an environment: the column is NOT NULL
        // on some deployments, so a non-FBR clone must leave it to its default
        // instead of writing an explicit null.
        if ($target === 'fbrpos') {
            $data['fbr_pos_environment'] = 'sandbox';
        }

        if ($target === 'pos') {
            $data['pra_reporting_enabled'] = false;
            $data['pra_environment'] = 'sandbox';
        }

        if (in_array($target, ['pos', 'fbrpos'], true)) {
            $posType = $targetConfig['category'];
            if (\Illuminate\Support\Facades\Schema::hasColumn('companies', 'pos_type')) {
                $data['pos_type'] = $posType;
            }
            $data += \App\Services\PosFeatureService::registrationAttributes($posType);
        }

        if (\App\Support\NestErps::isProductType($target)) {
            $vertical = \App\Support\NestErps::normalizeVertical($request->input('erps_vertical'));
            if (\Illuminate\Support\Facades\Schema::hasColumn('companies', \App\Support\NestErps::VERTICAL_COLUMN)) {
                $data[\App\Support\NestErps::VERTICAL_COLUMN] = $vertical;
            }
            if ($vertical === \App\Support\NestErps::HEALTH) {
                if (\Illuminate\Support\Facades\Schema::hasColumn('companies', 'health_org_type')) {
                    $data['health_org_type'] = 'clinic';
                }
                if (\Illuminate\Support\Facades\Schema::hasColumn('companies', 'health_modules')) {
                    $data['health_modules'] = \App\Services\HealthModuleService::ORG_TYPE_DEFAULTS['clinic'];
                }
            }
        }

        if (\Illuminate\Support\Facades\Schema::hasColumn('companies', 'agent_id')) {
            $data['agent_id'] = $source->agent_id;
        }

        $clone = Company::create($data);

        // ONE account: the owner. Teams are per product — the owner builds that
        // product's staff himself. Blank password = the same password he
        // already uses, so nothing new has to be communicated to him.
        $plain = $request->filled('admin_password') ? $request->input('admin_password') : null;
        $userData = [
            'name'       => $owner->name ?: $source->owner_name,
            'email'      => $owner->email,
            'phone'      => $owner->phone,
            'password'   => $plain ? Hash::make($plain) : $owner->password,
            'company_id' => $clone->id,
            'role'       => 'company_admin',
            'is_active'  => true,
            'pos_role'   => in_array($target, ['pos', 'fbrpos'], true) ? 'pos_admin' : null,
        ];
        if (\Illuminate\Support\Facades\Schema::hasColumn('users', 'username') && filled($owner->username)) {
            $userData['username'] = $owner->username;
        }
        User::create($userData);

        \App\Services\TrialSubscriptionService::ensureTrial($clone->id, $target, 14);

        CredentialLedgerService::record([
            'email' => $owner->email,
            'phone' => $source->phone ?: $source->mobile,
            'ntn'   => $source->ntn,
            'cnic'  => $source->cnic,
        ], $clone->id, $target);

        return $clone->fresh();
    }

}
