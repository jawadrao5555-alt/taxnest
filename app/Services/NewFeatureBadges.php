<?php

namespace App\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\Route;

/**
 * "NEW" nishan naye settings par — owner ask, 26 Aug 2026 (voice note).
 *
 * Masla: elaan (What's New) parh lene ke baad bhi shop ko yeh nahi pata chalta
 * ke naya switch HAI KAHAN. Owner ko KOT "aakhri add-on" wala switch dhoondne
 * ke liye elaan do-teen baar parhna para, phir kahin ja kar Kitchen Settings
 * mein mila. Hal: jo bhi setting naya shamil hua ho, kuch hafton ke liye us par
 * (aur us tak le jaane wale har raste par — nav pill, Customize hub card)
 * chhota sa "NEW" nishan chamak jaye.
 *
 * ── Nayi feature ship karte waqt SIRF itna karna hai ──────────────────────
 *   FEATURES mein aik entry daal do:
 *     'meri_nayi_setting' => [
 *         'since' => '2026-09-01',                       // jis din live gaya
 *         'panel' => 'pos',                              // pos | fbrpos | all
 *         'pages' => ['pos.receipt-settings'],           // jahan switch baitha hai
 *     ],
 *   aur us switch ke saath blade mein: <x-new-badge feature="meri_nayi_setting" />
 *
 *   Baqi sab khud ho jata hai: Customize hub ka card, nav ka "Settings" pill,
 *   aur window guzarne par nishan khud gayab (koi cron/cleanup nahi chahiye).
 *
 * Design ke faislay:
 *  • Waqt se chalta hai, per-user "seen" state se nahi. Koi nayi table/column
 *    nahi (PROD schema drift se bacha), har device par aik jaisa, aur DEFAULT_DAYS
 *    guzarte hi khud khatam. Shop ne dekh liya ho ya nahi — nishan utna hi arsa
 *    rehta hai jitna elaan taza hai.
 *  • Route names se bandha hai, hardcoded URLs se nahi; Route::has() guard is
 *    liye ke aik ghalat naam kabhi settings page ko 500 na kar de (yeh feature
 *    mehez cosmetic hai, isay kuch tordne ka haq nahi).
 */
class NewFeatureBadges
{
    /** Nishan kitne din chamke (entry apna 'days' de kar badal sakti hai). */
    public const DEFAULT_DAYS = 21;

    /**
     * "Yeh dekh liya" ki yaadasht — ZFC feedback, 1 Sep 2026.
     *
     * Shikayat: "NEW bar bar aa raha hai." Sach yeh tha ke nishan sirf tareekh
     * se chalta tha, is liye jab tak window khuli rehti woh HAR page load par
     * chamakta rehta — chahe shop us switch tak ja bhi chuki ho.
     *
     * Hal: jis din shop us switch wale page par pohanch jaye, us feature ki
     * yaadasht is COOKIE mein likh do aur aainda uska nishan khamosh. Cookie is
     * liye (na ke naya column): PROD par naye column hi drift karte hain aur yeh
     * mehez cosmetic cheez kisi settings page ko tor nahi sakti (memory:
     * prod-schema-drift-selfheal). Yaadasht per-DEVICE hai, is liye counter ke
     * doosre computer/staff ko nishan ab bhi milta hai — wahi asal maqsad tha.
     */
    public const SEEN_COOKIE = 'tn_new_seen';

    /** Yaadasht kitni der chale (din). Window se lamba — warna faida hi khatam. */
    public const SEEN_COOKIE_DAYS = 400;

    /**
     * Naye features ka register. Purani entries yahin rehne dena bhi theek hai
     * (window guzar chuki hoti hai to woh khud khamosh hain), magar safai ke
     * liye har chand mahine baad nikal dena behtar hai.
     */
    private const FEATURES = [
        // 25 Aug 2026 — KOT re-send aur "aakhri add-on" ke switch Modules page ke
        // sath sath Customize → Kitchen & KOT par bhi aa gaye (column wohi aik hai).
        'kot_reprint_switch' => [
            'since' => '2026-08-25',
            'panel' => 'pos',
            'pages' => ['pos.restaurant.kitchen-settings', 'pos.features'],
        ],
        'kot_last_addon_switch' => [
            'since' => '2026-08-25',
            'panel' => 'pos',
            'pages' => ['pos.restaurant.kitchen-settings', 'pos.features'],
        ],
        // 25 Aug 2026 — day-close ke purane kharcha-record ek baar mein saaf karne
        // ka button, aur local (L) series ko L001 se dobara shuru karne ka option.
        'spend_records_clear' => [
            'since' => '2026-08-25',
            'panel' => 'pos',
            'pages' => ['pos.customize'],
        ],
        'local_series_reset' => [
            'since' => '2026-08-25',
            'panel' => 'pos',
            'pages' => ['pos.customize'],
        ],
        // 27 Aug 2026 — Physical Stock Check: raat ko hath se gina hua maal
        // system ke hisaab se milao, farq khud saamne.
        'stock_check' => [
            'since' => '2026-08-27',
            'panel' => 'pos',
            'pages' => ['pos.inventory.stock-check.index'],
        ],
        // 1 Sep 2026 — local bill par roz L001 se shuru hone wala number
        // (Bill Number Style → "Roz ka local number").
        'local_daily_number' => [
            'since' => '2026-09-01',
            'panel' => 'pos',
            'pages' => ['pos.receipt-settings'],
        ],
        // 💊 5 Sep 2026 — Pharmacy / Medical Store mode FBR panel par: dawa ka
        // catalogue (salt/strength), batch aur expiry wala stock, aur khuli
        // (loose) patti ki farokht. Teenon switch aik hi card par baithe hain.
        'fbr_pharmacy_mode' => [
            'since' => '2026-09-05',
            'panel' => 'fbrpos',
            'pages' => ['fbrpos.customize'],
        ],
        'fbr_pharmacy_batch_expiry' => [
            'since' => '2026-09-05',
            'panel' => 'fbrpos',
            'pages' => ['fbrpos.customize'],
        ],
        'fbr_pharmacy_loose_sale' => [
            'since' => '2026-09-05',
            'panel' => 'fbrpos',
            'pages' => ['fbrpos.customize'],
        ],
        // Task 1580 — distributor ledger on the FBR stock page: scheme/bonus +
        // discount fields on purchase entry, supplier balances + payments,
        // statements and purchase returns.
        'fbr_supplier_ledger' => [
            'since' => '2026-09-06',
            'panel' => 'fbrpos',
            'pages' => ['fbrpos.stock'],
        ],
        'fbr_purchase_returns' => [
            'since' => '2026-09-06',
            'panel' => 'fbrpos',
            'pages' => ['fbrpos.stock.returns'],
        ],
        // Task 1579 — DRAP medicine catalogue: "add from catalogue" picker on
        // the pharmacy-mode products page (+ MRP update notices).
        'fbr_pharmacy_catalogue' => [
            'since' => '2026-09-06',
            'panel' => 'fbrpos',
            'pages' => ['fbrpos.products'],
        ],
        // 💊 6 Sep 2026 — counter-side pharmacy: dashboard par "Expire ho rahi
        // hain" tile, near-expiry window setting, aur "Missed sales" report
        // (customer ne poocha, dukan par nahi thi).
        'fbr_pharmacy_expiry_tile' => [
            'since' => '2026-09-06',
            'panel' => 'fbrpos',
            'pages' => ['fbrpos.dashboard'],
        ],
        'fbr_pharmacy_near_days' => [
            'since' => '2026-09-06',
            'panel' => 'fbrpos',
            'pages' => ['fbrpos.customize'],
        ],
        'fbr_pharmacy_missed_sales' => [
            'since' => '2026-09-06',
            'panel' => 'fbrpos',
            'pages' => ['fbrpos.pharmacy.missed-sales', 'fbrpos.pharmacy.reports'],
        ],
    ];

    /** Sirf tests ke liye — asli register ki jagah naqli entries. */
    private static ?array $fake = null;

    public static function fake(array $features): void
    {
        self::$fake = $features;
    }

    public static function clearFake(): void
    {
        self::$fake = null;
    }

    /** Poora register (jo bhi zer-e-istemal hai). */
    public static function registry(): array
    {
        return self::$fake ?? self::FEATURES;
    }

    /**
     * Woh entries jinki window abhi khuli hai. $panel do to sirf usi panel ki
     * (aur 'all' wali) entries milengi.
     */
    public static function active(?string $panel = null): array
    {
        $seen = self::seenKeys();
        $out = [];
        foreach (self::registry() as $key => $entry) {
            if (!self::windowOpen($entry)) {
                continue;
            }
            if (in_array($key, $seen, true)) {
                continue;
            }
            if ($panel !== null && !self::panelMatches($entry, $panel)) {
                continue;
            }
            $out[$key] = $entry;
        }

        return $out;
    }

    /**
     * Is device ne kin features ko "dekh liya" hai. Cookie kharab/ghair-mojood
     * ho to khali list — nishan dikhana kabhi crash na bane.
     */
    public static function seenKeys(): array
    {
        try {
            $raw = request()?->cookie(self::SEEN_COOKIE);
        } catch (\Throwable $e) {
            return [];
        }
        if (!is_string($raw) || trim($raw) === '') {
            return [];
        }

        return array_values(array_filter(array_map('trim', explode(',', $raw)), fn ($k) => $k !== ''));
    }

    /**
     * Is route par baithe hue woh naye features jo abhi tak "dekhe" nahi gaye.
     * Middleware isi se faisla karta hai ke cookie mein kya likhna hai — panel
     * ki tafreeq yahan nahi, kyunke shop usi panel ka page khol kar aayi hai.
     */
    public static function keysForRoute(?string $routeName): array
    {
        if ($routeName === null || $routeName === '') {
            return [];
        }
        $out = [];
        foreach (self::active() as $key => $entry) {
            if (in_array($routeName, (array) ($entry['pages'] ?? []), true)) {
                $out[] = $key;
            }
        }

        return $out;
    }

    /** Kya yeh feature abhi "naya" hai? */
    public static function isNew(?string $key): bool
    {
        if ($key === null || $key === '') {
            return false;
        }
        $entry = self::registry()[$key] ?? null;
        if ($entry === null || !self::windowOpen($entry)) {
            return false;
        }

        return !in_array($key, self::seenKeys(), true);
    }

    /** Is page (route name) par koi naya switch hai? */
    public static function pageHasNew(?string $routeName, ?string $panel = null): bool
    {
        if ($routeName === null || $routeName === '') {
            return false;
        }
        foreach (self::active($panel) as $entry) {
            if (in_array($routeName, (array) ($entry['pages'] ?? []), true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Customize hub ke cards sirf URL rakhte hain — usi URL ka raasta (path)
     * mila kar bata do ke us page par koi nayi cheez hai ya nahi. Query string
     * aur #anchor nazar-andaz, taake card ka URL kaisa bhi ho, milan ho jaye.
     */
    public static function urlHasNew(?string $url, ?string $panel = null): bool
    {
        $path = self::path($url);
        if ($path === null) {
            return false;
        }
        foreach (self::active($panel) as $entry) {
            foreach ((array) ($entry['pages'] ?? []) as $routeName) {
                if (!Route::has($routeName)) {
                    continue;
                }
                try {
                    // Route ko parameter darkaar ho (ya URL banane mein koi bhi
                    // masla ho) to sirf khamoshi — nishan na sahi, hub card to
                    // khule. Yeh feature mehez cosmetic hai.
                    $target = route($routeName, [], false);
                } catch (\Throwable $e) {
                    continue;
                }
                if (self::path($target) === $path) {
                    return true;
                }
            }
        }

        return false;
    }

    /** Panel ke kisi bhi settings page par nayi cheez? (nav ka chhota nuqta) */
    public static function panelHasNew(?string $panel): bool
    {
        if ($panel === null || $panel === '') {
            return false;
        }

        return self::active($panel) !== [];
    }

    /**
     * Component ka aik hi darwaza: feature key, page, URL ya panel — jo bhi
     * diya jaye, us par faisla. Kuch na diya jaye to nishan nahi.
     */
    public static function shows(
        ?string $feature = null,
        ?string $page = null,
        ?string $url = null,
        ?string $panel = null
    ): bool {
        if ($feature !== null && $feature !== '') {
            return self::isNew($feature);
        }
        if ($page !== null && $page !== '') {
            return self::pageHasNew($page, $panel);
        }
        if ($url !== null && $url !== '') {
            return self::urlHasNew($url, $panel);
        }
        if ($panel !== null && $panel !== '') {
            return self::panelHasNew($panel);
        }

        return false;
    }

    /** Window abhi khuli hai? Ghalat/khali tareekh = khamoshi (kabhi crash nahi). */
    private static function windowOpen(array $entry): bool
    {
        $since = trim((string) ($entry['since'] ?? ''));
        if ($since === '') {
            return false;
        }
        try {
            $start = Carbon::parse($since)->startOfDay();
        } catch (\Throwable $e) {
            return false;
        }
        $days = (int) ($entry['days'] ?? self::DEFAULT_DAYS);
        if ($days < 1) {
            return false;
        }

        $now = Carbon::now();

        return $now->greaterThanOrEqualTo($start) && $now->lessThan($start->copy()->addDays($days));
    }

    private static function panelMatches(array $entry, string $panel): bool
    {
        $entryPanel = (string) ($entry['panel'] ?? 'all');

        return $entryPanel === 'all' || $entryPanel === $panel;
    }

    private static function path(?string $url): ?string
    {
        if ($url === null) {
            return null;
        }
        $url = trim($url);
        if ($url === '') {
            return null;
        }
        $path = parse_url($url, PHP_URL_PATH);
        if (!is_string($path) || $path === '') {
            return null;
        }
        $path = rtrim($path, '/');

        return $path === '' ? '/' : $path;
    }
}
