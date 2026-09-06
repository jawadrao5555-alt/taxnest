<?php
/**
 * Task 1582 dev probe: for one PRA and one FBR company, walk every business
 * category, render the shop-facing pages as that shop and report (a) non-200s,
 * (b) other families' words leaking in, (c) off-catalogue module markers, and
 * (d) that an off-catalogue route is refused with a redirect.
 *
 * DEV ONLY. Temporarily rewrites business_category / pos_module_extras of the
 * two companies and restores them at the end.
 *
 *   env -u DATABASE_URL -u DB_CONNECTION -u PGHOST -u PGPORT -u PGUSER -u PGPASSWORD -u PGDATABASE \
 *     php scripts/one-off/category-render-probe.php [pos_company_id] [fbr_company_id] [locale]
 */
require __DIR__ . '/../../vendor/autoload.php';
$app = require __DIR__ . '/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Company;
use App\Models\User;
use App\Services\PosCategoryProfiles;
use App\Services\PosFeatureService;
use App\Support\PosVocabulary;
use Illuminate\Http\Request;

if (config('database.default') !== 'mysql') { fwrite(STDERR, "mysql only\n"); exit(2); }
if (!app()->environment('local', 'development', 'staging') && !str_contains((string) config('app.url'), 'replit')) { fwrite(STDERR, "dev only\n"); exit(2); }

$posId = (int) ($argv[1] ?? 1);
$fbrId = (int) ($argv[2] ?? 16);
$locale = $argv[3] ?? 'en';

$FOOD = ['burger', 'biryani', 'fries', 'karahi', 'samosa', 'hot wings', 'chicken', 'برگر', 'بریانی'];
$MED = ['paracetamol', 'panadol', 'syrup', 'ادویات', 'دوا'];
$STOCK = ['barcode', 'بار کوڈ'];
$MARKERS = [
    // marker => module key it belongs to
    '/pos/kitchen' => 'kitchen', '/pos/tables' => 'tables', '/pos/riders' => 'riders_enabled', '/pos/deals' => 'deals_enabled',
    '/pos/khata' => 'khata_enabled', '/pos/recipes' => 'recipes', '/pos/inventory' => 'inventory',
    '/fbr-pos/riders' => 'riders_enabled', '/fbr-pos/deals' => 'deals_enabled', '/fbr-pos/khata' => 'khata_enabled',
    '/fbr-pos/inventory' => 'inventory', '/fbr-pos/pharmacy' => 'pharmacy',
];
$PAGES = [
    'pos' => ['/pos/dashboard', '/pos/customize', '/pos/features', '/pos/products', '/pos/invoice/create', '/pos/day-close', '/pos/billing', '/pos/inventory/stock-check/create', '/pos/business-profile', '/pos/receipt-settings', '/pos/tutorials'],
    'fbrpos' => ['/fbr-pos/dashboard', '/fbr-pos/customize', '/fbr-pos/products', '/fbr-pos/products/create', '/fbr-pos/create', '/fbr-pos/day-close', '/fbr-pos/billing', '/fbr-pos/tutorials'],
];
$OFFROUTE = ['pos' => ['/pos/kitchen' => 'kitchen', '/pos/riders' => 'riders_enabled', '/pos/deals' => 'deals_enabled'],
             'fbrpos' => ['/fbr-pos/riders' => 'riders_enabled', '/fbr-pos/deals' => 'deals_enabled', '/fbr-pos/khata' => 'khata_enabled']];

$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
$problems = 0;

$run = function (Company $co, string $guard, array $cats) use (&$problems, $kernel, $PAGES, $OFFROUTE, $FOOD, $MED, $STOCK, $MARKERS, $locale) {
    $panel = $guard === 'pos' ? 'pos' : 'fbrpos';
    $user = User::where('company_id', $co->id)->where(fn ($q) => $q->where('pos_role', 'pos_admin')->orWhere('role', 'like', '%admin%'))->orderBy('id')->first();
    if (!$user) { echo "!! no admin user for company {$co->id}\n"; $problems++; return; }
    $orig = ['business_category' => $co->business_category, 'pos_module_extras' => $co->getRawOriginal('pos_module_extras'), 'pos_type' => $co->pos_type, 'restaurant_mode' => $co->restaurant_mode];
    if ($only = getenv('ONLY_CAT')) { $cats = array_values(array_intersect($cats, explode(',', $only))); }
    foreach ($cats as $cat) {
        \Illuminate\Support\Facades\DB::table('companies')->where('id', $co->id)->update([
            'business_category' => $cat, 'pos_module_extras' => null,
            'feature_flags' => json_encode(PosFeatureService::defaultsForCategory($cat)),
        ]);
        PosFeatureService::flushGateCaches();
        PosVocabulary::flush();
        $fresh = Company::find($co->id);
        $fam = PosFeatureService::familyFor($fresh);
        $forbid = [];
        if (in_array($fam, ['pharmacy', 'services'])) $forbid = array_merge($forbid, $FOOD);
        if (in_array($fam, ['food_service', 'goods_retail', 'services'])) $forbid = array_merge($forbid, $MED);
        if ($fam === 'services' && !PosFeatureService::moduleRelevant($fresh, 'barcode')) $forbid = array_merge($forbid, $STOCK);
        $line = [];
        foreach ($PAGES[$panel] as $path) {
            $req = Request::create($path, 'GET', [], [], [], ['HTTP_ACCEPT' => 'text/html']);
            $req->setLaravelSession(app('session')->driver());
            auth($guard)->login($user);
            app()->instance('currentCompanyId', $co->id);
            session(['pos_locale' => $locale]);
            try {
                $res = $kernel->handle($req);
            } catch (\Throwable $e) {
                echo "!! [$panel/$cat] $path threw " . get_class($e) . ': ' . $e->getMessage() . "\n"; $problems++; continue;
            }
            $st = $res->getStatusCode();
            if ($st !== 200) { $line[] = "$path=$st" . ($st >= 300 && $st < 400 ? '→' . $res->headers->get('Location') : ''); if ($st >= 500) $problems++; continue; }
            $html = (string) $res->getContent();
            // strip <script> bodies? No — baked TXT must be clean too. Strip only HTML comments.
            $html = preg_replace('/<!--.*?-->/s', '', $html);
            // Code comments are not shop-facing: drop /* */ blocks and // line comments inside <script>/<style>.
            $html = preg_replace_callback('#<(script|style)\b[^>]*>.*?</\1>#si', function ($m) {
                $b = preg_replace('#/\*.*?\*/#s', '', $m[0]);
                return preg_replace('#(^|[^:\'"\\\\])//[^\n]*#m', '$1', $b);
            }, $html);
            $html = preg_replace('/"(barcode|inventory|kitchen|kot|tables|recipes)":/', '"x":', $html); // JSON keys are data, not copy
            $html = preg_replace('/\b(barcode|inventory|kitchen|kot|tables|recipes)\s*:\s*/', 'x: ', $html); // unquoted JS object keys
            $html = preg_replace('/[.?](barcode|inventory|kitchen|kot|tables|recipes)\b/', '.x', $html);       // JS property reads (r.barcode)
            $low = mb_strtolower($html);
            foreach ($forbid as $w) {
                if (preg_match('/(?<![\\p{L}.])' . preg_quote(mb_strtolower($w), '/') . '(?![\\p{L}])/u', $low, $mm, PREG_OFFSET_CAPTURE)) {
                    $pos = mb_strlen(substr($low, 0, $mm[0][1]));
                    $ctx = trim(preg_replace('/\s+/', ' ', mb_substr($html, max(0, $pos - 60), 140)));
                    echo "!! [$panel/$cat/$fam] $path leaks '$w': …{$ctx}…\n"; $problems++;
                }
            }
            foreach ($MARKERS as $m => $mod) {
                if (!PosFeatureService::moduleRelevant($fresh, $mod) && preg_match('#href="[^"]*' . preg_quote($m, '#') . '(?:[/"?])#', $html)) {
                    echo "!! [$panel/$cat/$fam] $path links off-catalogue $m ($mod)\n"; $problems++;
                }
            }
            auth($guard)->logout();
        }
        foreach ($OFFROUTE[$panel] as $path => $mod) {
            if (PosFeatureService::moduleRelevant($fresh, $mod)) continue;
            $req = Request::create($path, 'GET', [], [], [], ['HTTP_ACCEPT' => 'text/html']);
            $req->setLaravelSession(app('session')->driver());
            auth($guard)->login($user);
            app()->instance('currentCompanyId', $co->id);
            try { $res = $kernel->handle($req); } catch (\Throwable $e) { echo "!! [$panel/$cat] off-route $path threw " . $e->getMessage() . "\n"; $problems++; continue; }
            if ($res->getStatusCode() === 200) { echo "!! [$panel/$cat/$fam] off-catalogue route $path rendered 200 (should be refused)\n"; $problems++; }
            else $line[] = "off:$path=" . $res->getStatusCode();
            auth($guard)->logout();
        }
        echo "[$panel/$cat/$fam] " . (implode(' ', $line) ?: 'all 200') . "\n";
    }
    \Illuminate\Support\Facades\DB::table('companies')->where('id', $co->id)->update($orig + ['feature_flags' => $co->getRawOriginal('feature_flags')]);
    PosFeatureService::flushGateCaches();
};

$pos = Company::findOrFail($posId);
$fbr = Company::findOrFail($fbrId);
$onlyPanel = getenv('ONLY_PANEL') ?: '';
if ($onlyPanel !== 'fbrpos') $run($pos, 'pos', array_merge(PosFeatureService::PANEL_CATEGORIES['pra'], ['pharmacy', 'grocery', 'general']));
if ($onlyPanel !== 'pos') $run($fbr, 'fbrpos', array_merge(PosFeatureService::PANEL_CATEGORIES['fbr'], ['general']));

echo $problems ? "\n$problems problem(s)\n" : "\nCLEAN\n";
exit($problems ? 1 : 0);
