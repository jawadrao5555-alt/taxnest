<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\FbrPlanGate;
use App\Models\Company;
use App\Models\MedicineCatalogueEntry;
use App\Models\MedicinePriceNotice;
use App\Models\Product;
use App\Services\AuditLogService;
use App\Services\Pharmacy\MedicineCatalogueSyncService;
use App\Services\PlanLimitService;
use App\Services\PosFeatureService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * FBR Pharmacy Mode — "add from the medicine catalogue" + MRP update notices
 * (Task 1579).
 *
 * The catalogue is the global DRAP-seeded list the SaaS admin maintains; a
 * shop searches it on its products page, ticks rows, and gets ordinary company
 * products (linked back to the catalogue row) created through the same rules
 * the product form uses. When a later DRAP sync changes a linked row's MRP the
 * shop sees a notice and decides — nothing is repriced silently.
 *
 * Every action re-checks pharmacyLive() (package AND shop switch), exactly
 * like FbrPosPharmacyController: a bookmarked URL cannot bypass the hidden nav.
 */
class FbrPosCatalogueController extends Controller
{
    use FbrPlanGate;

    public const SEARCH_LIMIT = 40;

    public const ADD_LIMIT = 50;

    public function __construct(private readonly MedicineCatalogueSyncService $sync)
    {
    }

    private function user() { return Auth::guard('fbrpos')->user(); }

    private function companyId(): int { return (int) $this->user()->company_id; }

    private function company(): ?Company { return Company::find($this->companyId()); }

    /** null = may proceed; otherwise the refusal response. */
    private function pharmacyGate(bool $json = false)
    {
        if (!Schema::hasTable('medicine_catalogue') || !Schema::hasColumn('products', 'medicine_catalogue_id')) {
            return $json
                ? response()->json(['success' => false, 'message' => 'Catalogue not available on this server.'], 503)
                : redirect()->route('fbrpos.products')->with('error', 'Catalogue not available on this server.');
        }
        if (!PosFeatureService::pharmacyLive($this->company())) {
            return $json
                ? response()->json(['success' => false, 'message' => __('pos.ph_mode_off')], 403)
                : redirect()->route('fbrpos.customize')->with('error', __('pos.ph_mode_off'));
        }

        return null;
    }

    /** Product creation and repricing are owner (company_admin) territory, like the product form. */
    private function adminGate(bool $json = false)
    {
        if (($this->user()->role ?? null) !== 'company_admin') {
            return $json
                ? response()->json(['success' => false, 'message' => __('pos.access_denied')], 403)
                : abort(403, __('pos.access_denied'));
        }

        return null;
    }

    /**
     * GET /fbr-pos/pharmacy/catalogue/search?q=panadol
     * Brand / salt / manufacturer / reg-no search; already-linked rows are
     * flagged so the picker shows "already added" instead of a checkbox.
     */
    public function search(Request $request)
    {
        if ($r = $this->pharmacyGate(true)) return $r;

        $q = trim((string) $request->query('q', ''));
        if (mb_strlen($q) < 2) {
            return response()->json(['success' => true, 'items' => [], 'query' => $q]);
        }
        $companyId = $this->companyId();

        $like = '%' . str_replace(['%', '_'], ['\%', '\_'], $q) . '%';
        $prefix = str_replace(['%', '_'], ['\%', '\_'], $q) . '%';
        $entries = MedicineCatalogueEntry::query()
            ->where('is_active', true)
            ->where(function ($w) use ($like, $q) {
                $w->where('brand_name', 'like', $like)
                    ->orWhere('generic_name', 'like', $like)
                    ->orWhere('composition', 'like', $like)
                    ->orWhere('manufacturer', 'like', $like);
                if (ctype_digit($q)) {
                    $w->orWhere('drap_reg_no', str_pad($q, 6, '0', STR_PAD_LEFT));
                }
            })
            // brand-prefix hits first ("panadol" → Panadol before Panadol Extra before "…contains panadol")
            ->orderByRaw('CASE WHEN brand_name LIKE ? THEN 0 WHEN generic_name LIKE ? THEN 1 ELSE 2 END', [$prefix, $prefix])
            ->orderBy('brand_name')
            ->orderBy('pack_size')
            ->limit(self::SEARCH_LIMIT)
            ->get();

        $ids = $entries->pluck('id')->all();
        $linked = $ids
            ? Product::where('company_id', $companyId)->whereIn('medicine_catalogue_id', $ids)
                ->get(['id', 'name', 'medicine_catalogue_id'])->keyBy('medicine_catalogue_id')
            : collect();

        $items = $entries->map(function (MedicineCatalogueEntry $e) use ($linked) {
            $row = $e->toPickerArray();
            $row['category_label'] = MedicineCatalogueEntry::categoryLabel($e->category);
            $p = $linked->get($e->id);
            $row['linked_product_id'] = $p?->id;
            $row['linked_product_name'] = $p?->name;

            return $row;
        })->values();

        return response()->json(['success' => true, 'items' => $items, 'query' => $q, 'limit' => self::SEARCH_LIMIT]);
    }

    /**
     * POST /fbr-pos/pharmacy/catalogue/add  {ids: [...]}
     * Bulk-creates linked company products. Mirrors storeMultipleProducts():
     * exempt/0 tax default (product-form default), uom U, price editable,
     * sale price = MRP, plan quota for EVERY row, one transaction.
     */
    public function add(Request $request)
    {
        if ($r = $this->pharmacyGate(true)) return $r;
        if ($r = $this->adminGate(true)) return $r;

        $data = $request->validate([
            'ids' => 'required|array|min:1|max:' . self::ADD_LIMIT,
            'ids.*' => 'integer|min:1',
        ]);
        $companyId = $this->companyId();
        $ids = array_values(array_unique(array_map('intval', $data['ids'])));

        $entries = MedicineCatalogueEntry::whereIn('id', $ids)->where('is_active', true)->get()->keyBy('id');
        $already = Product::where('company_id', $companyId)->whereIn('medicine_catalogue_id', $ids)
            ->get(['id', 'name', 'medicine_catalogue_id'])->keyBy('medicine_catalogue_id');

        $toCreate = [];
        $skipped = [];
        foreach ($ids as $id) {
            $e = $entries->get($id);
            if (!$e) {
                $skipped[] = ['id' => $id, 'reason' => 'missing'];
                continue;
            }
            if ($already->has($id)) {
                $skipped[] = ['id' => $id, 'reason' => 'already', 'product_id' => $already->get($id)->id, 'name' => $already->get($id)->name];
                continue;
            }
            $toCreate[] = $e;
        }

        if (empty($toCreate)) {
            return response()->json(['success' => true, 'created' => [], 'skipped' => $skipped, 'message' => __('pos.ph_cat_nothing_new')]);
        }

        // Plan quota for EVERY row — the same check the product form runs.
        $remaining = PlanLimitService::remainingProductAllowance($companyId, 'fbr');
        if ($remaining !== null && count($toCreate) > $remaining) {
            return response()->json([
                'success' => false,
                'message' => __('pos.fbr_pf_quota_rows', ['n' => max(0, (int) $remaining)]),
                'remaining' => (int) $remaining,
            ], 422);
        }

        $hasThirdCol = Schema::hasColumn('products', 'is_third_schedule');
        $hasDrapCol = Schema::hasColumn('products', 'drap_reg_no');
        $userId = (int) $this->user()->id;

        $created = DB::transaction(function () use ($toCreate, $companyId, $hasThirdCol, $hasDrapCol, $userId) {
            $out = [];
            foreach ($toCreate as $e) {
                // Re-check under the transaction: two tabs adding the same row.
                $dup = Product::where('company_id', $companyId)->where('medicine_catalogue_id', $e->id)->first();
                if ($dup) {
                    continue;
                }
                $mrp = $e->mrp !== null ? round((float) $e->mrp, 2) : null;
                $attrs = [
                    'company_id' => $companyId,
                    'name' => self::productNameFor($e),
                    'default_price' => $mrp ?? 0,
                    'mrp' => $mrp,
                    'is_price_editable' => true,
                    'uom' => 'U',
                    'tax_type' => 'exempt',
                    'default_tax_rate' => 0,
                    'generic_name' => $e->generic_name ? mb_substr($e->generic_name, 0, 190) : null,
                    'strength' => $e->strength ? mb_substr($e->strength, 0, 60) : null,
                    'dosage_form' => in_array($e->dosage_form, Product::DOSAGE_FORMS, true) ? $e->dosage_form : null,
                    'manufacturer' => $e->manufacturer ? mb_substr($e->manufacturer, 0, 150) : null,
                    'medicine_catalogue_id' => $e->id,
                    'is_active' => true,
                    'show_on_sale' => true, // explicit — never trust the DB default (prod drift)
                ];
                if ($hasThirdCol) $attrs['is_third_schedule'] = false;
                if ($hasDrapCol) $attrs['drap_reg_no'] = $e->drap_reg_no;
                $p = Product::create($attrs);

                AuditLogService::log('medicine_catalogue_product_added', 'Product', $p->id, null, [
                    'catalogue_id' => $e->id, 'drap_reg_no' => $e->drap_reg_no, 'mrp' => $mrp, 'brand' => $e->brand_name,
                ], $companyId, $userId);

                $out[] = ['id' => $e->id, 'product_id' => $p->id, 'name' => $p->name, 'mrp' => $mrp];
            }

            return $out;
        });

        return response()->json([
            'success' => true,
            'created' => $created,
            'skipped' => $skipped,
            'message' => __('pos.ph_cat_added_n', ['n' => count($created)]),
            'edit_url_base' => route('fbrpos.products.edit', ['id' => 0], false),
        ]);
    }

    /**
     * Product name a counter recognises: brand as DRAP prints it plus the
     * pack text ("Panadol Tablets 500mg (200's)") — the same brand comes in
     * several packs and the shop sells them as separate items.
     */
    public static function productNameFor(MedicineCatalogueEntry $e): string
    {
        $name = trim((string) $e->brand_name);
        $pack = trim((string) $e->pack_size);
        // A single-unit pack ("1", "1's", "1 vial") adds nothing a counter needs.
        if (preg_match("/^\(?1\s*(?:'s|s|x|unit|vial|pc|piece)?\)?$/i", $pack)) {
            $pack = '';
        }
        if ($pack !== '' && mb_stripos($name, $pack) === false) {
            $pack = preg_replace('/^\((.*)\)$/', '$1', $pack);
            $name .= ' (' . $pack . ')';
        }

        return mb_substr($name, 0, 255);
    }

    /** GET /fbr-pos/pharmacy/price-updates — pending + recent history for this shop. */
    public function priceUpdates(Request $request)
    {
        if ($r = $this->pharmacyGate()) return $r;
        $companyId = $this->companyId();
        $isAdmin = ($this->user()->role ?? null) === 'company_admin';

        $pending = MedicinePriceNotice::with(['product', 'entry'])
            ->where('company_id', $companyId)
            ->where('status', MedicinePriceNotice::STATUS_PENDING)
            ->orderByDesc('id')
            ->get()
            ->filter(fn ($n) => $n->product);

        $history = MedicinePriceNotice::with(['product', 'entry'])
            ->where('company_id', $companyId)
            ->whereIn('status', [MedicinePriceNotice::STATUS_APPLIED, MedicinePriceNotice::STATUS_DISMISSED])
            ->orderByDesc('acted_at')
            ->limit(30)
            ->get()
            ->filter(fn ($n) => $n->product);

        return view('fbr-pos.pharmacy.price-updates', [
            'pending' => $pending,
            'history' => $history,
            'isAdmin' => $isAdmin,
        ]);
    }

    /** POST /fbr-pos/pharmacy/price-updates/{id}/apply */
    public function apply(Request $request, int $id)
    {
        if ($r = $this->pharmacyGate(true)) return $r;
        if ($r = $this->adminGate(true)) return $r;

        $notice = MedicinePriceNotice::where('company_id', $this->companyId())->findOrFail($id);
        $ok = $this->sync->applyNotice($notice, (int) $this->user()->id);
        $notice->refresh();

        return $this->noticeResponse($request, $ok, $ok ? __('pos.ph_cat_price_applied') : __('pos.ph_cat_price_not_pending'), $notice);
    }

    /** POST /fbr-pos/pharmacy/price-updates/{id}/dismiss */
    public function dismiss(Request $request, int $id)
    {
        if ($r = $this->pharmacyGate(true)) return $r;
        if ($r = $this->adminGate(true)) return $r;

        $notice = MedicinePriceNotice::where('company_id', $this->companyId())->findOrFail($id);
        $ok = $this->sync->dismissNotice($notice, (int) $this->user()->id);
        $notice->refresh();

        return $this->noticeResponse($request, $ok, $ok ? __('pos.ph_cat_price_dismissed') : __('pos.ph_cat_price_not_pending'), $notice);
    }

    /** POST /fbr-pos/pharmacy/price-updates/apply-all */
    public function applyAll(Request $request)
    {
        if ($r = $this->pharmacyGate(true)) return $r;
        if ($r = $this->adminGate(true)) return $r;

        $userId = (int) $this->user()->id;
        $applied = 0;
        MedicinePriceNotice::where('company_id', $this->companyId())
            ->where('status', MedicinePriceNotice::STATUS_PENDING)
            ->orderBy('id')
            ->get()
            ->each(function (MedicinePriceNotice $n) use (&$applied, $userId) {
                if ($this->sync->applyNotice($n, $userId)) {
                    $applied++;
                }
            });

        $msg = __('pos.ph_cat_price_applied_n', ['n' => $applied]);
        if ($request->expectsJson()) {
            return response()->json(['success' => true, 'applied' => $applied, 'message' => $msg, 'pending' => MedicinePriceNotice::pendingCountFor($this->companyId())]);
        }

        return redirect()->route('fbrpos.pharmacy.price-updates')->with('success', $msg);
    }

    private function noticeResponse(Request $request, bool $ok, string $msg, MedicinePriceNotice $notice)
    {
        if ($request->expectsJson()) {
            return response()->json([
                'success' => $ok,
                'message' => $msg,
                'status' => $notice->status,
                'pending' => MedicinePriceNotice::pendingCountFor($this->companyId()),
            ], $ok ? 200 : 409);
        }

        return redirect()->route('fbrpos.pharmacy.price-updates')->with($ok ? 'success' : 'error', $msg);
    }
}
