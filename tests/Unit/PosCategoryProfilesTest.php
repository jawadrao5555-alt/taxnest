<?php

namespace Tests\Unit;

use App\Models\Company;
use App\Services\PosCategoryProfiles;
use App\Services\PosFeatureService;
use App\Support\PosVocabulary;
use Tests\TestCase;

/**
 * Task 1582 — every business category on BOTH panels (and every legacy one)
 * must carry a complete category profile, and the profile must keep each
 * kind of shop inside its own world: features, nouns and examples.
 *
 * A new category added to PANEL_CATEGORIES / CATEGORY_DEFAULTS /
 * LEGACY_CATEGORIES without a PosCategoryProfiles::PROFILES row fails here.
 *
 * Run:
 *   env -u DATABASE_URL -u DB_CONNECTION -u PGHOST -u PGPORT -u PGUSER \
 *     -u PGPASSWORD -u PGDATABASE APP_ENV=testing DB_CONNECTION=sqlite \
 *     DB_DATABASE=':memory:' CACHE_STORE=array php vendor/bin/phpunit \
 *     tests/Unit/PosCategoryProfilesTest.php --testdox
 */
class PosCategoryProfilesTest extends TestCase
{
    /** Words that belong to ONE family and must never reach another. */
    private const FOOD_WORDS = ['burger', 'biryani', 'fries', 'karahi', 'pizza', 'samosa', 'chicken', 'wings', 'kitchen', 'برگر', 'بریانی', 'مینو', 'menu'];
    private const MEDICINE_WORDS = ['medicine', 'tablet', 'syrup', 'paracetamol', 'pharmacy', 'dawai', 'ادویات', 'دوا', 'فارمیسی'];
    private const STOCK_WORDS = ['barcode', 'shelf', 'stock', 'اسٹاک', 'شیلف'];

    protected function setUp(): void
    {
        parent::setUp();
        PosFeatureService::flushGateCaches();
        PosFeatureService::assumeExtrasColumn(true);
        PosVocabulary::flush();
    }

    protected function tearDown(): void
    {
        PosFeatureService::assumeExtrasColumn(null);
        PosVocabulary::flush();
        app()->setLocale('en');
        parent::tearDown();
    }

    private function shop(string $category, string $productType = 'pos', array $extras = []): Company
    {
        static $n = 900000;
        $c = new Company();
        $c->id = ++$n;
        $c->name = 'Profile Test ' . $category;
        $c->product_type = $productType;
        $c->business_category = $category;
        $c->feature_flags = PosFeatureService::defaultsForCategory($category);
        $c->restaurant_enabled = true;
        $c->pharmacy_enabled = true;
        $c->is_internal_account = true; // every plan gate open → only relevance decides
        if ($extras) {
            $c->pos_module_extras = $extras;
        }
        return $c;
    }

    /** @return array<int,array{0:string,1:string}> [category, product_type] */
    private function matrix(): array
    {
        $rows = [];
        foreach (PosFeatureService::PANEL_CATEGORIES as $panel => $cats) {
            $pt = $panel === 'fbr' ? 'fbrpos' : 'pos';
            foreach ($cats as $cat) {
                $rows[] = [$cat, $pt];
            }
        }
        foreach (array_keys(PosFeatureService::LEGACY_CATEGORIES) as $cat) {
            $rows[] = [$cat, 'pos'];
            $rows[] = [$cat, 'fbrpos'];
        }
        return $rows;
    }

    public function test_every_offered_and_legacy_category_has_a_complete_profile(): void
    {
        $known = PosCategoryProfiles::knownModules();
        foreach (PosCategoryProfiles::requiredCategories() as $cat) {
            $this->assertTrue(PosCategoryProfiles::has($cat), "Category '{$cat}' has no profile in PosCategoryProfiles::PROFILES");
            $family = PosCategoryProfiles::family($cat);
            $this->assertContains($family, PosCategoryProfiles::FAMILIES, "{$cat}: unknown family {$family}");

            foreach (['pra', 'fbr'] as $panel) {
                $profile = PosCategoryProfiles::profile($cat, $panel);
                $this->assertSame([], array_values(array_diff($profile['modules'], $known)), "{$cat}/{$panel}: profile references unknown module keys");
                foreach (PosCategoryProfiles::CORE_MODULES as $core) {
                    $this->assertContains($core, $profile['modules'], "{$cat}/{$panel}: core module {$core} missing");
                }
                // The signup preset can never switch on something its own profile hides.
                $defaults = array_keys(array_filter(PosFeatureService::allCategoryDefaults()[$cat] ?? []));
                $this->assertSame([], array_values(array_diff($defaults, $profile['modules'])), "{$cat}/{$panel}: preset turns on modules outside its own profile");

                $this->assertGreaterThanOrEqual(3, count($profile['examples']), "{$cat}: needs 3-4 example items");
                $this->assertLessThanOrEqual(4, count($profile['examples']));
                $this->assertNotEmpty($profile['unit']);
                $this->assertContains($profile['unit'], $profile['units'], "{$cat}/{$panel}: default unit not in unit list");
                $this->assertNotEmpty($profile['order']);
                $this->assertNotEmpty($profile['checklist']);
                $this->assertNotEmpty($profile['tiles']);
                foreach ($profile['audiences'] as $aud) {
                    $this->assertContains($aud, PosCategoryProfiles::AUDIENCE_FAMILIES, "{$cat}: unknown audience {$aud}");
                    $this->assertNotSame('all', $aud);
                }
            }
        }
        // And no orphan profile pointing at a category nobody can be on.
        foreach (array_keys(PosCategoryProfiles::PROFILES) as $cat) {
            $this->assertContains($cat, PosCategoryProfiles::requiredCategories(), "Profile '{$cat}' names a category no panel offers");
        }
    }

    public function test_every_family_has_its_nouns_in_all_three_languages(): void
    {
        foreach (['en', 'rur', 'ur'] as $locale) {
            $map = require base_path("lang/{$locale}/pos.php");
            foreach (PosCategoryProfiles::FAMILIES as $family) {
                foreach (PosVocabulary::NOUN_SLOTS as $slot) {
                    $key = "vocab_{$slot}_{$family}";
                    $this->assertArrayHasKey($key, $map, "{$locale}: missing {$key}");
                    $this->assertNotSame('', trim((string) $map[$key]));
                }
            }
            $this->assertArrayHasKey('vocab_audience_all', $map);
            $this->assertArrayHasKey('feature_not_for_business', $map);
        }
    }

    public function test_families_keep_their_feature_worlds_apart(): void
    {
        $pharmacy = $this->shop('pharmacy', 'fbrpos');
        foreach (['kot', 'tables', 'kitchen', 'recipes', 'qr_menu_enabled', 'deals_enabled', 'kot_enabled'] as $k) {
            $this->assertFalse(PosFeatureService::moduleRelevant($pharmacy, $k), "pharmacy must not see {$k}");
        }
        foreach (['pharmacy', 'prescription', 'barcode', 'inventory', 'pharmacy_enabled', 'khata_enabled'] as $k) {
            $this->assertTrue(PosFeatureService::moduleRelevant($pharmacy, $k), "pharmacy must see {$k}");
        }

        $restaurant = $this->shop('restaurant', 'pos');
        foreach (['barcode', 'pharmacy', 'prescription', 'batch_expiry', 'loose_sale', 'bulk_pricing', 'pharmacy_enabled', 'service_jobs'] as $k) {
            $this->assertFalse(PosFeatureService::moduleRelevant($restaurant, $k), "restaurant must not see {$k}");
        }
        foreach (['kot', 'tables', 'kitchen', 'recipes', 'delivery', 'deals_enabled', 'riders_enabled', 'qr_menu_enabled'] as $k) {
            $this->assertTrue(PosFeatureService::moduleRelevant($restaurant, $k), "restaurant must see {$k}");
        }

        $salon = $this->shop('salon', 'pos');
        foreach (['kot', 'tables', 'kitchen', 'recipes', 'inventory', 'barcode', 'delivery', 'riders_enabled', 'deals_enabled', 'qr_menu_enabled', 'kot_enabled', 'pharmacy_enabled'] as $k) {
            $this->assertFalse(PosFeatureService::moduleRelevant($salon, $k), "salon must not see {$k}");
        }
        foreach (['service_jobs', 'customer_profile', 'customer_loyalty', 'loyalty_enabled', 'reports_enabled', 'hazri_enabled'] as $k) {
            $this->assertTrue(PosFeatureService::moduleRelevant($salon, $k), "salon must see {$k}");
        }

        $grocery = $this->shop('grocery', 'fbrpos');
        foreach (['kot', 'tables', 'kitchen', 'recipes', 'qr_menu_enabled', 'pharmacy', 'pharmacy_enabled', 'service_jobs'] as $k) {
            $this->assertFalse(PosFeatureService::moduleRelevant($grocery, $k), "grocery must not see {$k}");
        }
        foreach (['barcode', 'inventory', 'bulk_pricing', 'khata_enabled', 'riders_enabled', 'kot_enabled', 'kitchen_notes'] as $k) {
            $this->assertTrue(PosFeatureService::moduleRelevant($grocery, $k), "FBR grocery must see {$k} (store slip family)");
        }
        // The same goods shop on the PRA panel reads those flags as a kitchen — hidden there.
        $praGrocery = $this->shop('grocery', 'pos');
        $this->assertFalse(PosFeatureService::moduleRelevant($praGrocery, 'kot_enabled'));
        $this->assertFalse(PosFeatureService::moduleRelevant($praGrocery, 'kitchen_notes'));

        // Unclassified: nothing hidden.
        $general = $this->shop('general', 'pos');
        foreach (PosCategoryProfiles::knownModules() as $k) {
            $this->assertTrue(PosFeatureService::moduleRelevant($general, $k), "general must see {$k}");
        }
        $unknown = new Company();
        $unknown->id = 899999;
        $unknown->product_type = 'fbrpos';
        $unknown->business_category = null;
        $this->assertSame('general', PosFeatureService::profileCategory($unknown));
    }

    public function test_masking_predicate_and_plan_gate_inherit_relevance(): void
    {
        // A salon whose stored flags say "kitchen on" (e.g. an old preset) is
        // still a salon: the resolved map masks it OFF and the gate refuses it.
        $salon = $this->shop('salon', 'pos');
        $flags = $salon->feature_flags;
        $flags['kot'] = $flags['kitchen'] = $flags['inventory'] = true;
        $salon->feature_flags = $flags;
        $f = PosFeatureService::forCompany($salon);
        $this->assertFalse((bool) $f->kitchen);
        $this->assertFalse((bool) $f->inventory);
        $this->assertTrue((bool) $f->service_jobs);
        $this->assertFalse(PosFeatureService::moduleAvailable($salon, 'kitchen'));
        $this->assertFalse(PosFeatureService::planAllows($salon, 'riders_enabled'), 'internal account, but riders are not for a salon');
        $this->assertTrue(PosFeatureService::planAllows($salon, 'reports_enabled'));
        $this->assertFalse(PosFeatureService::moduleAvailable($salon, 'riders_enabled'));
    }

    public function test_admin_extra_and_grandfathered_modules_unhide(): void
    {
        $salon = $this->shop('salon', 'pos', [
            'riders_enabled' => ['source' => 'admin', 'reason' => 'runs home-service staff', 'by' => 'admin'],
            'inventory' => ['source' => 'grandfathered', 'reason' => 'flag stored ON'],
        ]);
        $flags = $salon->feature_flags;
        $flags['inventory'] = true;
        $salon->feature_flags = $flags;

        $this->assertTrue(PosFeatureService::moduleRelevant($salon, 'riders_enabled'));
        $this->assertTrue(PosFeatureService::planAllows($salon, 'riders_enabled'));
        $this->assertTrue((bool) PosFeatureService::forCompany($salon)->inventory);
        $this->assertFalse(PosFeatureService::moduleRelevant($salon, 'kot'), 'an extra unhides only itself');
        $this->assertSame(['riders_enabled', 'inventory'], array_keys(PosFeatureService::extraModules($salon)));
    }

    public function test_predicate_stays_dormant_until_the_extras_column_exists(): void
    {
        PosFeatureService::assumeExtrasColumn(false);
        $salon = $this->shop('salon', 'pos');
        $this->assertTrue(PosFeatureService::moduleRelevant($salon, 'kot'), 'no column = nothing grandfathered yet = hide nothing');
        $this->assertTrue(PosFeatureService::planAllows($salon, 'riders_enabled'));
    }

    public function test_audience_families_reach_the_right_shops(): void
    {
        $this->assertTrue(PosFeatureService::audienceMatches($this->shop('restaurant'), 'food_service'));
        $this->assertFalse(PosFeatureService::audienceMatches($this->shop('restaurant'), 'pharmacy'));
        $this->assertTrue(PosFeatureService::audienceMatches($this->shop('restaurant'), 'all'));
        $this->assertTrue(PosFeatureService::audienceMatches($this->shop('restaurant'), null));
        $this->assertTrue(PosFeatureService::audienceMatches($this->shop('bakery', 'fbrpos'), 'food_service'), 'bakery listens to both worlds');
        $this->assertTrue(PosFeatureService::audienceMatches($this->shop('bakery', 'fbrpos'), 'goods_retail'));
        $this->assertFalse(PosFeatureService::audienceMatches($this->shop('salon'), 'goods_retail'));
        foreach (['food_service', 'goods_retail', 'pharmacy', 'services'] as $fam) {
            $this->assertTrue(PosFeatureService::audienceMatches($this->shop('general'), $fam), 'unclassified shops hear everything');
        }
    }

    /**
     * Category × panel × locale: the vocabulary and the converted lang keys
     * never leak another family's words into a shop.
     */
    public function test_vocabulary_matrix_keeps_other_families_words_out(): void
    {
        $keys = [
            'no_active_products', 'ph_chicken_burger', 'ph_deal_desc_eg', 'quick_type_mode_sub',
            'search_mode_any_word', 'search_mode_prefix', 'ti_quick_type_f7', 'stock_check_scope_products_hint',
        ];
        foreach ($this->matrix() as [$cat, $pt]) {
            $company = $this->shop($cat, $pt);
            $family = PosFeatureService::familyFor($company);
            foreach (['en', 'rur', 'ur'] as $locale) {
                app()->setLocale($locale);
                PosVocabulary::flush();
                $v = PosVocabulary::for($company);
                $this->assertSame($family, $v['family']);
                $this->assertNotEmpty($v['item']);
                $this->assertNotEmpty($v['items']);
                $this->assertStringNotContainsString(':', $v['quick_type']);

                $text = implode(' ', array_map(fn ($k) => PosVocabulary::t($k, [], $company), $keys))
                    . ' ' . implode(' ', $v['examples']) . ' ' . $v['item'] . ' ' . $v['items'] . ' ' . $v['grid'] . ' ' . $v['counter'];
                $this->assertDoesNotMatchRegularExpression('/:(item|items|items_lower|example|example2|example3|quick_type|any_frag|any_example|prefix_frag|prefix_example|counter|list)\b/', $text, "{$cat}/{$pt}/{$locale}: unfilled placeholder");
                $lower = mb_strtolower($text);

                $forbidden = [];
                if ($family === 'pharmacy' || $family === 'services') {
                    $forbidden = array_merge($forbidden, self::FOOD_WORDS);
                }
                if ($family === 'food_service' || $family === 'goods_retail' || $family === 'services') {
                    $forbidden = array_merge($forbidden, self::MEDICINE_WORDS);
                }
                if ($family === 'services') {
                    $forbidden = array_merge($forbidden, self::STOCK_WORDS);
                }
                foreach ($forbidden as $word) {
                    $this->assertStringNotContainsString(mb_strtolower($word), $lower, "{$cat}/{$pt}/{$locale}: '{$word}' leaked into a {$family} shop");
                }
            }
        }
        app()->setLocale('en');
    }

    public function test_examples_are_brand_neutral(): void
    {
        // Real customers' shop / product names never become samples.
        $banned = ['pizza master', 'al haq', 'al rehman', 'c18', 'nestpos', 'taxnest'];
        foreach (PosCategoryProfiles::PROFILES as $cat => $p) {
            foreach ($p['examples'] ?? [] as $ex) {
                foreach ($banned as $b) {
                    $this->assertStringNotContainsStringIgnoringCase($b, $ex, "{$cat}: example '{$ex}' names a real business");
                }
            }
        }
    }
}
