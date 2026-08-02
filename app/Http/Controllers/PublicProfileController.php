<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\PosMenuItem;
use App\Models\PosProduct;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * P8 (F8) — Public QR business profile + menu.
 *
 * Public side: a single slug-only route (/menu/{slug}) — throttled, 404 on
 * unknown slugs, zero enumeration (slug is a random 24-char token, never an
 * id or name). Shows only the details the company chose to expose plus an
 * optional live-priced menu (prices read from pos_products at render time).
 *
 * Admin side: settings + menu builder live on the POS Business Profile page;
 * every POST is POS-admin gated (cashiers 403) per the settings-POST rule.
 */
class PublicProfileController extends Controller
{
    /** Default per-detail visibility when a company first enables the page. */
    private const DEFAULT_SETTINGS = [
        'enabled' => false,
        'show_phone' => true,
        'show_mobile' => true,
        'show_email' => false,
        'show_address' => true,
        'show_ntn' => false,
        'show_website' => true,
        'show_hours' => false,
        'hours_text' => '',
        'about_text' => '',
        'show_menu' => true,
    ];

    public static function settingsFor(Company $company): array
    {
        return array_merge(self::DEFAULT_SETTINGS, $company->public_profile_settings ?? []);
    }

    /**
     * Absolute public URL for a company, or null when the page is disabled
     * or the plan lacks the QR Menu gate (Pro and above, Aug
     * 2026). Receipts (80mm/58mm) and the business-profile page all read
     * this ONE helper, so the QR block disappears everywhere at once.
     */
    public static function publicUrlFor(?Company $company): ?string
    {
        if (!$company || !$company->public_profile_slug) {
            return null;
        }
        $settings = self::settingsFor($company);
        if (!($settings['enabled'] ?? false)) {
            return null;
        }
        if (!\App\Services\PosFeatureService::planAllows($company, 'qr_menu_enabled')) {
            return null;
        }
        return url('/menu/' . $company->public_profile_slug);
    }

    // ============================== PUBLIC ==============================

    public function show(string $slug)
    {
        // Slug-only lookup; anything else (including disabled pages) is a plain 404
        // so outsiders can't distinguish "wrong slug" from "page turned off".
        if (!preg_match('/^[a-z0-9]{16,32}$/', $slug)) {
            abort(404);
        }

        $company = Company::where('public_profile_slug', $slug)->first();
        if (!$company) {
            abort(404);
        }

        $settings = self::settingsFor($company);
        if (!($settings['enabled'] ?? false)) {
            abort(404);
        }

        // Plan gate (Aug 2026): QR Menu is Pro and above. Public
        // page — plain 404, no upgrade pitch (customers aren't the buyer).
        if (!\App\Services\PosFeatureService::planAllows($company, 'qr_menu_enabled')) {
            abort(404);
        }

        // Customer-facing page (no login) — SetPosLocale user-pref resolution
        // doesn't apply, so render in the company's default language.
        $lang = $company->default_language;
        app()->setLocale(in_array($lang, ['ur', 'en'], true) ? $lang : 'ur');

        $menuItems = collect();
        if ($settings['show_menu'] ?? true) {
            $menuItems = PosMenuItem::where('company_id', $company->id)
                ->where('is_active', true)
                ->with('product')
                ->orderBy('sort_order')
                ->orderBy('id')
                ->get()
                // Live prices: drop rows whose product is gone or deactivated.
                ->filter(fn ($mi) => $mi->product
                    && (int) $mi->product->company_id === (int) $company->id
                    && $mi->product->is_active)
                ->values();
        }

        return response()
            ->view('public.company-profile', [
                'company' => $company,
                'settings' => $settings,
                'menuItems' => $menuItems,
            ])
            ->header('X-Robots-Tag', 'noindex');
    }

    // ============================== ADMIN ==============================

    private function adminGate(Request $request): array
    {
        $user = $request->user('pos');
        if (!$user || $user->posCashierBlocked()) {
            abort(403, 'Only POS administrators can manage the public profile.');
        }
        $companyId = app('currentCompanyId');
        $company = Company::findOrFail($companyId);
        // Plan gate (Aug 2026): QR Menu settings are Pro and above.
        if (!\App\Services\PosFeatureService::planAllows($company, 'qr_menu_enabled')) {
            throw new \Illuminate\Http\Exceptions\HttpResponseException(
                back()->with('error', __('pos.qr_menu_plan_locked'))
            );
        }
        return [$user, $company];
    }

    /** POST /pos/public-profile — save toggles / enable / disable. */
    public function saveSettings(Request $request)
    {
        [, $company] = $this->adminGate($request);

        $request->validate([
            'hours_text' => 'nullable|string|max:200',
            'about_text' => 'nullable|string|max:600',
        ]);

        $settings = self::settingsFor($company);
        $settings['enabled'] = $request->has('pp_enabled');
        foreach (['show_phone', 'show_mobile', 'show_email', 'show_address', 'show_ntn', 'show_website', 'show_hours', 'show_menu'] as $key) {
            $settings[$key] = $request->has('pp_' . $key);
        }
        $settings['hours_text'] = trim((string) $request->input('hours_text', ''));
        $settings['about_text'] = trim((string) $request->input('about_text', ''));

        // First enable ever → mint the permanent random slug.
        if ($settings['enabled'] && !$company->public_profile_slug) {
            $company->public_profile_slug = self::mintSlug();
        }

        $company->public_profile_settings = $settings;
        $company->save();

        return back()->with('success', 'Public profile settings saved.');
    }

    /** POST /pos/public-profile/regenerate — new slug (old QR links die). */
    public function regenerateSlug(Request $request)
    {
        [, $company] = $this->adminGate($request);

        $company->public_profile_slug = self::mintSlug();
        $company->save();

        return back()->with('success', 'New public link generated — purane QR codes ab kaam nahi karenge.');
    }

    /** POST /pos/public-profile/menu — replace the menu selection (ids in order). */
    public function saveMenu(Request $request)
    {
        [, $company] = $this->adminGate($request);

        $request->validate([
            'menu_product_ids' => 'nullable|array|max:300',
            'menu_product_ids.*' => 'integer',
        ]);

        $ids = collect($request->input('menu_product_ids', []))
            ->map(fn ($v) => (int) $v)
            ->filter()
            ->unique()
            ->values();

        // Company-scoped: only this company's own products can be pinned.
        $validIds = PosProduct::where('company_id', $company->id)
            ->whereIn('id', $ids)
            ->pluck('id')
            ->map(fn ($v) => (int) $v)
            ->all();
        $validSet = array_flip($validIds);
        $ordered = $ids->filter(fn ($id) => isset($validSet[$id]))->values();

        PosMenuItem::where('company_id', $company->id)
            ->whereNotIn('pos_product_id', $ordered->all())
            ->delete();

        foreach ($ordered as $i => $productId) {
            PosMenuItem::updateOrCreate(
                ['company_id' => $company->id, 'pos_product_id' => $productId],
                ['sort_order' => $i, 'is_active' => true]
            );
        }

        return back()->with('success', 'Public menu updated (' . $ordered->count() . ' items).');
    }

    private static function mintSlug(): string
    {
        do {
            $slug = strtolower(Str::random(24));
        } while (Company::where('public_profile_slug', $slug)->exists());

        return $slug;
    }
}
