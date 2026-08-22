<?php

namespace App\Http\Controllers\SaasAdmin;

use App\Http\Controllers\Controller;
use App\Models\PricingPlan;
use App\Models\AdminAuditLog;
use App\Services\PlanLadderGuard;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Eloquent\Collection;
use App\Services\PosAddonPricingService;
use App\Services\PosPlanComparisonService;

class AdminPlanController extends Controller
{
    public function index()
    {
        // Defensive: if the product_type column has not been migrated on this
        // database yet, fall back to bucketing every plan as Digital Invoice
        // instead of crashing with a 500 (the safety migration adds the column).
        if (Schema::hasColumn('pricing_plans', 'product_type')) {
            $diPlans = PricingPlan::where('product_type', 'di')->orderBy('price')->get();
            $posPlans = PricingPlan::where('product_type', 'pos')
                ->where(function ($query) {
                    $query->where('is_trial', true)
                        ->orWhereIn('name', PosPlanComparisonService::SELLABLE_PLAN_NAMES);
                })
                ->orderBy('price')
                ->get();
            $fbrposPlans = PricingPlan::where('product_type', 'fbrpos')->orderBy('price')->get();
        } else {
            $diPlans = PricingPlan::orderBy('price')->get();
            $posPlans = new Collection();
            $fbrposPlans = new Collection();
        }

        // A ladder that is ALREADY broken must not be invisible — the editor
        // only blocks NEW breakage, so an old one would otherwise sit there
        // silently until the next deploy gate run (Task 1455).
        $ladderWarnings = PlanLadderGuard::allCurrentProblems();

        $posAddons = PosAddonPricingService::catalog();

        return view('saas-admin.plans', compact('diPlans', 'posPlans', 'fbrposPlans', 'ladderWarnings', 'posAddons'));
    }

    public function updateAddonPricing(Request $request)
    {
        $rules = ['addons' => ['required', 'array']];
        foreach (array_keys(PosAddonPricingService::ADDONS) as $code) {
            $rules["addons.{$code}.annual"] = ['required', 'numeric', 'min:0', 'max:999999999'];
            $rules["addons.{$code}.quarterly"] = ['required', 'numeric', 'min:0', 'max:999999999'];
        }

        $data = $request->validate($rules);
        $before = PosAddonPricingService::catalog();
        PosAddonPricingService::save($data['addons']);
        $after = PosAddonPricingService::catalog();

        AdminAuditLog::log(auth('admin')->id(), 'PRA POS add-on pricing updated', 'SystemSetting', null, [
            'old' => collect($before)->mapWithKeys(fn ($row, $code) => [
                $code => ['annual' => $row['annual_price'], 'quarterly' => $row['quarterly_price']],
            ])->all(),
            'new' => collect($after)->mapWithKeys(fn ($row, $code) => [
                $code => ['annual' => $row['annual_price'], 'quarterly' => $row['quarterly_price']],
            ])->all(),
        ]);

        return back()->with('success', 'PRA POS add-on rates saved successfully.');
    }

    /**
     * The row exactly as it would be written, for the ladder guard to audit.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function ladderAttributes(Request $request, array $data): array
    {
        return [
            'name'              => $data['name'],
            'product_type'      => $data['product_type'],
            'price'             => $data['price'],
            'invoice_limit'     => $data['invoice_limit'],
            'max_terminals'     => $request->input('max_terminals'),
            'max_users'         => $request->input('max_users'),
            'max_products'      => $request->input('max_products'),
            'inventory_enabled' => $request->boolean('inventory_enabled'),
            'reports_enabled'   => $request->boolean('reports_enabled'),
        ];
    }

    /**
     * Refuse a save that would break the package ladder, unless the admin has
     * ticked "save anyway" — then let it through but record the override.
     *
     * @param  array<int, string>  $problems
     */
    private function ladderBlock(Request $request, array $problems, string $planName)
    {
        if (!$problems || $request->boolean('ladder_override')) {
            return null;
        }

        return back()->withInput()->withErrors([
            'ladder' => array_merge(
                ["This change to '{$planName}' would break the package ladder, so it was not saved:"],
                array_map(fn ($p) => '• ' . $p, $problems),
                ['Tick "Save anyway (breaks the ladder)" on the form if you really mean it.'],
            ),
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => 'required|string|max:100',
            'price' => 'required|numeric|min:0',
            'price_quarterly' => 'nullable|numeric|min:0',
            'invoice_limit' => 'required|integer|min:-1',
            'product_type' => 'required|in:di,pos,fbrpos',
            'max_terminals' => 'nullable|integer|min:-1',
            'max_users' => 'nullable|integer|min:-1',
            'max_products' => 'nullable|integer|min:-1',
            'inventory_enabled' => 'boolean',
            'reports_enabled' => 'boolean',
            'features_text' => 'nullable|string',
        ]);

        $features = array_filter(array_map('trim', explode("\n", $request->input('features_text', ''))));

        // Ladder check BEFORE the write (Task 1455) — a new half-configured
        // package dropped into the middle of a ladder is the classic way a
        // card silently stops saying "Everything in <previous>, plus:".
        $ladderProblems = PlanLadderGuard::newProblems($this->ladderAttributes($request, $data));
        if ($blocked = $this->ladderBlock($request, $ladderProblems, $data['name'])) {
            return $blocked;
        }

        // Explicit field list (never mass-assign the whole request) so sensitive
        // columns like is_trial can't be injected via crafted POST data.
        $plan = PricingPlan::create([
            'name' => $data['name'],
            'product_type' => $data['product_type'],
            'price' => $data['price'],
            'price_monthly' => in_array($data['product_type'], ['di', 'fbrpos']) ? $data['price'] : null,
            'price_quarterly' => $data['price_quarterly'] ?? null,
            'invoice_limit' => $data['invoice_limit'],
            'max_terminals' => $request->input('max_terminals'),
            'max_users' => $request->input('max_users'),
            'max_products' => $request->input('max_products'),
            'inventory_enabled' => $request->boolean('inventory_enabled'),
            'reports_enabled' => $request->boolean('reports_enabled'),
            'features' => $features,
        ]);

        AdminAuditLog::log(auth('admin')->id(), 'Plan created', 'PricingPlan', $plan->id, array_filter([
            'name' => $plan->name,
            'product_type' => $plan->product_type,
            'ladder_override' => $ladderProblems ?: null,
        ]));
        return back()->with('success', "Plan '{$plan->name}' created."
            . ($ladderProblems ? ' Saved with a ladder warning — see the banner on this page.' : ''));
    }

    public function update(Request $request, $id)
    {
        $plan = PricingPlan::findOrFail($id);

        $data = $request->validate([
            'name' => 'required|string|max:100',
            'price' => 'required|numeric|min:0',
            'price_quarterly' => 'nullable|numeric|min:0',
            'invoice_limit' => 'required|integer|min:-1',
            'product_type' => 'required|in:di,pos,fbrpos',
            'max_terminals' => 'nullable|integer|min:-1',
            'max_users' => 'nullable|integer|min:-1',
            'max_products' => 'nullable|integer|min:-1',
            'features_text' => 'nullable|string',
        ]);

        $features = array_filter(array_map('trim', explode("\n", $request->input('features_text', ''))));

        // Ladder check BEFORE the write (Task 1455) — reports switched off on a
        // costlier package, a tightened cap or a reprice that reorders the
        // ladder all land silently otherwise.
        $ladderProblems = PlanLadderGuard::newProblems($this->ladderAttributes($request, $data), (int) $plan->id);
        if ($blocked = $this->ladderBlock($request, $ladderProblems, $data['name'])) {
            return $blocked;
        }

        // Explicit field list (never mass-assign the whole request) so sensitive
        // columns like is_trial can't be injected via crafted POST data.
        $plan->update([
            'name' => $data['name'],
            'product_type' => $data['product_type'],
            'price' => $data['price'],
            'price_monthly' => in_array($data['product_type'], ['di', 'fbrpos']) ? $data['price'] : null,
            'price_quarterly' => $data['price_quarterly'] ?? null,
            'invoice_limit' => $data['invoice_limit'],
            'max_terminals' => $request->input('max_terminals'),
            'max_users' => $request->input('max_users'),
            'max_products' => $request->input('max_products'),
            'inventory_enabled' => $request->boolean('inventory_enabled'),
            'reports_enabled' => $request->boolean('reports_enabled'),
            'features' => $features,
        ]);

        AdminAuditLog::log(auth('admin')->id(), 'Plan updated', 'PricingPlan', $plan->id, array_filter([
            'name' => $plan->name,
            'product_type' => $plan->product_type,
            'ladder_override' => $ladderProblems ?: null,
        ]));
        return back()->with('success', "Plan '{$plan->name}' updated."
            . ($ladderProblems ? ' Saved with a ladder warning — see the banner on this page.' : ''));
    }
}
