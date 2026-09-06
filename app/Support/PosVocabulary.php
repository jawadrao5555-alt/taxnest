<?php

namespace App\Support;

use App\Models\Company;
use App\Services\PosCategoryProfiles;
use App\Services\PosFeatureService;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Str;

/**
 * The shop's own words (Task 1582).
 *
 * One helper that turns a company's category profile into the vocabulary the
 * shop-facing screens use: the item noun ("Menu items" / "Products" /
 * "Medicines" / "Services"), brand-neutral example item names, a quick-type
 * example, receipt-preview sample lines, Excel import sample rows, units and
 * the sale-screen defaults — all in the CURRENT locale.
 *
 * Lang keys stay single (one key, three languages) and carry placeholders
 * (":item", ":items", ":example", ...) that the category fills, so the
 * three-way key sync and the Urdu purity test keep working. Use:
 *
 *   PosVocabulary::t('search_mode_prefix')              // __() + category placeholders
 *   __('pos.no_active_products', PosVocabulary::replacements())
 *   PosI18n::baked('pos/universal', PosVocabulary::replacements($company))
 *
 * The company defaults to the request's current company (POS or FBR guard).
 */
final class PosVocabulary
{
    /** company_id|locale|panel => resolved vocabulary */
    private static array $cache = [];

    /** Lang-key names each family owns for its nouns (pos.vocab_<slot>_<family>). */
    public const NOUN_SLOTS = ['item', 'items', 'grid', 'list', 'counter', 'family'];

    public static function flush(): void
    {
        self::$cache = [];
    }

    public static function currentCompany(): ?Company
    {
        try {
            if (app()->bound('currentCompanyId') && app('currentCompanyId')) {
                return Company::find((int) app('currentCompanyId'));
            }
        } catch (\Throwable $e) {
            // fall through to the guards
        }
        foreach (['pos', 'fbrpos'] as $guard) {
            try {
                $user = auth($guard)->user();
                if ($user && $user->company_id) {
                    return Company::find((int) $user->company_id);
                }
            } catch (\Throwable $e) {
                // guard not configured in this context
            }
        }
        return null;
    }

    /**
     * Full vocabulary record for a company in the current locale.
     *
     * @return array{
     *   category:string, category_label:string, family:string, family_label:string,
     *   panel:string, item:string, items:string, items_lower:string, grid:string, list:string, counter:string,
     *   examples:string[], example:string, example2:string, example3:string,
     *   quick_type:string, search_any_frag:string, search_any_example:string,
     *   search_prefix_frag:string, search_prefix_example:string,
     *   receipt_lines:array<int,array{name:string,qty:int,price:int}>,
     *   import_rows:array<int,array<int,string|int>>, sample_category:string,
     *   unit:string, units:string[], order:string, checklist:string[], tiles:string[],
     *   modules:string[]
     * }
     */
    public static function for(?Company $company = null): array
    {
        $company = $company ?? self::currentCompany();
        $panel = PosFeatureService::panelFor($company);
        $locale = app()->getLocale();
        $key = ($company?->id ?? 0) . '|' . $locale . '|' . $panel;
        if (isset(self::$cache[$key])) {
            return self::$cache[$key];
        }

        $profile = PosFeatureService::profile($company);
        $family = $profile['family'];
        $examples = $profile['examples'];
        $prices = $profile['prices'];

        $noun = fn (string $slot) => self::noun($slot, $family);

        [$anyFrag, $anyExample] = self::anyWordSample($examples);
        [$prefixFrag, $prefixExample] = self::prefixSample($examples);

        $receipt = [];
        foreach ([0, 2] as $i => $idx) {
            $receipt[] = [
                'name' => $examples[$idx] ?? $examples[0],
                'qty' => $i === 0 ? 2 : 1,
                'price' => (int) ($prices[$idx] ?? $prices[0]),
            ];
        }

        $rows = [];
        foreach (array_slice($examples, 0, 3) as $i => $name) {
            $rows[] = [$name, $profile['sample_category'], (int) ($prices[$i] ?? $prices[0]), $profile['unit']];
        }

        $items = $noun('items');
        $out = [
            'category' => $profile['category'],
            'category_label' => self::categoryLabel($profile['category']),
            'family' => $family,
            'family_label' => $noun('family'),
            'panel' => $panel,
            'item' => $noun('item'),
            'items' => $items,
            'items_lower' => self::lower($items),
            'grid' => $noun('grid'),
            'list' => $noun('list'),
            'counter' => $noun('counter'),
            'examples' => $examples,
            'example' => $examples[0],
            'example2' => $examples[1] ?? $examples[0],
            'example3' => $examples[2] ?? $examples[0],
            'quick_type' => self::quickType($examples),
            'search_any_frag' => $anyFrag,
            'search_any_example' => $anyExample,
            'search_prefix_frag' => $prefixFrag,
            'search_prefix_example' => $prefixExample,
            'receipt_lines' => $receipt,
            'import_rows' => $rows,
            'sample_category' => $profile['sample_category'],
            'unit' => $profile['unit'],
            'units' => $profile['units'],
            'order' => $profile['order'],
            'checklist' => $profile['checklist'],
            'tiles' => $profile['tiles'],
            'modules' => $profile['modules'],
        ];
        return self::$cache[$key] = $out;
    }

    /**
     * Placeholder map for __('pos.key', ...) — every string slot of for().
     *
     * @return array<string,string>
     */
    public static function replacements(?Company $company = null): array
    {
        $v = self::for($company);
        return [
            'item' => $v['item'],
            'items' => $v['items'],
            'items_lower' => $v['items_lower'],
            'grid' => $v['grid'],
            'list' => $v['list'],
            'counter' => $v['counter'],
            'example' => $v['example'],
            'example2' => $v['example2'],
            'example3' => $v['example3'],
            'quick_type' => $v['quick_type'],
            'any_frag' => $v['search_any_frag'],
            'any_example' => $v['search_any_example'],
            'prefix_frag' => $v['search_prefix_frag'],
            'prefix_example' => $v['search_prefix_example'],
            'category' => $v['category_label'],
            'family' => $v['family_label'],
            'unit' => $v['unit'],
        ];
    }

    /** __('pos.<key>') with the category placeholders filled (plus any extra). */
    public static function t(string $key, array $extra = [], ?Company $company = null): string
    {
        $str = __('pos.' . $key, array_merge(self::replacements($company), $extra));
        return is_string($str) ? $str : $key;
    }

    /** Localized label of a category ('auth_bt_<cat>' when the lang file has it). */
    public static function categoryLabel(string $category): string
    {
        $key = 'pos.auth_bt_' . $category;
        if (Lang::has($key)) {
            return (string) __($key);
        }
        if ($category === 'general') {
            return (string) __('pos.vocab_family_general');
        }
        return PosFeatureService::presetMeta($category)['label'] ?? ucwords(str_replace('_', ' ', $category));
    }

    /** Audience families for admin pickers: value => localized label. */
    public static function audienceOptions(): array
    {
        $out = ['all' => (string) __('pos.vocab_audience_all')];
        foreach (PosCategoryProfiles::AUDIENCE_FAMILIES as $fam) {
            if ($fam !== 'all') {
                $out[$fam] = (string) __('pos.vocab_family_' . $fam);
            }
        }
        return $out;
    }

    /* ------------------------------------------------------------------ */

    private static function noun(string $slot, string $family): string
    {
        $key = 'pos.vocab_' . $slot . '_' . $family;
        $v = __($key);
        if ($v === $key) {
            $v = __('pos.vocab_' . $slot . '_general');
        }
        return is_string($v) ? $v : $slot;
    }

    private static function lower(string $s): string
    {
        // Only Latin text has a meaningful lower case; Urdu script is untouched.
        return preg_match('/^[\x00-\x7F]*$/', $s) ? mb_strtolower($s) : $s;
    }

    /** "sugar 2, oil 1" — first word of the first two examples. */
    private static function quickType(array $examples): string
    {
        $w = [];
        foreach (array_slice($examples, 0, 2) as $ex) {
            $first = preg_replace('/[^A-Za-z]/', '', Str::before(trim($ex), ' ')) ?: preg_replace('/[^A-Za-z]/', '', $ex);
            $w[] = mb_strtolower($first ?: 'item');
        }
        return ($w[0] ?? 'item') . ' 2, ' . ($w[1] ?? $w[0] ?? 'item') . ' 1';
    }

    /** A mid-word fragment (any-word mode) taken from an example's second word when possible. */
    private static function anyWordSample(array $examples): array
    {
        $ex = $examples[1] ?? $examples[0];
        $words = array_values(array_filter(preg_split('/[^A-Za-z]+/', $ex)));
        $word = null;
        foreach (array_reverse($words) as $w) {
            if (strlen($w) >= 4) {
                $word = $w;
                break;
            }
        }
        $word = $word ?? ($words[0] ?? 'item');
        $frag = mb_strtolower(substr($word, 0, min(3, strlen($word))));
        return [$frag, $ex];
    }

    /** The first three letters of the first example (starts-with mode). */
    private static function prefixSample(array $examples): array
    {
        $ex = $examples[0];
        $letters = preg_replace('/[^A-Za-z]/', '', Str::before(trim($ex), ' ')) ?: preg_replace('/[^A-Za-z]/', '', $ex);
        $frag = mb_strtolower(substr($letters ?: 'ite', 0, 3));
        return [$frag, $ex];
    }
}
