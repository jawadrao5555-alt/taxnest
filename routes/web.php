<?php

use App\Http\Controllers\ProfileController;
use Illuminate\Support\Facades\Route;

// ── Stateless machine endpoints (Task 1090) ─────────────────────────────────
// Token/webhook-authenticated endpoints hit by machines (Desktop Agent every
// 5-30s per shop, rider/caller apps, biometric devices, WA webhooks). They
// live in routes/web.php so by default they get the FULL web group: cookies +
// StartSession + session-dependent middleware. With SESSION_DRIVER=database on
// live, every such request (axios keeps no cookies) did a sessions SELECT +
// a brand-new sessions INSERT + gc-lottery DELETEs — thousands of garbage
// session rows and 3 extra DB statements per poll. During the 17 Aug 2026
// night rush this contributed to MySQL "Too many connections" (1040).
// Stripping session/cookie middleware makes these requests session-free.
// NOTE: the impersonation/consultant/locale middleware below call
// $request->session() unguarded, so they MUST come off together with
// StartSession or every request would 500.
$statelessMachine = [
    \Illuminate\Cookie\Middleware\EncryptCookies::class,
    \Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse::class,
    \Illuminate\Session\Middleware\StartSession::class,
    \Illuminate\View\Middleware\ShareErrorsFromSession::class,
    \Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class,
    \App\Http\Middleware\ReadOnlyImpersonation::class,
    \App\Http\Middleware\LogImpersonatedWrites::class,
    \App\Http\Middleware\ConsultantSwitchGuard::class,
    \App\Http\Middleware\SetPosLocale::class,
];
use Illuminate\Support\Facades\Artisan;
use App\Http\Controllers\InvoiceController;
use App\Http\Controllers\AdminController;
use App\Http\Controllers\BillingController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\ComplianceCertificateController;
use App\Http\Controllers\ComplianceController;
use App\Http\Controllers\RiskReportController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\MISController;
use App\Http\Controllers\HsMasterExportController;
use App\Http\Controllers\GlobalHsMasterController;
use App\Http\Controllers\SroReferenceController;
use App\Http\Controllers\Admin\HsMasterController;
use App\Http\Controllers\ShareController;
use App\Http\Controllers\CompanyUserController;
use App\Http\Controllers\CompanyConsultantController;
use App\Http\Controllers\ConsultantConsoleController;
use App\Http\Controllers\CompanySettingsController;
use App\Http\Controllers\CustomerLedgerController;
use App\Http\Controllers\PosController;
use App\Http\Controllers\RestaurantPosController;
use App\Http\Controllers\RestaurantTableController;
use App\Http\Controllers\RestaurantKdsController;
use App\Http\Controllers\IngredientController;
use App\Http\Controllers\PosInventoryController;
use App\Http\Controllers\PosAuthController;
use App\Http\Controllers\HsCodeMappingController;
use App\Http\Controllers\BranchController;
use App\Http\Controllers\BranchSwitchController;
use App\Http\Controllers\OnboardingController;
use App\Http\Controllers\TaxOverrideController;
use App\Http\Controllers\CustomerProfileController;
use App\Http\Controllers\WhtReportController;
use App\Http\Controllers\CsvImportController;
use App\Http\Controllers\InvoiceImportController;
use App\Http\Controllers\AiInvoiceReaderController;
use App\Http\Controllers\FbrPosController;
use App\Http\Controllers\FbrPosAuthController;
use App\Http\Controllers\InventoryController;
use App\Http\Controllers\SupplierController;
use App\Http\Controllers\AnnouncementController;

// Audit Pack ZIP via temporary signed URL from the "pack ready" email — no login
// needed. 'signed' middleware 403s tampered/expired links (expiry = pack retention).
Route::get('/compliance/audit-packs/{pack}/download-signed', [ComplianceController::class, 'downloadSigned'])
    ->middleware('signed')
    ->name('compliance.packs.download-signed');

Route::get('/share/invoice/{uuid}', [ShareController::class, 'show']);
Route::get('/share/invoice/{uuid}/pdf', [ShareController::class, 'pdf'])->name('share.invoice.pdf');

// Meta WhatsApp Cloud API status webhook (per-company; public + CSRF-exempt).
Route::get('/webhooks/whatsapp/{company}', [\App\Http\Controllers\WhatsAppWebhookController::class, 'verify'])->whereNumber('company')->withoutMiddleware($statelessMachine);
Route::post('/webhooks/whatsapp/{company}', [\App\Http\Controllers\WhatsAppWebhookController::class, 'receive'])->whereNumber('company')->withoutMiddleware($statelessMachine);

Route::get('/demo-login/{role}', [\App\Http\Controllers\Auth\AuthenticatedSessionController::class, 'demoLogin'])
    ->where('role', 'super_admin|company_admin|demo');

Route::get('/health', function () {
    $replitdbContent = '';
    if (file_exists('/tmp/replitdb')) {
        $raw = trim(file_get_contents('/tmp/replitdb'));
        if (preg_match('/^(postgres(ql)?:\/\/[^:]+:[^@]+@)([^\/]+)(\/\S+)/', $raw, $m)) {
            $replitdbContent = 'postgres_url_host=' . $m[3];
        } else {
            $replitdbContent = substr($raw, 0, 30) . '...';
        }
    }
    $dbUrlGetenv = getenv('DATABASE_URL') ?: 'empty';
    $dbUrlPhpEnv = $_ENV['DATABASE_URL'] ?? 'empty';
    $dbUrlServer = $_SERVER['DATABASE_URL'] ?? 'empty';
    $dbUrlLaravel = env('DATABASE_URL') ?: 'empty';
    $dbUrlFile = file_exists('/tmp/prod_database_url') ? trim(file_get_contents('/tmp/prod_database_url')) : 'empty';
    $dbUrlSources = [
        'getenv' => ($dbUrlGetenv !== 'empty') ? 'set' : 'empty',
        '_ENV' => ($dbUrlPhpEnv !== 'empty') ? 'set' : 'empty',
        '_SERVER' => ($dbUrlServer !== 'empty') ? 'set' : 'empty',
        'laravel_env' => ($dbUrlLaravel !== 'empty') ? 'set' : 'empty',
        'file_dump' => ($dbUrlFile !== 'empty' && !empty($dbUrlFile)) ? 'set' : 'empty',
    ];
    $foundUrl = null;
    foreach ([$dbUrlGetenv, $dbUrlPhpEnv, $dbUrlServer, $dbUrlLaravel] as $candidate) {
        if ($candidate !== 'empty' && preg_match('/^postgres(ql)?:\/\//', $candidate)) {
            $foundUrl = $candidate;
            break;
        }
    }
    $resolvedHost = 'none';
    if ($foundUrl) {
        $p = parse_url(preg_replace('/^postgres:\/\//', 'postgresql://', $foundUrl));
        $resolvedHost = $p['host'] ?? 'parse_fail';
    }
    $info = [
        'status' => 'ok',
        'db_host' => config('database.connections.pgsql.host'),
        'db_port' => config('database.connections.pgsql.port'),
        'db_name' => config('database.connections.pgsql.database'),
        'db_user' => config('database.connections.pgsql.username'),
        'session_driver' => config('session.driver'),
        'cache_driver' => config('cache.default'),
        'config_cached' => file_exists(base_path('bootstrap/cache/config.php')),
        'replitdb_exists' => file_exists('/tmp/replitdb'),
        'replitdb_content' => $replitdbContent,
        'database_url_sources' => $dbUrlSources,
        'resolved_db_host' => $resolvedHost,
        'pghost_getenv' => getenv('PGHOST') ?: 'not_set',
        'db_host_getenv' => getenv('DB_HOST') ?: 'not_set',
    ];
    try {
        $start = microtime(true);
        \DB::connection()->getPdo();
        $info['db'] = 'connected';
        $info['db_time'] = round((microtime(true) - $start) * 1000) . 'ms';
    } catch (\Exception $e) {
        $info['db'] = 'failed';
        $info['db_error'] = substr($e->getMessage(), 0, 300);
    }
    return response()->json($info);
});

Route::get('/', function () {
    // Perf (Jul 2026): these 4 COUNTs over big tables cost ~250ms on EVERY
    // landing hit — cache 10 min (marketing stats don't need to be live).
    try {
        $stats = \Illuminate\Support\Facades\Cache::remember('landing_stats', 600, function () {
            return [
                'total_invoices' => \App\Models\Invoice::where('status', 'locked')->count()
                    + \App\Models\PosTransaction::where('pra_status', 'success')->count()
                    + \App\Models\FbrPosTransaction::where('fbr_status', 'success')->count(),
                'total_companies' => \App\Models\Company::where('status', 'approved')->count(),
            ];
        });
    } catch (\Throwable $e) {
        // DB down: show zeros for THIS request only — never cache zeros for 10 min.
        \Log::warning('Landing stats unavailable: ' . $e->getMessage());
        $stats = ['total_invoices' => 0, 'total_companies' => 0];
    }

    return view('landing', [
        'showLogin' => false,
        'stats' => $stats,
    ]);
});

Route::get('/contact', fn () => view('contact'))->name('contact');

Route::get('/sitemap.xml', function () {
    $urls = [
        ['loc' => url('/'),                'priority' => '1.0', 'changefreq' => 'weekly'],
        ['loc' => url('/pos'),             'priority' => '0.9', 'changefreq' => 'monthly'],
        ['loc' => url('/fbr-pos-landing'), 'priority' => '0.9', 'changefreq' => 'monthly'],
        ['loc' => url('/digital-invoice'), 'priority' => '0.9', 'changefreq' => 'monthly'],
        ['loc' => url('/tutorials'),       'priority' => '0.8', 'changefreq' => 'weekly'],
        ['loc' => url('/download'),        'priority' => '0.7', 'changefreq' => 'monthly'],
        ['loc' => url('/contact'),         'priority' => '0.6', 'changefreq' => 'yearly'],
    ];
    return response()->view('sitemap', compact('urls'))
        ->header('Content-Type', 'application/xml');
})->name('sitemap');

// Public downloads hub — /download (singular) on purpose: /downloads/ is a real
// directory in public/ (APKs, agent zip) and Apache serves existing dirs
// directly, so that path never reaches Laravel.
Route::get('/download', fn () => view('downloads'))->name('downloads.page');

// In-app update check for the Android shells (Task #443) — public, stateless
// JSON. Each app calls this on launch with its own key and compares the
// returned `latest` against its installed versionName; empty latest = no
// update prompt (owner hasn't released / flipped the version yet).
Route::get('/api/app-version', function () {
    $map = [
        'pos'    => ['setting' => 'pos_app_latest_version',    'apk' => 'downloads/taxnest-pos.apk'],
        'fbrpos' => ['setting' => 'fbrpos_app_latest_version', 'apk' => 'downloads/taxnest-fbr-pos.apk'],
        'waiter' => ['setting' => 'waiter_app_latest_version', 'apk' => 'downloads/taxnest-waiter.apk'],
        'rider'  => ['setting' => 'rider_app_latest_version',  'apk' => 'downloads/taxnest-rider.apk'],
        'di'     => ['setting' => 'di_app_latest_version',     'apk' => 'downloads/taxnest-di.apk'],
    ];
    $app = (string) request()->query('app', '');
    if (!isset($map[$app])) {
        return response()->json(['ok' => false, 'error' => 'unknown app'], 404);
    }
    $latest = trim((string) \App\Models\SystemSetting::get($map[$app]['setting'], ''));
    return response()->json([
        'ok'     => true,
        'app'    => $app,
        'latest' => $latest,
        'apk_url'=> url($map[$app]['apk']),
    ]);
})->name('api.app-version');
Route::get('/download/agent', [\App\Http\Controllers\AgentManagementController::class, 'downloadAgent'])->name('public.agent.download');

// Urdu tutorial video library (owner request, 2 Aug 2026) — public page,
// linked from the marketing top nav. In-app twin lives at /pos/tutorials.
Route::get('/tutorials', [\App\Http\Controllers\TutorialController::class, 'publicIndex'])->name('tutorials.page');

Route::get('/digital-invoice', function () {
    $plans = \App\Models\PricingPlan::where('is_trial', false)->where('product_type', 'di')->orderBy('price')->get();
    return view('di-landing', ['plans' => $plans]);
})->name('di.landing');

Route::redirect('/di', '/digital-invoice', 301);

Route::get('/pos', function () {
    $plans = \App\Models\PricingPlan::where('is_trial', false)->where('product_type', 'pos')->orderBy('price')->get();
    return view('pos.landing', ['plans' => $plans]);
})->name('pos.landing');
Route::get('/pos/login', [PosAuthController::class, 'showLogin'])->name('pos.login');
Route::post('/pos/login', [PosAuthController::class, 'login']);
Route::get('/pos/register', [PosAuthController::class, 'showRegister'])->name('pos.register');
Route::post('/pos/register', [PosAuthController::class, 'register']);
Route::post('/pos/logout', [PosAuthController::class, 'logout'])->name('pos.logout');
// Guest language picker on login/register (Aug 2026): session-only choice —
// SetPosLocale then follows it on all guest pages incl. root forgot/reset-password.
Route::post('/pos/guest-language', function (\Illuminate\Http\Request $request) {
    $lang = $request->input('language');
    if (\App\Support\PosLocale::isValid($lang)) {
        $request->session()->put(\App\Support\PosLocale::SESSION_KEY, $lang);
    }
    return back();
})->name('pos.guest-language');

Route::get('/pos/invoice/share/{token}', [PosController::class, 'publicInvoicePdf'])->name('pos.invoice.share');

// Customer live tracking (Task 1105) — PUBLIC tokenized "aapka rider yahan
// hai" page + poll. Token = 48-char random tied to ONE bill; both endpoints
// re-check the company's plan on every call (downgrade kills live links) and
// flip to a read-only "Delivered" state once the bill closes. Throttled
// per-IP (tokens stay unenumerable); stateless — no session-row churn from
// customers' phones polling every 10s.
Route::get('/track/{token}', [\App\Http\Controllers\PosRiderTrackingController::class, 'publicTrackPage'])
    ->where('token', '[A-Za-z0-9]{20,64}')
    ->middleware('throttle:60,1')->withoutMiddleware($statelessMachine)
    ->name('pos.track.public');
Route::get('/track/{token}/data', [\App\Http\Controllers\PosRiderTrackingController::class, 'publicTrackData'])
    ->where('token', '[A-Za-z0-9]{20,64}')
    ->middleware('throttle:60,1')->withoutMiddleware($statelessMachine)
    ->name('pos.track.public.data');

// Biometric ADMS push endpoint (4 Aug 2026) — PUBLIC, no POS auth.
// ZKTeco and compatible devices call /bio-sync/{token}/iclock/cdata.
// Token identifies + scopes the company; no session/CSRF needed.
Route::get('/bio-sync/{token}/iclock/cdata', [\App\Http\Controllers\PosBiometricController::class, 'admsHandshake'])->name('pos.bio-sync.adms-get')->withoutMiddleware($statelessMachine);
Route::post('/bio-sync/{token}/iclock/cdata', [\App\Http\Controllers\PosBiometricController::class, 'admsReceivePunches'])->name('pos.bio-sync.adms-post')->withoutMiddleware($statelessMachine);
// Root ADMS endpoints (4 Aug 2026) — K50/K40-class firmware only accepts a bare
// server address (no URL path), so those devices push to /iclock/cdata at the
// domain root. Device is identified by ?SN= (must be pre-registered on /pos/bio-sync).
// Throttled per-IP: devices poll every ~30-60s + push bursts; 120/min is generous
// for real hardware but blocks SN-enumeration scans and punch-flood abuse.
Route::middleware('throttle:120,1')->withoutMiddleware($statelessMachine)->group(function () {
    Route::get('/iclock/cdata', [\App\Http\Controllers\PosBiometricController::class, 'admsHandshakeBySn'])->name('pos.bio-sync.adms-root-get');
    Route::post('/iclock/cdata', [\App\Http\Controllers\PosBiometricController::class, 'admsReceivePunchesBySn'])->name('pos.bio-sync.adms-root-post');
    Route::match(['get', 'post'], '/iclock/getrequest', [\App\Http\Controllers\PosBiometricController::class, 'admsNoCommand'])->name('pos.bio-sync.adms-getrequest');
    Route::match(['get', 'post'], '/iclock/devicecmd', [\App\Http\Controllers\PosBiometricController::class, 'admsNoCommand'])->name('pos.bio-sync.adms-devicecmd');
});

// ═══ Local Bills Archive Portal ═══
// Isolated read-only portal for users with pos_role='archive_viewer'. Same /pos/login URL
// (auto-detected). PosAuth middleware confines archive_viewer to /pos/archive/* and
// blocks every other pos_role from these routes (404). Account created/managed only
// by SaaS super-admin from /admin/companies/{id}.
Route::middleware(['pos.auth'])->prefix('pos/archive')->group(function () {
    Route::get('/', [\App\Http\Controllers\PosArchiveController::class, 'index'])->name('pos.archive.index');
    Route::get('/export', [\App\Http\Controllers\PosArchiveController::class, 'exportCsv'])->name('pos.archive.export');
    Route::get('/{id}', [\App\Http\Controllers\PosArchiveController::class, 'show'])->whereNumber('id')->name('pos.archive.show');
});

// ═══ Local Bills Portal ═══
// Isolated read-only portal for users with pos_role='local_viewer'. Same /pos/login URL
// (auto-detected). PosAuth middleware confines local_viewer to /pos/local-bills/* and
// blocks every other pos_role from these routes (404). This is the ONLY surface where
// local (non-PRA) bills are visible. Account created/managed only by SaaS super-admin.
Route::middleware(['pos.auth'])->prefix('pos/local-bills')->group(function () {
    Route::get('/', [\App\Http\Controllers\PosLocalBillsController::class, 'index'])->name('pos.local.index');
    Route::get('/export', [\App\Http\Controllers\PosLocalBillsController::class, 'exportCsv'])->name('pos.local.export');
    Route::get('/{id}', [\App\Http\Controllers\PosLocalBillsController::class, 'show'])->whereNumber('id')->name('pos.local.show');
});

// Branch switcher — accessible by ANY authenticated guard (web/pos/fbrpos)
Route::middleware('web')->post('/branch/switch', [BranchSwitchController::class, 'switch'])->name('branch.switch');

Route::middleware(['auth', 'company', 'rate_limit_company', 'company.approval'])->group(function () {

    Route::get('/onboarding', [OnboardingController::class, 'index'])->name('onboarding');
    Route::post('/onboarding/complete', [OnboardingController::class, 'complete']);
    Route::post('/onboarding/skip', [OnboardingController::class, 'skip']);

    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');

    // ── Tax Consultant Console (any DI web user can become a consultant) ──
    // Linking is consent-based ONLY: redeem a client-generated invite code, or
    // send a request the client admin must approve. Switch = audited login as
    // the client's admin user, watched by ConsultantSwitchGuard middleware.
    Route::prefix('consultant')->group(function () {
        Route::get('/', [ConsultantConsoleController::class, 'index'])->name('consultant.console');
        Route::get('/earnings', [ConsultantConsoleController::class, 'earnings'])->name('consultant.earnings');
        Route::post('/payout-details', [ConsultantConsoleController::class, 'savePayoutDetails'])->name('consultant.payout-details');
        Route::post('/join', [ConsultantConsoleController::class, 'join'])->name('consultant.join');
        Route::post('/redeem', [ConsultantConsoleController::class, 'redeem'])->middleware('throttle:10,1')->name('consultant.redeem');
        Route::post('/request', [ConsultantConsoleController::class, 'requestLink'])->middleware('throttle:10,1')->name('consultant.request');
        Route::post('/links/{link}/cancel', [ConsultantConsoleController::class, 'cancel'])->name('consultant.cancel');
        Route::post('/links/{link}/revoke', [ConsultantConsoleController::class, 'revoke'])->name('consultant.revoke');
        Route::post('/switch/{company}', [ConsultantConsoleController::class, 'switchIn'])->name('consultant.switch');
    });
    Route::post('/notifications/{id}/dismiss', [DashboardController::class, 'dismissNotification'])->name('notifications.dismiss');
    Route::post('/notifications/dismiss-all', [DashboardController::class, 'dismissAllNotifications'])->name('notifications.dismiss-all');

    Route::get('/billing/plans', [BillingController::class, 'plans'])->name('billing.plans');
    Route::post('/billing/subscribe', [BillingController::class, 'subscribe']);
    Route::post('/api/billing/calculate', [BillingController::class, 'calculatePrice']);
    Route::get('/billing/custom-plan', [BillingController::class, 'customPlanBuilder']);
    Route::post('/billing/calculate-custom', [BillingController::class, 'calculateCustomPlan']);
    Route::post('/billing/subscribe-custom', [BillingController::class, 'subscribeCustomPlan']);

    Route::get('/invoices', [InvoiceController::class, 'index'])->name('invoices.index');
    Route::get('/invoices/unique-buyers', [InvoiceController::class, 'uniqueBuyers'])->name('invoices.unique-buyers');
    Route::get('/invoices/bulk-pdf', [InvoiceController::class, 'bulkDownloadPdf'])->name('invoices.bulk-pdf');
    Route::post('/invoices/toggle-wht-pdf', function (\Illuminate\Http\Request $r) {
        $on = $r->boolean('on');
        session(['pdf_show_wht' => $on]);
        return response()->json(['status' => 'ok', 'pdf_show_wht' => $on]);
    })->name('invoices.toggle-wht-pdf');

    Route::middleware(['role:company_admin,employee'])->group(function () {
        Route::get('/invoice/create', [InvoiceController::class, 'create'])->name('invoice.create');
        Route::post('/invoice/store', [InvoiceController::class, 'store'])->middleware('plan.limit:invoices');
        Route::get('/invoice/{invoice}/edit', [InvoiceController::class, 'edit']);
        Route::put('/invoice/{invoice}', [InvoiceController::class, 'update']);
        Route::post('/invoice/{invoice}/submit', [InvoiceController::class, 'submit']);
        Route::post('/invoice/{invoice}/retry', [InvoiceController::class, 'retry']);
        Route::post('/invoice/{invoice}/resubmit-fbr', [InvoiceController::class, 'resubmitToFbr']);
        Route::post('/invoice/{invoice}/validate', [InvoiceController::class, 'validateInvoice']);
        Route::post('/invoice/{invoice}/validate-fbr', [InvoiceController::class, 'validateFbrPayload']);
        Route::post('/invoice/{invoice}/confirm-fbr', [InvoiceController::class, 'confirmFbrStatus']);
        Route::post('/invoice/{invoice}/update-fbr-number', [InvoiceController::class, 'updateFbrNumber']);
        Route::post('/invoice/{invoice}/duplicate', [InvoiceController::class, 'duplicate'])->name('invoice.duplicate');
        Route::delete('/invoice/{invoice}', [InvoiceController::class, 'destroy'])->name('invoice.destroy');

        // Buyer ko invoice bhejna (Email / WhatsApp wa.me) — sab plans, no premium gate.
        Route::get('/invoice/{invoice}/send-info', [\App\Http\Controllers\InvoiceSendController::class, 'info'])->name('invoice.send-info');
        Route::post('/invoice/{invoice}/send-email', [\App\Http\Controllers\InvoiceSendController::class, 'sendEmail'])->name('invoice.send-email')->middleware('throttle:12,1');
        Route::post('/invoice/{invoice}/send-whatsapp', [\App\Http\Controllers\InvoiceSendController::class, 'sendWhatsApp'])->name('invoice.send-whatsapp')->middleware('throttle:30,1');

        Route::get('/invoices/csv-template', [CsvImportController::class, 'template'])->name('invoices.csv-template');
        Route::post('/invoices/csv-upload', [CsvImportController::class, 'upload'])->name('invoices.csv-upload');
        Route::post('/invoices/csv-process', [CsvImportController::class, 'process'])->name('invoices.csv-process');

        // Bulk import v2: .xlsx template + pre-validation + background processing.
        Route::get('/invoices/import-template', [InvoiceImportController::class, 'template'])->name('invoices.import-template');
        Route::post('/invoices/import-upload', [InvoiceImportController::class, 'upload'])->name('invoices.import-upload');
        Route::post('/invoices/import/{batchId}/process', [InvoiceImportController::class, 'process'])->middleware('plan.limit:invoices')->name('invoices.import-process');
        Route::get('/invoices/import-history', [InvoiceImportController::class, 'history'])->name('invoices.import-history');
        Route::get('/invoices/import/{batchId}/status', [InvoiceImportController::class, 'status'])->name('invoices.import-status');
        Route::get('/invoices/import/{batchId}/error-report', [InvoiceImportController::class, 'errorReport'])->name('invoices.import-error-report');

        // Task 142: AI Invoice Reader (Premium gate 'ai_reader') — upload old/supplier
        // invoice (PDF/photo/Excel) -> AI extraction -> review -> save DRAFT only.
        Route::get('/invoices/ai-reader', [AiInvoiceReaderController::class, 'show'])->name('invoices.ai-reader');
        Route::post('/invoices/ai-reader/parse', [AiInvoiceReaderController::class, 'parse'])
            ->middleware('throttle:6,1')
            ->name('invoices.ai-reader.parse');

        Route::get('/customers', [CustomerLedgerController::class, 'index'])->name('customers.index');
        Route::get('/customers/{ntn}/ledger', [CustomerLedgerController::class, 'show']);
        Route::post('/customers/payment', [CustomerLedgerController::class, 'addPayment']);
        Route::post('/customers/adjustment', [CustomerLedgerController::class, 'addAdjustment']);

        Route::get('/sro-reference', [SroReferenceController::class, 'index'])->name('sro-reference');
        Route::get('/api/sro-reference/search', [SroReferenceController::class, 'apiSearch'])->name('sro-reference.search');

        Route::get('/products', [ProductController::class, 'index'])->name('products.index');
        Route::get('/products/create', [ProductController::class, 'create'])->name('products.create');
        Route::post('/products', [ProductController::class, 'store'])->name('products.store')->middleware('plan.limit:products');
        Route::get('/products/{product}/edit', [ProductController::class, 'edit'])->name('products.edit');
        Route::put('/products/{product}', [ProductController::class, 'update'])->name('products.update');
        Route::post('/products/{product}/toggle', [ProductController::class, 'deactivate'])->name('products.toggle');
        Route::delete('/products/{product}', [ProductController::class, 'destroy'])->name('products.destroy');
        Route::get('/products/search', [ProductController::class, 'search'])->name('products.search');

        Route::get('/customer-profiles', [CustomerProfileController::class, 'index'])->name('customer-profiles.index');
        Route::get('/customer-profiles/create', [CustomerProfileController::class, 'create'])->name('customer-profiles.create');
        Route::post('/customer-profiles', [CustomerProfileController::class, 'store'])->name('customer-profiles.store');
        Route::get('/customer-profiles/{customerProfile}/edit', [CustomerProfileController::class, 'edit'])->name('customer-profiles.edit');
        Route::put('/customer-profiles/{customerProfile}', [CustomerProfileController::class, 'update'])->name('customer-profiles.update');
        Route::post('/customer-profiles/{customerProfile}/toggle', [CustomerProfileController::class, 'toggle'])->name('customer-profiles.toggle');
    });


    Route::middleware(['role:company_admin'])->group(function () {
        // Client-side consultant consent: invites, approvals, revokes.
        Route::get('/company/consultants', [CompanyConsultantController::class, 'index'])->name('company.consultants');
        Route::post('/company/consultants/invite', [CompanyConsultantController::class, 'createInvite'])->name('company.consultants.invite');
        Route::post('/company/consultants/invites/{invite}/revoke', [CompanyConsultantController::class, 'revokeInvite'])->name('company.consultants.invite-revoke');
        Route::post('/company/consultants/links/{link}/approve', [CompanyConsultantController::class, 'approve'])->name('company.consultants.approve');
        Route::post('/company/consultants/links/{link}/reject', [CompanyConsultantController::class, 'reject'])->name('company.consultants.reject');
        Route::post('/company/consultants/links/{link}/revoke', [CompanyConsultantController::class, 'revokeLink'])->name('company.consultants.revoke');

        Route::get('/compliance', [ComplianceController::class, 'index'])->name('compliance.index');
        Route::post('/compliance/audit-packs', [ComplianceController::class, 'store'])->name('compliance.packs.store');
        Route::get('/compliance/audit-packs/{pack}/status', [ComplianceController::class, 'status'])->name('compliance.packs.status');
        Route::get('/compliance/audit-packs/{pack}/download', [ComplianceController::class, 'download'])->name('compliance.packs.download');
        Route::delete('/compliance/audit-packs/{pack}', [ComplianceController::class, 'destroy'])->name('compliance.packs.destroy');

        Route::get('/company/users', [CompanyUserController::class, 'index']);
        Route::post('/company/users', [CompanyUserController::class, 'store'])->middleware('plan.limit:users');
        Route::patch('/company/users/{user}/role', [CompanyUserController::class, 'updateRole']);
        Route::patch('/company/users/{user}/reset-password', [CompanyUserController::class, 'resetPassword']);
        Route::patch('/company/users/{user}/toggle', [CompanyUserController::class, 'toggleActive']);

        Route::get('/company/profile', [CompanySettingsController::class, 'profile']);
        Route::put('/company/profile', [CompanySettingsController::class, 'updateProfile']);
        Route::get('/company/fbr-settings', [CompanySettingsController::class, 'fbrSettings']);
        Route::put('/company/fbr-settings', [CompanySettingsController::class, 'updateFbrSettings']);
        // Task 140: DI Premium white-label branding (white_label plan gate enforced in-controller)
        Route::get('/company/branding', [CompanySettingsController::class, 'branding'])->name('company.branding');
        Route::put('/company/branding', [CompanySettingsController::class, 'updateBranding'])->name('company.branding.update');
        Route::post('/company/fbr-settings-ajax', [CompanySettingsController::class, 'updateFbrSettingsAjax']);
        // WhatsApp Business API (Phase 2) — server-side invoice send credentials
        Route::get('/company/whatsapp-settings', [CompanySettingsController::class, 'whatsappSettings']);
        Route::put('/company/whatsapp-settings', [CompanySettingsController::class, 'updateWhatsappSettings']);
        Route::post('/company/test-connection', [CompanySettingsController::class, 'testConnection']);
        Route::post('/company/sandbox-test/{type}', [CompanySettingsController::class, 'sandboxTest']);

        Route::get('/branches', [BranchController::class, 'index'])->name('branches.index');
        Route::get('/branches/create', [BranchController::class, 'create'])->name('branches.create');
        Route::post('/branches', [BranchController::class, 'store'])->name('branches.store');
        Route::get('/branches/{branch}/edit', [BranchController::class, 'edit'])->name('branches.edit');
        Route::put('/branches/{branch}', [BranchController::class, 'update'])->name('branches.update');
        Route::delete('/branches/{branch}', [BranchController::class, 'destroy'])->name('branches.destroy');
    });

    Route::get('/api/products/search', [ProductController::class, 'search']);
    Route::post('/api/products/quick-create', [ProductController::class, 'quickCreate']);
    Route::get('/api/customer-profiles/search', [CustomerProfileController::class, 'search']);
    Route::get('/api/schedule/config', function () {
        return response()->json(\App\Services\ScheduleEngine::$scheduleTypes);
    });
    Route::get('/api/hs-lookup', [GlobalHsMasterController::class, 'apiLookup']);
    Route::get('/api/hs-search', [GlobalHsMasterController::class, 'apiSearch']);
    Route::get('/api/hs-mapping-suggestions/{hsCode}', [HsCodeMappingController::class, 'apiSuggestions']);
    Route::post('/api/hs-mapping-response', [HsCodeMappingController::class, 'apiRecordResponse']);
    Route::get('/api/sro-suggest', function (\Illuminate\Http\Request $request) {
        $scheduleType = $request->get('schedule_type', 'standard');
        $taxRate = $request->get('tax_rate') ? floatval($request->get('tax_rate')) : null;
        $hsCode = $request->get('hs_code');
        $companyId = app('currentCompanyId');
        $company = \App\Models\Company::find($companyId);
        $standardTaxRate = $company ? $company->getStandardTaxRateValue() : 18.0;
        return response()->json(\App\Services\SroSuggestionService::getApiResponse($scheduleType, $taxRate, $hsCode, $standardTaxRate));
    });
    Route::get('/api/tax-resolve', function (\Illuminate\Http\Request $request) {
        $companyId = app('currentCompanyId');
        $hsCode = $request->get('hs_code', '');
        $customerNtn = $request->get('customer_ntn');
        return response()->json(\App\Services\TaxResolutionService::resolveForApi($hsCode, $companyId, $customerNtn));
    });
    Route::get('/api/invoice/{invoice}/risk-analysis', function (\Illuminate\Http\Request $request, \App\Models\Invoice $invoice) {
        $companyId = app('currentCompanyId');
        if ($invoice->company_id !== $companyId && auth()->user()->role !== 'super_admin') {
            abort(403);
        }
        return response()->json(\App\Services\RiskIntelligenceEngine::analyzeInvoice($invoice));
    });
    Route::get('/api/hs-usage-suggestions/{hsCode}', function (string $hsCode) {
        $suggestions = \App\Services\HsUsagePatternService::getSuggestions($hsCode);
        if ($suggestions && count($suggestions) > 0) {
            $best = $suggestions[0];
            $best['hs_code'] = $hsCode;
            $best['all_suggestions'] = $suggestions;
            return response()->json($best);
        }
        return response()->json(['hs_code' => $hsCode]);
    });

    Route::get('/api/smart-tax-recommend', function (\Illuminate\Http\Request $request) {
        $companyId = app('currentCompanyId');
        $hsCode = $request->get('hs_code', '');
        $province = $request->get('province');
        $buyerRegType = $request->get('buyer_registration_type');
        $sectorType = $request->get('sector_type');
        return response()->json(\App\Services\SmartTaxEngine::recommend($hsCode, $province, $buyerRegType, $sectorType, $companyId));
    });

    Route::post('/api/rejection-probability', function (\Illuminate\Http\Request $request) {
        $companyId = app('currentCompanyId');
        return response()->json(\App\Services\RejectionProbabilityEngine::simulateFromRequest($request->all(), $companyId));
    });

    Route::get('/api/invoice/{invoice}/rejection-probability', function (\App\Models\Invoice $invoice) {
        $companyId = app('currentCompanyId');
        if ($invoice->company_id !== $companyId && auth()->user()->role !== 'super_admin') {
            abort(403);
        }
        return response()->json(\App\Services\RejectionProbabilityEngine::simulate($invoice));
    });

    Route::get('/api/audit-probability', function () {
        $companyId = app('currentCompanyId');
        return response()->json(\App\Services\AuditProbabilityEngine::calculate($companyId));
    });

    Route::get('/api/risk-heatmap', [DashboardController::class, 'riskHeatmap']);

    Route::get('/executive-dashboard', [DashboardController::class, 'executive'])->name('executive.dashboard');

    Route::post('/toggle-dark-mode', function () {
        $user = auth()->user();
        $user->dark_mode = !$user->dark_mode;
        $user->save();
        return back();
    })->name('toggle.dark-mode');

    Route::post('/api/compliance/check', [InvoiceController::class, 'complianceCheck']);
    Route::get('/api/enterprise/invoice/{invoice}/status', [InvoiceController::class, 'apiStatus']);
    Route::get('/api/enterprise/company/compliance', [InvoiceController::class, 'apiComplianceStatus']);

    Route::get('/invoice/{invoice}', [InvoiceController::class, 'show'])->name('invoice.show');
    Route::get('/invoice/{invoice}/status-json', [InvoiceController::class, 'statusJson']);
    Route::get('/invoice/{invoice}/preview', [InvoiceController::class, 'preview']);
    Route::get('/invoice/{invoice}/pdf', [InvoiceController::class, 'pdf']);
    Route::get('/invoice/{invoice}/pdf-bw-preview', [InvoiceController::class, 'pdfBwPreview'])->name('invoice.pdfBwPreview');
    Route::get('/invoice/{invoice}/download', [InvoiceController::class, 'download']);
    Route::post('/invoice/{invoice}/update-wht', [InvoiceController::class, 'updateWht'])->name('invoice.updateWht');
    Route::post('/invoice/{invoice}/update-wht-ajax', [InvoiceController::class, 'updateWhtAjax'])->name('invoice.updateWhtAjax');
    Route::post('/invoice/{invoice}/correct-wht-ajax', [InvoiceController::class, 'correctWhtAjax'])->name('invoice.correctWhtAjax');
    Route::get('/wht-management', [InvoiceController::class, 'whtManagement'])->name('wht.management');
    Route::post('/invoice/{invoice}/verify', [InvoiceController::class, 'verifyIntegrity'])->name('invoice.verify');

    Route::get('/compliance/certificate', [ComplianceCertificateController::class, 'generate'])->name('compliance.certificate');
    Route::get('/compliance/risk-report', [RiskReportController::class, 'show'])->name('compliance.risk-report');

    Route::get('/reports/wht', [WhtReportController::class, 'index'])->name('reports.wht');
    Route::get('/reports/wht/download', [WhtReportController::class, 'downloadWht'])->name('reports.wht.download');
    Route::get('/reports/wht/pdf', [WhtReportController::class, 'pdfWht'])->name('reports.wht.pdf');
    Route::get('/reports/tax-summary', [WhtReportController::class, 'taxSummary'])->name('reports.tax-summary');
    Route::get('/reports/tax-summary/download', [WhtReportController::class, 'downloadTaxSummary'])->name('reports.tax-summary.download');
    Route::get('/reports/tax-summary/pdf', [WhtReportController::class, 'pdfTaxSummary'])->name('reports.tax-summary.pdf');

    Route::get('/mis', [MISController::class, 'index'])->name('mis.index');
    Route::get('/mis/export', [MISController::class, 'exportCsv'])->name('mis.export');
    Route::get('/mis/pdf', [MISController::class, 'exportPdf'])->name('mis.pdf');

    Route::middleware(['role:company_admin,super_admin'])->group(function () {
        Route::get('/tax-overrides', [TaxOverrideController::class, 'index'])->name('tax-overrides.index');
        Route::post('/tax-overrides/customer', [TaxOverrideController::class, 'storeCustomerRule'])->name('tax-overrides.customer.store');
        Route::put('/tax-overrides/customer/{id}', [TaxOverrideController::class, 'updateCustomerRule'])->name('tax-overrides.customer.update');
        Route::delete('/tax-overrides/customer/{id}', [TaxOverrideController::class, 'deleteCustomerRule'])->name('tax-overrides.customer.delete');
    });

    Route::middleware(['role:super_admin'])->group(function () {
        Route::post('/tax-overrides/sector', [TaxOverrideController::class, 'storeSectorRule'])->name('tax-overrides.sector.store');
        Route::put('/tax-overrides/sector/{id}', [TaxOverrideController::class, 'updateSectorRule'])->name('tax-overrides.sector.update');
        Route::delete('/tax-overrides/sector/{id}', [TaxOverrideController::class, 'deleteSectorRule'])->name('tax-overrides.sector.delete');
        Route::post('/tax-overrides/province', [TaxOverrideController::class, 'storeProvinceRule'])->name('tax-overrides.province.store');
        Route::put('/tax-overrides/province/{id}', [TaxOverrideController::class, 'updateProvinceRule'])->name('tax-overrides.province.update');
        Route::delete('/tax-overrides/province/{id}', [TaxOverrideController::class, 'deleteProvinceRule'])->name('tax-overrides.province.delete');
        Route::post('/tax-overrides/sro', [TaxOverrideController::class, 'storeSroRule'])->name('tax-overrides.sro.store');
        Route::put('/tax-overrides/sro/{id}', [TaxOverrideController::class, 'updateSroRule'])->name('tax-overrides.sro.update');
        Route::delete('/tax-overrides/sro/{id}', [TaxOverrideController::class, 'deleteSroRule'])->name('tax-overrides.sro.delete');
        Route::get('/tax-overrides/analytics', [TaxOverrideController::class, 'overrideAnalytics'])->name('tax-overrides.analytics');

    });
});

// Consultant exit: 'auth' ONLY (no company/approval gates) so leaving a client
// session ALWAYS works, even if the client company got demoted mid-session.
Route::middleware('auth')->post('/consultant/exit', [ConsultantConsoleController::class, 'exitSwitch'])->name('consultant.exit');

Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::put('/profile/company', [ProfileController::class, 'updateCompany'])->name('profile.updateCompany');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

Route::middleware('auth')->group(function () {
    Route::post('/announcements/{id}/dismiss', [AnnouncementController::class, 'dismiss'])->name('announcements.dismiss');

    Route::get('/inventory', [InventoryController::class, 'index'])->name('inventory.index');
    Route::get('/inventory/movements', [InventoryController::class, 'movements'])->name('inventory.movements');
    Route::post('/inventory/adjust', [InventoryController::class, 'adjust'])->name('inventory.adjust');
    Route::put('/inventory/stock/{id}/min-stock', [InventoryController::class, 'updateMinStock'])->name('inventory.update-min-stock');
    Route::get('/inventory/product/{productId}/movements', [InventoryController::class, 'productMovements'])->name('inventory.product-movements');
    Route::get('/api/inventory/stock/{productId}', [InventoryController::class, 'apiStockCheck'])->name('api.inventory.stock-check');

    Route::get('/suppliers', [SupplierController::class, 'index'])->name('suppliers.index');
    Route::post('/suppliers', [SupplierController::class, 'store'])->name('suppliers.store');
    Route::put('/suppliers/{id}', [SupplierController::class, 'update'])->name('suppliers.update');
    Route::delete('/suppliers/{id}', [SupplierController::class, 'destroy'])->name('suppliers.destroy');

    Route::get('/purchase-orders', [SupplierController::class, 'purchaseOrders'])->name('purchase-orders.index');
    Route::post('/purchase-orders', [SupplierController::class, 'storePurchaseOrder'])->name('purchase-orders.store');
    Route::post('/purchase-orders/{id}/receive', [SupplierController::class, 'receivePurchaseOrder'])->name('purchase-orders.receive');
    Route::post('/purchase-orders/{id}/cancel', [SupplierController::class, 'cancelPurchaseOrder'])->name('purchase-orders.cancel');

    // Payment proof submission (locked / trial-ended DI company) — intentionally NOT behind plan.limit.
    Route::post('/payment-proof', [\App\Http\Controllers\PaymentProofController::class, 'store'])
        ->name('payment-proof.store')->middleware('throttle:6,1');
});

// Madadgar AI support bot (owner request 22 Jul 2026) — pos.auth ONLY, NO
// company.approval: pending companies may chat (their POSTs would be 403'd
// otherwise). ALL POS roles allowed, including cashiers (owner explicit).
Route::middleware(['pos.auth'])->prefix('pos/madadgar')->group(function () {
    Route::get('/history', [\App\Http\Controllers\MadadgarController::class, 'history'])->name('pos.madadgar.history');
    Route::post('/message', [\App\Http\Controllers\MadadgarController::class, 'message'])->name('pos.madadgar.message')->middleware('throttle:20,1');
    Route::post('/escalate', [\App\Http\Controllers\MadadgarController::class, 'escalate'])->name('pos.madadgar.escalate')->middleware('throttle:10,1');
});

// Tutorial videos inside the POS login (owner request, 2 Aug 2026) — pos.auth
// ONLY, NO company.approval: pending companies may watch and learn while they
// wait (same precedent as Madadgar). All roles allowed; path is unmapped in
// PosAccessService so custom-access members can always reach it.
Route::middleware(['pos.auth'])->get('/pos/tutorials', [\App\Http\Controllers\TutorialController::class, 'posIndex'])->name('pos.tutorials');

// FBR POS panel ki apni tutorials page (owner, 6 Aug 2026) — fbrpos.auth ONLY,
// NO company.approval (same precedent as /pos/tutorials): sirf fbrpos videos.
Route::middleware(['fbrpos.auth'])->get('/fbr-pos/tutorials', [\App\Http\Controllers\TutorialController::class, 'fbrIndex'])->name('fbrpos.tutorials');

// Per-user sale-grid visibility (owner, 25 Jul 2026) — pos.auth ONLY, NO
// company.approval (personal display pref; same precedent as Madadgar).
// ALL POS roles allowed, including cashiers and waiters (owner explicit).
Route::middleware(['pos.auth'])->prefix('pos/grid-prefs')->group(function () {
    Route::post('/toggle', [\App\Http\Controllers\PosGridPrefController::class, 'toggle'])->name('pos.grid-prefs.toggle')->middleware('throttle:60,1');
    Route::post('/reset', [\App\Http\Controllers\PosGridPrefController::class, 'reset'])->name('pos.grid-prefs.reset')->middleware('throttle:10,1');
});

Route::middleware(['pos.auth', 'company.approval'])->prefix('pos')->group(function () {
    Route::get('/agent', [\App\Http\Controllers\AgentManagementController::class, 'show'])->name('pos.agent');
    Route::post('/agent/generate-key', [\App\Http\Controllers\AgentManagementController::class, 'generateKey'])->name('pos.agent.generate');
    Route::post('/agent/regenerate-key', [\App\Http\Controllers\AgentManagementController::class, 'regenerateKey'])->name('pos.agent.regenerate');
    Route::post('/agent/toggle', [\App\Http\Controllers\AgentManagementController::class, 'toggle'])->name('pos.agent.toggle');
    Route::get('/agent/download', [\App\Http\Controllers\AgentManagementController::class, 'downloadAgent'])->name('pos.agent.download');
    // NestPOS Desktop shell auto-config: shell fetches agent credentials with the
    // logged-in POS session cookie right after login (zero manual agent setup).
    Route::get('/desktop/agent-config', [\App\Http\Controllers\AgentManagementController::class, 'desktopConfig'])->name('pos.desktop.agent-config');

    Route::get('/dashboard', [PosController::class, 'dashboard'])->name('pos.dashboard');
    Route::post('/notifications/{id}/dismiss', [PosController::class, 'dismissNotification'])->name('pos.notifications.dismiss');
    Route::post('/notifications/dismiss-all', [PosController::class, 'dismissAllNotifications'])->name('pos.notifications.dismiss-all');
    Route::post('/whats-new/seen', [\App\Http\Controllers\AppUpdateController::class, 'markSeen'])->name('pos.whats-new.seen');
    // Task 1022: POS survey popup (Caller ID elaan) — admin/manager gated in controller.
    Route::post('/survey/{id}/respond', [\App\Http\Controllers\PosSurveyController::class, 'respond'])->name('pos.survey.respond');
    Route::post('/survey/{id}/dismiss', [\App\Http\Controllers\PosSurveyController::class, 'dismiss'])->name('pos.survey.dismiss');
    // Task 767: one-time "KOT centering still ON — verify your printout" banner dismiss (admin/manager only, gated in controller).
    Route::post('/kot-center-notice/dismiss', [PosController::class, 'dismissKotCenterNotice'])->name('pos.kot-center-notice.dismiss');
    // Feature Suggestion box (owner request 20 Jul 2026) — customers submit feature requests.
    Route::get('/suggestions', [\App\Http\Controllers\FeatureSuggestionController::class, 'index'])->name('pos.suggestions');
    Route::post('/suggestions', [\App\Http\Controllers\FeatureSuggestionController::class, 'store'])->name('pos.suggestions.store')->middleware('throttle:10,1');
    Route::post('/payment-proof', [\App\Http\Controllers\PaymentProofController::class, 'store'])
        ->name('pos.payment-proof.store')->middleware('throttle:6,1');
    Route::post('/settings/theme', [PosController::class, 'updateTheme'])->name('pos.settings.theme');
    Route::post('/settings/dashboard-style', [PosController::class, 'updateDashboardStyle'])->name('pos.settings.dashboard-style');
    Route::post('/settings/guided-flow', [PosController::class, 'updateGuidedFlow'])->name('pos.settings.guided-flow');
    // Language system (2 Aug 2026): per-user choice + company default. PosLocale: 'en' / 'rur' Roman Urdu / 'ur' Urdu script.
    Route::post('/set-language', function (\Illuminate\Http\Request $request) {
        $lang = $request->input('language');
        if (in_array($lang, \App\Support\PosLocale::ALL, true)) {
            $u = auth()->guard('pos')->user();
            $u->language = $lang;
            $u->save();
        }
        return back()->with('success', __('pos.language_saved'));
    })->name('pos.set-language');
    Route::post('/settings/default-language', function (\Illuminate\Http\Request $request) {
        $u = auth()->guard('pos')->user();
        abort_unless($u && $u->isPosAdmin(), 403);
        $lang = $request->input('default_language');
        if (in_array($lang, \App\Support\PosLocale::ALL, true)) {
            $u->company->update(['default_language' => $lang]);
        }
        return back()->with('success', __('pos.language_saved'));
    })->name('pos.settings.default-language');
    Route::post('/settings/quick-type', [PosController::class, 'updateQuickType'])->name('pos.settings.quick-type');
    // Caller ID (Task 1039): admin toggle + connected-phone status live on customize.
    Route::post('/settings/caller-id', [\App\Http\Controllers\PosCallerIdController::class, 'toggle'])->name('pos.settings.caller-id');
    Route::post('/settings/caller-devices/revoke', [\App\Http\Controllers\PosCallerIdController::class, 'revokeDevice'])->name('pos.settings.caller-devices.revoke');
    Route::post('/settings/receipt-autoclose', [PosController::class, 'updateReceiptAutoclose'])->name('pos.settings.receipt-autoclose');
    Route::post('/settings/cash-received-toggle', [PosController::class, 'toggleCashReceived'])->name('pos.settings.cash-received-toggle');
    Route::post('/settings/whatsapp-bill-toggle', [PosController::class, 'toggleWhatsappBill'])->name('pos.settings.whatsapp-bill-toggle');
    Route::post('/settings/tax-pricing-mode', [PosController::class, 'updateTaxPricingMode'])->name('pos.settings.tax-pricing-mode');
    Route::post('/settings/inventory-toggle', [PosController::class, 'updateInventoryToggle'])->name('pos.settings.inventory-toggle');
    Route::post('/settings/restock-toggle', [PosController::class, 'updateRestockToggle'])->name('pos.settings.restock-toggle');
    Route::post('/settings/auto-purge-local-toggle', [PosController::class, 'toggleAutoPurgeLocal'])->name('pos.settings.auto-purge-local-toggle');
    Route::post('/settings/local-billing', [PosController::class, 'updateLocalBillingSettings'])->name('pos.settings.local-billing');
    Route::post('/settings/auto-dayclose-toggle', [PosController::class, 'toggleAutoDayclose'])->name('pos.settings.auto-dayclose-toggle');
    Route::post('/settings/cashier-dayclose-toggle', [PosController::class, 'toggleCashierDayclose'])->name('pos.settings.cashier-dayclose-toggle');
    Route::post('/settings/cashier-ordercancel-toggle', [PosController::class, 'toggleCashierOrderCancel'])->name('pos.settings.cashier-ordercancel-toggle');
    Route::post('/settings/dayclose-cutoff', [PosController::class, 'updateDaycloseCutoff'])->name('pos.settings.dayclose-cutoff');
    Route::post('/settings/kds-auto-print', [PosController::class, 'toggleKdsAutoPrint'])->name('pos.settings.kds-auto-print');
    // Task 527: admin-controlled waiter permissions (cancel default OFF, takeaway default ON).
    Route::post('/settings/waiter-permission', [PosController::class, 'toggleWaiterPermission'])->name('pos.settings.waiter-permission');
    Route::get('/invoice/create', [PosController::class, 'createInvoice'])->name('pos.invoice.create');
    Route::get('/v2/invoice/create', [PosController::class, 'universalCreateInvoice'])->name('pos.v2.invoice.create');
    Route::get('/features', [PosController::class, 'featureSettings'])->name('pos.features');
    Route::post('/features', [PosController::class, 'updateFeatureSettings'])->name('pos.features.update');
    Route::post('/features/reset', [PosController::class, 'resetFeaturesToCategory'])->name('pos.features.reset');
    Route::get('/customize', [PosController::class, 'customize'])->name('pos.customize');
    Route::post('/invoice/store', [PosController::class, 'storeInvoice'])->name('pos.invoice.store')->middleware('plan.limit:invoices');
    Route::get('/transactions', [PosController::class, 'transactions'])->name('pos.transactions');
    Route::get('/transaction/{id}', [PosController::class, 'transactionShow'])->name('pos.transaction.show');
    Route::get('/transaction/{id}/edit', [PosController::class, 'editTransaction'])->name('pos.transaction.edit');
    Route::put('/transaction/{id}', [PosController::class, 'updateTransaction'])->name('pos.transaction.update');
    Route::delete('/transaction/{id}', [PosController::class, 'deleteTransaction'])->name('pos.transaction.delete');
    Route::post('/transaction/{id}/retry-pra', [PosController::class, 'retryPra'])->name('pos.transaction.retry-pra');
    // Task 808: owner self-serve re-queue of historical exempt_internal bills.
    Route::post('/transaction/{id}/requeue-exempt', [PosController::class, 'requeueExemptInternal'])->name('pos.transaction.requeue-exempt');
    // Task 655: payment-complete popup polls this on agent-mode 'pending' bills.
    Route::get('/transaction/{id}/pra-status', [PosController::class, 'apiPraStatus'])->name('pos.transaction.pra-status');
    // Return / credit-note flow (Task 570; Task 678: BOTH streams — local
    // parents produce local returns, never reported). Permission = owner/
    // manager always, staff via the per-user "Return / Credit Note" Custom
    // Access tick (PosAccessService::returnsAllowed → 403 in controller).
    // Task 681: sale-screen Quick Return — bill number → return-form URL (JSON).
    Route::get('/return-lookup', [\App\Http\Controllers\PosReturnController::class, 'quickLookup'])->name('pos.return.lookup');
    Route::get('/transaction/{id}/return', [\App\Http\Controllers\PosReturnController::class, 'returnForm'])->name('pos.transaction.return-form');
    Route::post('/transaction/{id}/return', [\App\Http\Controllers\PosReturnController::class, 'processReturn'])->name('pos.transaction.return');
    Route::post('/transactions/bulk-retry-pra', [PosController::class, 'bulkRetryPra'])->name('pos.transactions.bulk-retry-pra');
    Route::get('/api/provisional-bills', [PosController::class, 'apiProvisionalBills'])->name('pos.api.provisional-bills');
    Route::post('/api/provisional-bills/{id}/delete', [PosController::class, 'apiDeleteProvisional'])->name('pos.api.provisional.delete');
    Route::post('/api/provisional-bills/{id}/promote', [PosController::class, 'apiPromoteProvisional'])->name('pos.api.provisional.promote');
    // Item #1 (Jul 2026): customer saved delivery addresses (sale-flow, cashiers allowed)
    Route::get('/api/customer-addresses', [PosController::class, 'apiCustomerAddresses'])->name('pos.api.customer-addresses');
    Route::post('/api/customer-addresses', [PosController::class, 'apiStoreCustomerAddress'])->name('pos.api.customer-addresses.store');
    Route::post('/api/customer-addresses/delete', [PosController::class, 'apiDeleteCustomerAddress'])->name('pos.api.customer-addresses.delete');
    Route::get('/api/failed-bills', [PosController::class, 'apiFailedBills'])->name('pos.api.failed-bills');
    Route::post('/api/failed-bills/{id}/retry', [PosController::class, 'apiRetryFailed'])->name('pos.api.failed.retry');
    // Reprint modal (Alt+R) — read-only list of ALL of today's completed bills.
    Route::get('/api/todays-bills', [PosController::class, 'apiTodaysBills'])->name('pos.api.todays-bills');
    Route::get('/transaction/{id}/receipt', [PosController::class, 'receipt'])->name('pos.receipt');
    Route::get('/transaction/{id}/pdf', [PosController::class, 'downloadInvoicePdf'])->name('pos.invoice.pdf');
    Route::post('/transaction/{id}/share-link', [PosController::class, 'generateShareLink'])->name('pos.invoice.share-link');
    Route::get('/reports', [PosController::class, 'reports'])->name('pos.reports');
    Route::get('/tax-reports', [PosController::class, 'taxReports'])->name('pos.tax-reports');
    Route::get('/reports/csv', [PosController::class, 'exportReportCsv'])->name('pos.reports.csv');
    // Staff Hazri (owner batch, 26 Jul 2026) — ADMIN/MANAGER-ONLY (403 in controller).
    Route::get('/reports/hazri', [PosController::class, 'hazriReport'])->name('pos.reports.hazri');
    // Payroll PDF export (Task #280) — same gates (planGate hazri_enabled + isPosAdmin).
    Route::get('/reports/hazri/payroll-pdf', [PosController::class, 'payrollHazriPdf'])->name('pos.reports.hazri.payroll-pdf');
    // Biometric device setup + CSV import (4 Aug 2026) — admin only (403 in controller).
    Route::get('/bio-sync', [\App\Http\Controllers\PosBiometricController::class, 'setup'])->name('pos.bio-sync.setup');
    Route::post('/bio-sync/device', [\App\Http\Controllers\PosBiometricController::class, 'storeDevice'])->name('pos.bio-sync.store-device');
    Route::post('/bio-sync/device/{id}/toggle', [\App\Http\Controllers\PosBiometricController::class, 'toggleDevice'])->name('pos.bio-sync.toggle-device');
    Route::delete('/bio-sync/device/{id}', [\App\Http\Controllers\PosBiometricController::class, 'destroyDevice'])->name('pos.bio-sync.destroy-device');
    Route::post('/bio-sync/device/{id}/map', [\App\Http\Controllers\PosBiometricController::class, 'saveMapping'])->name('pos.bio-sync.save-mapping');
    Route::get('/bio-sync/import', [\App\Http\Controllers\PosBiometricController::class, 'showImport'])->name('pos.bio-sync.import');
    Route::post('/bio-sync/import', [\App\Http\Controllers\PosBiometricController::class, 'processImport'])->name('pos.bio-sync.process-import');
    Route::post('/bio-sync/quick-map', [\App\Http\Controllers\PosBiometricController::class, 'quickMapPin'])->name('pos.bio-sync.quick-map');
    // Unmapped PIN panel-banner dismiss (Task #277). Admin-only, normal POS web middleware (CSRF).
    Route::post('/bio-sync/pin-alert/dismiss', [\App\Http\Controllers\PosBiometricController::class, 'dismissPinAlert'])->name('pos.bio-sync.dismiss-pin-alert');
    Route::get('/tax-reports/csv', [PosController::class, 'exportTaxReportCsv'])->name('pos.tax-reports.csv');
    Route::get('/tax-reports/pdf', [PosController::class, 'exportTaxReportPdf'])->name('pos.tax-reports.pdf');
    Route::get('/reports/analytics-pdf', [PosController::class, 'reportsAnalyticsPdf'])->name('pos.reports.analytics-pdf');
    // Task 705: khufia key (Ctrl+Alt+Shift+L) — no visible UI by design.
    // Manager/owner: session-only "local check mode" toggle (cashier = 403).
    // LOCAL-scoped cashier: station identity switch to the owner-linked PRA
    // counterpart cashier and back (unlinked/ineligible = silent no-op).
    Route::post('/api/local-check-toggle', [PosController::class, 'toggleLocalCheck'])->name('pos.api.local-check-toggle');
    Route::post('/api/identity-switch', [PosController::class, 'identitySwitch'])->name('pos.api.identity-switch');
    Route::get('/day-close', [PosController::class, 'dayCloseReport'])->name('pos.day-close');
    Route::post('/day-close', [PosController::class, 'closeDayReport'])->name('pos.close-day');
    // Task 516: bulk-close every stranded prior business day in one click.
    Route::post('/day-close/close-all-prior', [PosController::class, 'closeAllPriorDays'])->name('pos.close-all-days');
    Route::post('/day-opening', [PosController::class, 'saveDayOpening'])->name('pos.day-opening.save');
    // Task 660: X-Report — read-only "abhi tak ki report" WITHOUT closing the
    // day (no wash, no hash, no report row). Literal paths registered BEFORE
    // the /day-close/{id}/... routes so {id} never swallows 'x-report'.
    Route::get('/day-close/x-report/pdf', [PosController::class, 'dayCloseXReportPdf'])->name('pos.day-close-x-pdf');
    Route::get('/day-close/x-report/thermal', [PosController::class, 'dayCloseXReportThermal'])->name('pos.day-close-x-thermal');
    Route::get('/day-close/{id}/pdf', [PosController::class, 'dayCloseReportPdf'])->name('pos.day-close-pdf');
    Route::get('/day-close/{id}/thermal', [PosController::class, 'dayCloseThermal'])->name('pos.day-close-thermal');
    Route::get('/api/tax-rate', [PosController::class, 'getTaxRate'])->name('pos.api.tax-rate');
    Route::post('/api/draft/save', [PosController::class, 'saveDraft'])->name('pos.api.draft.save');
    Route::get('/api/last-order', [PosController::class, 'getLastOrder'])->name('pos.api.last-order');
    Route::get('/csrf-token', function () { return response()->json(['token' => csrf_token()]); })->name('pos.csrf-token');
    Route::get('/api/draft/list', [PosController::class, 'getDrafts'])->name('pos.api.draft.list');
    Route::delete('/api/draft/{id}', [PosController::class, 'deleteDraft'])->name('pos.api.draft.delete');
    Route::post('/api/invoice/{id}/lock', [PosController::class, 'lockInvoice'])->name('pos.api.invoice.lock');
    Route::post('/api/invoice/{id}/unlock', [PosController::class, 'unlockInvoice'])->name('pos.api.invoice.unlock');
    Route::post('/api/verify-pin', [PosController::class, 'verifyPin'])->name('pos.api.verify-pin');
    Route::get('/api/check-pin-session', [PosController::class, 'checkPinSession'])->name('pos.api.check-pin-session');
    Route::post('/api/toggle-pra', [PosController::class, 'togglePra'])->name('pos.api.toggle-pra');
    Route::get('/api/boot-check', [PosController::class, 'bootCheck'])->name('pos.api.boot-check');
    // Caller ID (Task 1039): sale-screen popup poll — fresh ring events + customer match.
    Route::get('/api/caller-events', [\App\Http\Controllers\PosCallerIdController::class, 'events'])->name('pos.api.caller-events');
    Route::get('/api/caller-recent', [\App\Http\Controllers\PosCallerIdController::class, 'recentCalls'])->name('pos.api.caller-recent');
    Route::get('/api/caller-last-order', [\App\Http\Controllers\PosCallerIdController::class, 'lastOrder'])->name('pos.api.caller-last-order');
    Route::post('/api/toggle-auto-print', [PosController::class, 'toggleAutoPrint'])->name('pos.api.toggle-auto-print');
    Route::post('/api/print-jobs', [PosController::class, 'apiCreatePrintJob'])->name('pos.api.print-jobs');
    // Print-failure telemetry beacon (Task #63 — 30 Jul vanished-bill case):
    // sale screen reports WHY a print didn't fire so server logs carry the root
    // cause next time. sendBeacon-compatible (pos/* is CSRF-exempt).
    Route::post('/api/print-telemetry', [PosController::class, 'apiPrintTelemetry'])
        ->middleware('throttle:30,1')->name('pos.api.print-telemetry');
    // One-click silent-print prompt (sale-screen banner) — controller enforces
    // a strict admin/manager gate (isPosCashier → 403), same pattern as bulk-sale.
    Route::post('/api/printer-prompt', [PosController::class, 'apiPrinterPrompt'])->name('pos.api.printer-prompt');
    // Smart Product Creation — Simple POS quick-create (refused server-side when inventory ON)
    Route::post('/api/products/quick-create', [PosController::class, 'apiQuickCreate'])->name('pos.api.products.quick-create');
    Route::post('/api/products/{id}/quick-price', [PosController::class, 'apiQuickUpdatePrice'])->name('pos.api.products.quick-price');
    Route::match(['get', 'post'], '/my-profile', [PosController::class, 'userProfile'])->name('pos.user-profile');
    Route::get('/products', [PosController::class, 'products'])->name('pos.products');
    Route::get('/products/labels', [PosController::class, 'productLabels'])->name('pos.products.labels');
    // Bulk sale-screen visibility: OUTSIDE PosAdminOnly on purpose — that middleware
    // redirects instead of 403ing; controller enforces a strict admin/manager allowlist
    // (isPosAdmin) and returns a true 403 to everyone else, cashiers included.
    Route::post('/products/bulk-sale', [PosController::class, 'bulkToggleSale'])->name('pos.products.bulk-sale');
    // Product search mode — same pattern as bulk-sale: controller enforces the
    // admin allowlist with a true 403 (PosAdminOnly would redirect instead).
    Route::post('/products/search-mode', [PosController::class, 'productSearchMode'])->name('pos.products.search-mode');
    Route::get('/customers', [PosController::class, 'customers'])->name('pos.customers');
    Route::post('/customers', [PosController::class, 'storeCustomer'])->name('pos.customers.store');

    Route::middleware([\App\Http\Middleware\PosAdminOnly::class])->group(function () {
        Route::get('/services', [PosController::class, 'services'])->name('pos.services');
        Route::post('/services', [PosController::class, 'storeService'])->name('pos.services.store');
        Route::put('/services/{id}', [PosController::class, 'updateService'])->name('pos.services.update');
        Route::delete('/services/{id}', [PosController::class, 'deleteService'])->name('pos.services.delete');
        Route::get('/deals', [PosController::class, 'deals'])->name('pos.deals');
        Route::post('/deals', [PosController::class, 'storeDeal'])->name('pos.deals.store');
        Route::put('/deals/{id}', [PosController::class, 'updateDeal'])->name('pos.deals.update');
        Route::delete('/deals/{id}', [PosController::class, 'deleteDeal'])->name('pos.deals.delete');
        Route::get('/terminals', [PosController::class, 'terminals'])->name('pos.terminals');
        Route::post('/terminals', [PosController::class, 'storeTerminal'])->name('pos.terminals.store')->middleware('plan.limit:terminals');
        Route::put('/terminals/{id}', [PosController::class, 'updateTerminal'])->name('pos.terminals.update');
        Route::delete('/terminals/{id}', [PosController::class, 'deleteTerminal'])->name('pos.terminals.delete');
        Route::match(['get', 'post'], '/pra-settings', [PosController::class, 'praSettings'])->name('pos.pra-settings');
        // Irreversible standalone→PRA edition flip: admins only.
        Route::post('/api/enable-pra-integration', [PosController::class, 'enablePraIntegration'])->name('pos.api.enable-pra-integration');
        Route::get('/billing', [PosController::class, 'billing'])->name('pos.billing');
        Route::match(['get', 'post'], '/business-profile', [PosController::class, 'businessProfile'])->name('pos.business-profile');
        // F8 — public profile + menu builder (admin-only POSTs, gated in controller)
        Route::post('/public-profile', [\App\Http\Controllers\PublicProfileController::class, 'saveSettings'])->name('pos.public-profile.save');
        Route::post('/public-profile/regenerate', [\App\Http\Controllers\PublicProfileController::class, 'regenerateSlug'])->name('pos.public-profile.regenerate');
        Route::post('/public-profile/menu', [\App\Http\Controllers\PublicProfileController::class, 'saveMenu'])->name('pos.public-profile.menu');
        Route::match(['get', 'post'], '/receipt-settings', [PosController::class, 'receiptSettings'])->name('pos.receipt-settings');
        Route::match(['get', 'post'], '/printer-settings', [PosController::class, 'printerSettings'])->name('pos.printer-settings');
        Route::post('/products', [PosController::class, 'storeProduct'])->name('pos.products.store')->middleware('plan.limit:pos_products');
        Route::get('/products/template', [PosController::class, 'downloadProductTemplate'])->name('pos.products.template');
        // NO plan.limit middleware here on purpose: at-cap shops must still be
        // able to run UPDATE-only imports (the middleware would 403 the whole
        // request at cap). Access + per-row plan cap are enforced inside
        // importProducts (SubscriptionAccessService gate + remaining allowance).
        Route::post('/products/import', [PosController::class, 'importProducts'])->name('pos.products.import');
        Route::post('/products/bulk', [PosController::class, 'bulkProductAction'])->name('pos.products.bulk');
        Route::put('/products/{id}', [PosController::class, 'updateProduct'])->name('pos.products.update');
        Route::delete('/products/{id}', [PosController::class, 'deleteProduct'])->name('pos.products.delete');
        Route::post('/products/{id}/toggle', [PosController::class, 'toggleProduct'])->name('pos.products.toggle');
        Route::post('/products/{id}/toggle-sale', [PosController::class, 'toggleProductSale'])->name('pos.products.toggle-sale');
        Route::get('/customers/export', [PosController::class, 'exportCustomers'])->name('pos.customers.export');
        Route::get('/customers/template', [PosController::class, 'downloadCustomerTemplate'])->name('pos.customers.template');
        Route::post('/customers/import', [PosController::class, 'importCustomers'])->name('pos.customers.import');
        Route::put('/customers/{id}', [PosController::class, 'updateCustomer'])->name('pos.customers.update');
        Route::delete('/customers/{id}', [PosController::class, 'deleteCustomer'])->name('pos.customers.delete');
        Route::post('/customers/{id}/toggle', [PosController::class, 'toggleCustomer'])->name('pos.customers.toggle');
        Route::get('/customers/{id}/history', [PosController::class, 'customerHistory'])->name('pos.customers.history');
        Route::get('/customers/{id}/history/export', [PosController::class, 'exportCustomerHistory'])->name('pos.customers.history.export');
        Route::get('/customers/{id}/history/pdf', [PosController::class, 'customerHistoryPdf'])->name('pos.customers.history.pdf');
        Route::get('/inventory', [PosInventoryController::class, 'dashboard'])->name('pos.inventory.dashboard');
        Route::get('/inventory/stock', [PosInventoryController::class, 'stock'])->name('pos.inventory.stock');
        Route::get('/inventory/movements', [PosInventoryController::class, 'movements'])->name('pos.inventory.movements');
        Route::get('/inventory/low-stock', [PosInventoryController::class, 'lowStockAlerts'])->name('pos.inventory.low-stock');
        Route::match(['get', 'post'], '/inventory/adjust', [PosInventoryController::class, 'adjustStock'])->name('pos.inventory.adjust');
        Route::post('/inventory/min-stock', [PosInventoryController::class, 'updateMinStock'])->name('pos.inventory.min-stock');
        Route::post('/inventory/toggle', [PosInventoryController::class, 'toggleInventory'])->name('pos.inventory.toggle');
        Route::get('/team', [PosController::class, 'posTeam'])->name('pos.team');
        Route::post('/team/cashier', [PosController::class, 'storeCashier'])->name('pos.team.store-cashier');
        Route::put('/team/cashier/{id}', [PosController::class, 'updateCashier'])->name('pos.team.update-cashier');
        Route::post('/team/cashier/{id}/toggle', [PosController::class, 'toggleCashier'])->name('pos.team.toggle-cashier');
        Route::post('/team/cashier/{id}/pra', [PosController::class, 'setCashierPra'])->name('pos.team.set-pra');
        Route::post('/team/scope-permission', [PosController::class, 'setBillingScopePermission'])->name('pos.team.scope-permission');
        // Custom Access (Task #111): per-member feature tick-boxes.
        Route::post('/team/cashier/{id}/access', [PosController::class, 'setCashierAccess'])->name('pos.team.set-access');

        Route::prefix('restaurant')->middleware('feature:kitchen')->group(function () {
            Route::get('/kitchen-settings', [RestaurantPosController::class, 'kitchenSettings'])->name('pos.restaurant.kitchen-settings');
            Route::post('/kitchen-settings', [RestaurantPosController::class, 'updateKitchenSettings'])->name('pos.restaurant.kitchen-settings.update');
            // Counter/Station KOT routing (owner, Jul 2026) — admin-only CRUD (guarded in controller).
            Route::post('/stations', [RestaurantPosController::class, 'storeStation'])->name('pos.restaurant.stations.store');
            Route::post('/stations/{id}', [RestaurantPosController::class, 'updateStation'])->name('pos.restaurant.stations.update');
            Route::post('/stations/{id}/delete', [RestaurantPosController::class, 'deleteStation'])->name('pos.restaurant.stations.delete');
        });
        Route::prefix('restaurant')->middleware('feature:tables')->group(function () {
            Route::get('/table-management', [RestaurantTableController::class, 'manage'])->name('pos.restaurant.table-management');
            // Task 779: Tables-first flow toggle (admin-only — denyCashier in controller).
            Route::post('/tables-first-flow', [RestaurantTableController::class, 'updateTablesFirstFlow'])->name('pos.restaurant.tables-first-flow');
            Route::post('/table-direct-open', [RestaurantTableController::class, 'updateTableClickDirectOpen'])->name('pos.restaurant.table-direct-open');
            Route::post('/floors', [RestaurantTableController::class, 'storeFloor'])->name('pos.restaurant.floors.store');
            Route::put('/floors/{id}', [RestaurantTableController::class, 'updateFloor'])->name('pos.restaurant.floors.update');
            Route::delete('/floors/{id}', [RestaurantTableController::class, 'deleteFloor'])->name('pos.restaurant.floors.delete');
            Route::post('/tables', [RestaurantTableController::class, 'storeTable'])->name('pos.restaurant.tables.store');
            Route::put('/tables/{id}', [RestaurantTableController::class, 'updateTable'])->name('pos.restaurant.tables.update');
            Route::delete('/tables/{id}', [RestaurantTableController::class, 'deleteTable'])->name('pos.restaurant.tables.delete');
        });
        Route::prefix('restaurant')->middleware('feature:recipes')->group(function () {
            Route::get('/ingredients', [IngredientController::class, 'index'])->name('pos.restaurant.ingredients');
            Route::post('/ingredients', [IngredientController::class, 'store'])->name('pos.restaurant.ingredients.store');
            Route::put('/ingredients/{id}', [IngredientController::class, 'update'])->name('pos.restaurant.ingredients.update');
            Route::post('/ingredients/{id}/adjust', [IngredientController::class, 'adjustStock'])->name('pos.restaurant.ingredients.adjust');
            Route::delete('/ingredients/{id}', [IngredientController::class, 'destroy'])->name('pos.restaurant.ingredients.delete');
            Route::get('/recipes', [IngredientController::class, 'recipes'])->name('pos.restaurant.recipes');
            Route::post('/recipes', [IngredientController::class, 'storeRecipe'])->name('pos.restaurant.recipes.store');
            Route::put('/recipes/{id}', [IngredientController::class, 'updateRecipe'])->name('pos.restaurant.recipes.update');
            Route::delete('/recipes/{id}', [IngredientController::class, 'deleteRecipe'])->name('pos.restaurant.recipes.delete');
        });
    });

    // 🔧 Customer search/lookup/store — accessible from BOTH PRA POS (universal)
    // and Restaurant POS. Previously these were trapped inside restaurant.only,
    // which broke customer name/phone search on retail/general companies.
    Route::get('/restaurant/api/customer-search', [RestaurantPosController::class, 'customerSearch'])->name('pos.restaurant.customer-search');
    Route::get('/restaurant/api/customer-lookup', [RestaurantPosController::class, 'customerLookup'])->name('pos.restaurant.customer-lookup');
    Route::post('/restaurant/api/customer-store', [RestaurantPosController::class, 'customerStore'])->name('pos.restaurant.customer-store');

    // 🔧 Hold / Pay-held / Delete-held — accessible from BOTH plain-retail (universal)
    // and restaurant companies. Previously trapped inside restaurant.only, which
    // 403'd Hold on retail/general companies even though the universal screen shows
    // the Hold button and holdOrder() itself enforces the dine-in flow rules for
    // restaurant companies (defence-in-depth). Same fix pattern as customer-search above.
    Route::post('/restaurant/orders/hold', [RestaurantPosController::class, 'holdOrder'])->name('pos.restaurant.orders.hold');
    Route::post('/restaurant/orders/{id}/pay', [RestaurantPosController::class, 'payOrder'])->name('pos.restaurant.orders.pay');
    Route::post('/restaurant/orders/{id}/delete', [RestaurantPosController::class, 'deleteOrder'])->name('pos.restaurant.orders.delete');

    Route::middleware('restaurant.only')->group(function () {
    Route::get('/restaurant/pos', [RestaurantPosController::class, 'pos'])->name('pos.restaurant.pos');
    Route::post('/restaurant/orders/{id}/shift-table', [RestaurantPosController::class, 'shiftTable'])->name('pos.restaurant.orders.shift-table');
    Route::get('/restaurant/orders/by-table/{tableId}', [RestaurantPosController::class, 'getOrdersByTable'])->name('pos.restaurant.orders.by-table');
    // Cancelled Orders report (ZFC, 2 Aug 2026) — admin/manager only (in-method gate)
    Route::get('/restaurant/cancelled-orders', [RestaurantPosController::class, 'cancelledOrders'])->name('pos.restaurant.cancelled-orders');
    Route::get('/restaurant/cancelled-orders/csv', [RestaurantPosController::class, 'cancelledOrdersCsv'])->name('pos.restaurant.cancelled-orders.csv');
    Route::get('/restaurant/cancelled-orders/pdf', [RestaurantPosController::class, 'cancelledOrdersPdf'])->name('pos.restaurant.cancelled-orders.pdf');
    Route::get('/restaurant/tables', [RestaurantTableController::class, 'index'])->name('pos.restaurant.tables');
    Route::post('/restaurant/tables/{id}/lock', [RestaurantTableController::class, 'lockTable'])->name('pos.restaurant.tables.lock');
    Route::post('/restaurant/tables/{id}/unlock', [RestaurantTableController::class, 'unlockTable'])->name('pos.restaurant.tables.unlock');
    Route::post('/restaurant/tables/{id}/reserve', [RestaurantTableController::class, 'reserveTable'])->name('pos.restaurant.tables.reserve');
    Route::post('/restaurant/tables/{id}/release', [RestaurantTableController::class, 'releaseTable'])->name('pos.restaurant.tables.release');
    Route::get('/restaurant/api/table-status', [RestaurantTableController::class, 'tableStatus'])->name('pos.restaurant.table-status');
    // Task 899: cross-terminal held-orders sync feed (polled every 25 s by all open tabs).
    Route::get('/restaurant/api/held-orders', [RestaurantPosController::class, 'listHeldOrders'])->name('pos.restaurant.api.held-orders');
    Route::get('/restaurant/kds', [RestaurantKdsController::class, 'index'])->name('pos.restaurant.kds');
    Route::post('/restaurant/kds/{id}/status', [RestaurantKdsController::class, 'updateStatus'])->name('pos.restaurant.kds.status');
    Route::post('/restaurant/kds/{id}/kitchen-status', [RestaurantKdsController::class, 'kitchenStatus'])->name('pos.restaurant.kds.kitchen-status');
    Route::post('/restaurant/kds/scan', [RestaurantKdsController::class, 'scanComplete'])->name('pos.restaurant.kds.scan');
    Route::post('/restaurant/kds/clear-all', [RestaurantKdsController::class, 'clearAll'])->name('pos.restaurant.kds.clear-all');
    // Task 855: server-side void ack — clears cancelled-dish badge on ALL KDS screens.
    Route::post('/restaurant/orders/{id}/ack-void', [RestaurantKdsController::class, 'ackVoid'])->name('pos.restaurant.kds.ack-void');
    Route::get('/restaurant/api/live-orders', [RestaurantKdsController::class, 'liveOrders'])->name('pos.restaurant.live-orders');
    Route::get('/restaurant/orders/{id}/kitchen-ticket', [RestaurantPosController::class, 'kitchenTicket'])->name('pos.restaurant.kitchen-ticket');
    // Task 794: VOID/CANCEL slip — dishes removed from a running order after their KOT fired.
    Route::get('/restaurant/orders/{id}/void-ticket', [RestaurantPosController::class, 'voidTicket'])->name('pos.restaurant.void-ticket');
    Route::get('/restaurant/orders/{id}/proof-bill', [RestaurantPosController::class, 'proofBill'])->name('pos.restaurant.proof-bill');
    // Delivery KOT for order-less bills (rendered from the transaction itself).
    Route::get('/transactions/{id}/kitchen-ticket', [RestaurantPosController::class, 'transactionKitchenTicket'])->name('pos.transaction.kitchen-ticket');

    // ── P7 (F6): Waiter Tablets ──────────────────────────────────────────────
    // Waiter composes an order (customer/table/items) and SENDs it to a cashier.
    // pos_waiter role is confined to these routes by PosAuth.
    Route::get('/waiter', [\App\Http\Controllers\RestaurantWaiterController::class, 'index'])->name('pos.waiter');
    Route::get('/waiter/api/version', [\App\Http\Controllers\RestaurantWaiterController::class, 'version'])->name('pos.waiter.version');
    // Per-waiter style pref (owner, 5 Aug 2026) — path stays under pos/waiter so the PosAuth waiter allowlist covers it.
    Route::post('/waiter/style', [\App\Http\Controllers\RestaurantWaiterController::class, 'saveStyle'])->name('pos.waiter.style');
    Route::get('/waiter/api/tables', [\App\Http\Controllers\RestaurantWaiterController::class, 'tables'])->name('pos.waiter.tables');
    Route::get('/waiter/api/orders', [\App\Http\Controllers\RestaurantWaiterController::class, 'myOrders'])->name('pos.waiter.orders');
    Route::post('/waiter/orders', [\App\Http\Controllers\RestaurantWaiterController::class, 'storeOrder'])->name('pos.waiter.orders.store');
    Route::post('/waiter/orders/{id}/items', [\App\Http\Controllers\RestaurantWaiterController::class, 'appendItems'])->name('pos.waiter.orders.append');
    Route::post('/waiter/orders/{id}/shift-table', [\App\Http\Controllers\RestaurantWaiterController::class, 'shiftTable'])->name('pos.waiter.orders.shift-table');
    // Waiter self-cancel (Task 412): apna un-settled order tablet se cancel.
    Route::post('/waiter/orders/{id}/cancel', [\App\Http\Controllers\RestaurantWaiterController::class, 'cancelOrder'])->name('pos.waiter.orders.cancel');
    // Task 851: waiter-accessible void-ticket fallback (under pos/waiter so PosAuth
    // waiter allowlist covers it without touching PosAuth logic). Mirrors the cashier
    // pos.restaurant.void-ticket but company-scoped to the waiter's own session.
    Route::get('/waiter/orders/{id}/void-ticket', [\App\Http\Controllers\RestaurantWaiterController::class, 'waiterVoidTicket'])->name('pos.waiter.orders.void-ticket');
    // Cashier side — incoming waiter orders on the sale screen.
    Route::get('/api/incoming-orders', [\App\Http\Controllers\RestaurantWaiterController::class, 'incomingOrders'])->name('pos.api.incoming-orders');
    Route::post('/api/incoming-orders/{id}/complete', [\App\Http\Controllers\RestaurantWaiterController::class, 'completeIncoming'])->name('pos.api.incoming-orders.complete');
    Route::post('/api/incoming-orders/{id}/claim', [\App\Http\Controllers\RestaurantWaiterController::class, 'claimIncoming'])->name('pos.api.incoming-orders.claim');
    Route::post('/restaurant/orders/{id}/resend-kitchen', [RestaurantPosController::class, 'resendKitchen'])->name('pos.restaurant.orders.resend-kitchen');
    Route::post('/api/toggle-auto-kot', [PosController::class, 'toggleAutoKot'])->name('pos.api.toggle-auto-kot');
    Route::get('/restaurant/dashboard', [RestaurantPosController::class, 'dashboard'])->name('pos.restaurant.dashboard');
    Route::get('/restaurant/receipt/{id}', [RestaurantPosController::class, 'receipt'])->name('pos.restaurant.receipt');
    Route::get('/restaurant/api/check-stock', [RestaurantPosController::class, 'checkStock'])->name('pos.restaurant.check-stock');
    Route::post('/restaurant/api/refresh-product-image/{id}', [RestaurantPosController::class, 'refreshProductImage'])->name('pos.restaurant.refresh-image');
    Route::post('/restaurant/api/verify-manager-pin', [RestaurantPosController::class, 'verifyManagerPin'])->name('pos.restaurant.verify-manager-pin');
    Route::post('/restaurant/api/receipt-printed/{id}', [RestaurantPosController::class, 'markReceiptPrinted'])->name('pos.restaurant.receipt-printed');
    Route::get('/restaurant/api/customer-history/{id}', [RestaurantPosController::class, 'customerHistory'])->name('pos.restaurant.customer-history');
    Route::post('/restaurant/api/save-manager-pin', [RestaurantPosController::class, 'saveManagerPin'])->name('pos.restaurant.save-manager-pin');

    // ── Delivery Riders (Jul 2026) ───────────────────────────────────────────
    // Board + settlement open to cashiers too (the cashier receives rider cash);
    // rider CRUD + login management is admin/manager only (PosAdminOnly below).
    Route::get('/deliveries', [\App\Http\Controllers\PosRiderController::class, 'deliveries'])->name('pos.deliveries');
    Route::post('/deliveries/{id}/assign', [\App\Http\Controllers\PosRiderController::class, 'assign'])->name('pos.deliveries.assign');
    Route::post('/deliveries/{id}/status', [\App\Http\Controllers\PosRiderController::class, 'updateStatus'])->name('pos.deliveries.status');
    // Prepaid conversion (Task 285, Aug 2026) — admin/manager only; role-checked in controller.
    Route::post('/deliveries/{id}/mark-prepaid', [\App\Http\Controllers\PosRiderController::class, 'markPrepaid'])->name('pos.deliveries.mark-prepaid');
    // Prepaid revert / undo (Task 288, Aug 2026) — admin/manager only; role-checked in controller.
    Route::post('/deliveries/{id}/unmark-prepaid', [\App\Http\Controllers\PosRiderController::class, 'unmarkPrepaid'])->name('pos.deliveries.unmark-prepaid');
    Route::post('/deliveries/rider/{riderId}/bulk-status', [\App\Http\Controllers\PosRiderController::class, 'bulkStatus'])->name('pos.deliveries.bulk');
    Route::post('/riders/{id}/settle', [\App\Http\Controllers\PosRiderController::class, 'settle'])->name('pos.riders.settle');
    // Task 1105: customer delivery pin + public track link + ETA chip poll.
    // Board roles (cashier included — same people who run the board); every
    // endpoint is plan-gated in the controller (Unlimited tracking only).
    Route::post('/deliveries/{id}/customer-location', [\App\Http\Controllers\PosRiderController::class, 'saveCustomerLocation'])->name('pos.deliveries.customer-location');
    Route::post('/deliveries/{id}/track-link', [\App\Http\Controllers\PosRiderController::class, 'trackLink'])->name('pos.deliveries.track-link');
    Route::get('/deliveries/eta/data', [\App\Http\Controllers\PosRiderController::class, 'etaData'])->name('pos.deliveries.eta');
    Route::middleware([\App\Http\Middleware\PosAdminOnly::class])->group(function () {
        Route::get('/riders', [\App\Http\Controllers\PosRiderController::class, 'index'])->name('pos.riders');
        Route::post('/riders', [\App\Http\Controllers\PosRiderController::class, 'store'])->name('pos.riders.store');
        Route::put('/riders/{id}', [\App\Http\Controllers\PosRiderController::class, 'update'])->name('pos.riders.update');
        Route::post('/riders/{id}/login', [\App\Http\Controllers\PosRiderController::class, 'saveLogin'])->name('pos.riders.login');
        // Rider LIVE Tracking (Aug 2026) — Unlimited exclusive; map + poll + trail
        Route::get('/riders/tracking', [\App\Http\Controllers\PosRiderTrackingController::class, 'trackingPage'])->name('pos.riders.tracking');
        Route::get('/riders/tracking/data', [\App\Http\Controllers\PosRiderTrackingController::class, 'trackingData'])->name('pos.riders.tracking.data');
        Route::get('/riders/tracking/trail/{rider}', [\App\Http\Controllers\PosRiderTrackingController::class, 'trail'])->name('pos.riders.tracking.trail');
        // Task #320 (ZFC): dukan ki location pin — map par shop marker + default center
        Route::post('/riders/tracking/shop-location', [\App\Http\Controllers\PosRiderTrackingController::class, 'saveShopLocation'])->name('pos.riders.tracking.shop');
        // Task #446 (ZFC): Google Maps short-link → lat/lng (server follows redirects)
        Route::post('/riders/tracking/resolve-link', [\App\Http\Controllers\PosRiderTrackingController::class, 'resolveShopLink'])->name('pos.riders.tracking.resolve');
        // Task #1115: per-company idle/silent/auto-off threshold overrides
        Route::post('/riders/tracking/settings', [\App\Http\Controllers\PosRiderTrackingController::class, 'saveRiderTrackingSettings'])->name('pos.riders.tracking.settings');
        // Task #1103: Rider performance report & ranking (same Unlimited gates as tracking)
        Route::get('/riders/report', [\App\Http\Controllers\PosRiderTrackingController::class, 'reportPage'])->name('pos.riders.report');
    });
    // Rider portal — pos_rider role is confined to these routes by PosAuth
    // (exact 'pos/rider' + 'pos/rider/' prefix; /pos/riders stays admin-only).
    Route::get('/rider', [\App\Http\Controllers\PosRiderController::class, 'portal'])->name('pos.rider.portal');
    Route::post('/rider/deliveries/{id}/delivered', [\App\Http\Controllers\PosRiderController::class, 'portalMarkDelivered'])->name('pos.rider.delivered');
    });
});

use App\Http\Controllers\SaasAdmin\AdminAuthController;
use App\Http\Controllers\SaasAdmin\AdminDashboardController;
use App\Http\Controllers\SaasAdmin\AdminCompanyController;
use App\Http\Controllers\SaasAdmin\AdminPlanController;
use App\Http\Controllers\SaasAdmin\AdminSaleController;
use App\Http\Controllers\SaasAdmin\AdminSubscriptionController;
use App\Http\Controllers\SaasAdmin\AdminFranchiseController;
use App\Http\Controllers\SaasAdmin\AdminUsageController;
use App\Http\Controllers\SaasAdmin\AdminSystemController;
use App\Http\Controllers\SaasAdmin\AdminSettingsController;
use App\Http\Controllers\SaasAdmin\AdminAuditController;
use App\Http\Controllers\SaasAdmin\AdminPaymentProofController;
use App\Http\Controllers\Franchise\FranchiseAuthController;
use App\Http\Controllers\Franchise\FranchiseDashboardController;

Route::get('/admin/login', [AdminAuthController::class, 'showLogin'])->name('admin.login');
Route::post('/admin/login', [AdminAuthController::class, 'login']);
Route::post('/admin/logout', [AdminAuthController::class, 'logout'])->name('admin.logout');

Route::prefix('admin')->middleware(['admin.auth'])->group(function () {
    Route::get('/dashboard', [AdminDashboardController::class, 'index'])->name('saas.admin.dashboard');
    Route::get('/live-activity', [\App\Http\Controllers\SaasAdmin\AdminLiveActivityController::class, 'index'])->name('saas.admin.live-activity');

    // 🎯 Analytics + Reporting + Smart Pricing (Phases 1-4)
    Route::get('/analytics/dashboard', [\App\Http\Controllers\Admin\AnalyticsController::class, 'dashboard'])->name('admin.analytics.dashboard');
    Route::get('/analytics/advanced', [\App\Http\Controllers\Admin\AnalyticsController::class, 'advanced'])->name('admin.analytics.advanced');
    Route::get('/reports/daily', [\App\Http\Controllers\Admin\ReportsController::class, 'dailyReport'])->name('admin.reports.daily');
    Route::get('/reports/products', [\App\Http\Controllers\Admin\ReportsController::class, 'productReport'])->name('admin.reports.products');
    Route::get('/reports/fbr', [\App\Http\Controllers\Admin\ReportsController::class, 'fbrComplianceReport'])->name('admin.reports.fbr');
    Route::get('/pricing/suggestions', [\App\Http\Controllers\Admin\PricingController::class, 'index'])->name('admin.pricing.suggestions');
    Route::post('/pricing/apply/{product}', [\App\Http\Controllers\Admin\PricingController::class, 'applySuggestion'])->name('admin.pricing.apply');

    Route::get('/old-dashboard', [AdminController::class, 'dashboard'])->name('admin.dashboard');
    Route::get('/di-companies', [AdminController::class, 'companies']);
    Route::get('/di-companies/create', [AdminController::class, 'createCompany']);
    Route::post('/di-companies/store', [AdminController::class, 'storeCompany']);
    Route::get('/all-users', [AdminController::class, 'users']);
    Route::post('/all-users', [AdminController::class, 'storeUser']);
    Route::get('/fbr-logs', [AdminController::class, 'fbrLogs']);
    Route::get('/fbr-doctor/{company}', [AdminController::class, 'fbrDoctor'])->name('admin.fbr.doctor');
    Route::get('/fbr/failed-invoices', [AdminController::class, 'failedFbrInvoices'])->name('admin.fbr.failed-invoices');
    Route::post('/fbr/retry/{invoice}', [AdminController::class, 'retryFbrInvoice'])->name('admin.fbr.retry');
    Route::get('/fbr-pos-logs', [AdminController::class, 'fbrPosLogs'])->name('admin.fbrPosLogs');
    Route::get('/system-health', [AdminController::class, 'systemHealth']);
    Route::get('/security-logs', [AdminController::class, 'securityLogs']);
    Route::get('/audit/export', [AdminController::class, 'auditExport'])->name('admin.audit.export');
    Route::get('/old-audit-logs', [AdminController::class, 'auditLogs'])->name('admin.audit-logs');
    Route::get('/anomalies', [AdminController::class, 'anomalies'])->name('admin.anomalies');
    Route::get('/override-logs', [AdminController::class, 'overrideLogs']);
    Route::get('/company/{company}', [AdminController::class, 'companyShow']);
    Route::post('/company/{company}/suspend', [AdminController::class, 'suspendCompany']);
    Route::post('/company/{company}/approve', [AdminController::class, 'approveCompany']);
    Route::post('/company/{company}/reject', [AdminController::class, 'rejectCompany']);
    Route::post('/company/{company}/toggle-watermark', [AdminController::class, 'toggleWatermark']);
    Route::get('/di-companies/pending', [AdminController::class, 'pendingCompanies']);
    Route::post('/company/{company}/change-plan', [AdminController::class, 'changePlan']);
    Route::post('/company/{company}/toggle-internal', [AdminController::class, 'toggleInternalAccount']);
    Route::post('/company/{company}/toggle-inventory', [AdminController::class, 'toggleInventory']);
    Route::post('/company/{company}/toggle-fbr-pos', [AdminController::class, 'toggleFbrPos']);
    Route::get('/company/{company}/pos-features', [AdminController::class, 'posFeatures'])->name('admin.company.pos-features');
    Route::put('/company/{company}/pos-features', [AdminController::class, 'updatePosFeatures'])->name('admin.company.pos-features.update');
    Route::post('/company/{company}/update-limits', [AdminController::class, 'updateCompanyLimits']);
    Route::post('/company/{company}/reset-limits', [AdminController::class, 'resetCompanyLimits']);

    Route::get('/risk-settings', [AdminController::class, 'riskSettings']);
    Route::post('/risk-settings', [AdminController::class, 'updateRiskSettings']);
    Route::get('/announcements', [AnnouncementController::class, 'index'])->name('admin.announcements');
    Route::post('/announcements', [AnnouncementController::class, 'store'])->name('admin.announcements.store');
    Route::post('/announcements/{id}/toggle', [AnnouncementController::class, 'toggle'])->name('admin.announcements.toggle');
    Route::delete('/announcements/{id}/delete', [AnnouncementController::class, 'destroy'])->name('admin.announcements.destroy');

    // POS "What's New" app updates (popup + bell) — owner request 20 Jul 2026
    Route::get('/app-updates', [\App\Http\Controllers\AppUpdateController::class, 'index'])->name('admin.app-updates');
    Route::post('/app-updates', [\App\Http\Controllers\AppUpdateController::class, 'store'])->name('admin.app-updates.store');
    Route::post('/app-updates/feature-toggle', [\App\Http\Controllers\AppUpdateController::class, 'toggleFeature'])->name('admin.app-updates.feature-toggle');
    Route::post('/app-updates/{id}/update', [\App\Http\Controllers\AppUpdateController::class, 'update'])->name('admin.app-updates.update');
    Route::post('/app-updates/{id}/toggle', [\App\Http\Controllers\AppUpdateController::class, 'toggle'])->name('admin.app-updates.toggle');
    Route::delete('/app-updates/{id}/delete', [\App\Http\Controllers\AppUpdateController::class, 'destroy'])->name('admin.app-updates.destroy');

    // Tutorial videos visibility control (owner request 3 Aug 2026)
    Route::get('/tutorial-videos', [\App\Http\Controllers\TutorialVideoAdminController::class, 'index'])->name('admin.tutorial-videos');
    Route::post('/tutorial-videos/{id}/toggle-published', [\App\Http\Controllers\TutorialVideoAdminController::class, 'togglePublished'])->name('admin.tutorial-videos.toggle-published');
    Route::post('/tutorial-videos/{id}/toggle-public', [\App\Http\Controllers\TutorialVideoAdminController::class, 'togglePublic'])->name('admin.tutorial-videos.toggle-public');
    Route::post('/tutorial-videos/{id}/gate', [\App\Http\Controllers\TutorialVideoAdminController::class, 'setGate'])->name('admin.tutorial-videos.gate');
    Route::post('/tutorial-videos/{id}/role', [\App\Http\Controllers\TutorialVideoAdminController::class, 'setRole'])->name('admin.tutorial-videos.role');

    // POS Feature Suggestions review (owner request 20 Jul 2026)
    Route::get('/feature-suggestions', [\App\Http\Controllers\FeatureSuggestionController::class, 'adminIndex'])->name('admin.feature-suggestions');
    Route::post('/feature-suggestions/{id}/status', [\App\Http\Controllers\FeatureSuggestionController::class, 'setStatus'])->name('admin.feature-suggestions.status');
    Route::delete('/feature-suggestions/{id}/delete', [\App\Http\Controllers\FeatureSuggestionController::class, 'destroy'])->name('admin.feature-suggestions.destroy');

    // POS Surveys (Task 1022 — Caller ID elaan / advice collection)
    Route::get('/surveys', [\App\Http\Controllers\PosSurveyController::class, 'adminIndex'])->name('admin.surveys');
    Route::post('/surveys/feature-toggle', [\App\Http\Controllers\PosSurveyController::class, 'toggleFeature'])->name('admin.surveys.feature-toggle');
    Route::post('/surveys', [\App\Http\Controllers\PosSurveyController::class, 'adminStore'])->name('admin.surveys.store');
    Route::get('/surveys/{id}', [\App\Http\Controllers\PosSurveyController::class, 'adminShow'])->name('admin.surveys.show');
    Route::post('/surveys/{id}/toggle-close', [\App\Http\Controllers\PosSurveyController::class, 'toggleClose'])->name('admin.surveys.toggle-close');
    Route::post('/surveys/{id}/update', [\App\Http\Controllers\PosSurveyController::class, 'adminUpdate'])->name('admin.surveys.update');
    Route::delete('/surveys/{id}', [\App\Http\Controllers\PosSurveyController::class, 'adminDestroy'])->name('admin.surveys.destroy');

    // Madadgar AI bot — chat logs + settings (owner request 22 Jul 2026)
    Route::get('/madadgar-chats', [\App\Http\Controllers\MadadgarController::class, 'adminChats'])->name('admin.madadgar-chats');
    Route::post('/madadgar-settings', [\App\Http\Controllers\MadadgarController::class, 'adminSettings'])->name('admin.madadgar-settings');

    Route::get('/invoice-override', [AdminController::class, 'invoiceSearch'])->name('admin.invoice-override');
    Route::post('/invoice-override/{id}', [AdminController::class, 'invoiceOverride'])->name('admin.invoice-override.action');

    Route::get('/hs-master-export', [HsMasterExportController::class, 'index'])->name('admin.hs-master-export');

    // HS-Rate Links (manual mappings + auto-learned from invoicing)
    Route::get('/hs-rate-links', [\App\Http\Controllers\Admin\HsRateLinksController::class, 'index'])->name('admin.hs-rate-links');
    Route::post('/hs-rate-links', [\App\Http\Controllers\Admin\HsRateLinksController::class, 'store'])->name('admin.hs-rate-links.store');
    Route::put('/hs-rate-links/{id}', [\App\Http\Controllers\Admin\HsRateLinksController::class, 'update'])->name('admin.hs-rate-links.update');
    Route::delete('/hs-rate-links/{id}', [\App\Http\Controllers\Admin\HsRateLinksController::class, 'destroy'])->name('admin.hs-rate-links.destroy');
    Route::post('/hs-rate-links/{id}/toggle', [\App\Http\Controllers\Admin\HsRateLinksController::class, 'toggle'])->name('admin.hs-rate-links.toggle');
    Route::get('/hs-rate-links-export', [\App\Http\Controllers\Admin\HsRateLinksController::class, 'exportCsv'])->name('admin.hs-rate-links.export');
    Route::get('/hs-rate-links-sample', [\App\Http\Controllers\Admin\HsRateLinksController::class, 'sampleCsv'])->name('admin.hs-rate-links.sample');
    Route::post('/hs-rate-links-import', [\App\Http\Controllers\Admin\HsRateLinksController::class, 'importCsv'])->name('admin.hs-rate-links.import');

    Route::get('/hs-master', [GlobalHsMasterController::class, 'index'])->name('admin.hs-master');
    Route::post('/hs-master', [GlobalHsMasterController::class, 'store'])->name('admin.hs-master.store');
    Route::put('/hs-master/{id}', [GlobalHsMasterController::class, 'update'])->name('admin.hs-master.update');
    Route::post('/hs-master/seed', [GlobalHsMasterController::class, 'seed'])->name('admin.hs-master.seed');
    Route::post('/hs-master/map-unmapped', [GlobalHsMasterController::class, 'mapUnmapped'])->name('admin.hs-master.map-unmapped');

    Route::get('/hs-master-global', [HsMasterController::class, 'index'])->name('admin.hs-master-global.index');
    Route::get('/hs-master-global/{id}/edit', [HsMasterController::class, 'edit'])->name('admin.hs-master-global.edit');
    Route::post('/hs-master-global/{id}', [HsMasterController::class, 'update'])->name('admin.hs-master-global.update');
    Route::get('/hs-unmapped', [HsMasterController::class, 'unmapped'])->name('admin.hs-master-global.unmapped');
    Route::post('/hs-unmapped/{id}/map', [HsMasterController::class, 'mapFromQueue'])->name('admin.hs-master-global.map');
    Route::post('/hs-unmapped/{id}/reject', [HsMasterController::class, 'rejectSuggestion'])->name('admin.hs-master-global.reject');
    Route::post('/hs-unmapped/{id}/regenerate', [HsMasterController::class, 'regenerateSuggestion'])->name('admin.hs-master-global.regenerate');

    Route::get('/hs-mapping-engine', [HsCodeMappingController::class, 'index'])->name('admin.hs-mapping-engine');
    Route::get('/hs-mapping-engine/export', [HsCodeMappingController::class, 'exportCsv'])->name('admin.hs-mapping-engine.export');
    Route::post('/hs-mapping-engine/import', [HsCodeMappingController::class, 'importCsv'])->name('admin.hs-mapping-engine.import');
    Route::post('/hs-mapping-engine', [HsCodeMappingController::class, 'store'])->name('admin.hs-mapping-engine.store');
    Route::put('/hs-mapping-engine/{id}', [HsCodeMappingController::class, 'update'])->name('admin.hs-mapping-engine.update');
    Route::delete('/hs-mapping-engine/{id}', [HsCodeMappingController::class, 'destroy'])->name('admin.hs-mapping-engine.destroy');
    Route::post('/hs-mapping-engine/{id}/clone', [HsCodeMappingController::class, 'duplicate'])->name('admin.hs-mapping-engine.clone');

    Route::get('/companies', [AdminCompanyController::class, 'index'])->name('saas.admin.companies');
    Route::get('/companies/create', [AdminCompanyController::class, 'create'])->name('saas.admin.companies.create');
    Route::post('/companies', [AdminCompanyController::class, 'store'])->name('saas.admin.companies.store');
    Route::get('/companies/{id}', [AdminCompanyController::class, 'show'])->name('saas.admin.companies.show');
    Route::get('/companies/{id}/edit', [AdminCompanyController::class, 'edit'])->name('saas.admin.companies.edit');
    Route::put('/companies/{id}', [AdminCompanyController::class, 'update'])->name('saas.admin.companies.update');
    Route::post('/companies/{id}/approve', [AdminCompanyController::class, 'approve'])->name('saas.admin.companies.approve');
    Route::post('/companies/{id}/reject', [AdminCompanyController::class, 'reject'])->name('saas.admin.companies.reject');
    Route::post('/companies/{id}/suspend', [AdminCompanyController::class, 'suspend'])->name('saas.admin.companies.suspend');
    Route::post('/companies/{id}/activate', [AdminCompanyController::class, 'activate'])->name('saas.admin.companies.activate');
    Route::post('/companies/{id}/limits', [AdminCompanyController::class, 'updateLimits'])->name('saas.admin.companies.limits');
    Route::post('/companies/{id}/delete', [AdminCompanyController::class, 'softDelete'])->name('saas.admin.companies.delete');
    Route::post('/companies/{id}/change-type', [AdminCompanyController::class, 'changeProductType'])->name('saas.admin.companies.changeType');
    Route::post('/companies/{id}/reveal-access-code', [AdminCompanyController::class, 'revealFbrAccessCode'])->name('saas.admin.companies.revealAccessCode');
    Route::post('/companies/{id}/reset-password', [AdminCompanyController::class, 'resetAdminPassword'])->name('saas.admin.companies.resetPassword');

    // ── View / Manage as Company (impersonation: view-only + full-access) ──
    Route::post('/companies/{id}/impersonate', [AdminCompanyController::class, 'impersonate'])->name('saas.admin.companies.impersonate');
    Route::post('/impersonation/stop', [AdminCompanyController::class, 'stopImpersonation'])->name('saas.admin.impersonation.stop');
    Route::post('/impersonation/lock', [AdminCompanyController::class, 'lockImpersonation'])->name('saas.admin.impersonation.lock');

    // Archive Viewer (Local Bills Archive Portal) — super-admin only.
    Route::post('/companies/{id}/archive-viewer', [AdminCompanyController::class, 'storeArchiveViewer'])->name('saas.admin.companies.archive-viewer.store');
    Route::put('/companies/{id}/archive-viewer/{userId}', [AdminCompanyController::class, 'updateArchiveViewer'])->name('saas.admin.companies.archive-viewer.update');
    Route::post('/companies/{id}/archive-viewer/{userId}/toggle', [AdminCompanyController::class, 'toggleArchiveViewer'])->name('saas.admin.companies.archive-viewer.toggle');
    Route::delete('/companies/{id}/archive-viewer/{userId}', [AdminCompanyController::class, 'deleteArchiveViewer'])->name('saas.admin.companies.archive-viewer.delete');
    Route::post('/companies/{id}/requeue-exempt-internal', [AdminCompanyController::class, 'requeueExemptInternal'])->name('saas.admin.companies.requeueExemptInternal');
    Route::post('/companies/{id}/local-viewer', [AdminCompanyController::class, 'storeLocalViewer'])->name('saas.admin.companies.local-viewer.store');
    Route::put('/companies/{id}/local-viewer/{userId}', [AdminCompanyController::class, 'updateLocalViewer'])->name('saas.admin.companies.local-viewer.update');
    Route::post('/companies/{id}/local-viewer/{userId}/toggle', [AdminCompanyController::class, 'toggleLocalViewer'])->name('saas.admin.companies.local-viewer.toggle');
    Route::delete('/companies/{id}/local-viewer/{userId}', [AdminCompanyController::class, 'deleteLocalViewer'])->name('saas.admin.companies.local-viewer.delete');
    Route::post('/companies/{id}/override/lifetime', [AdminCompanyController::class, 'grantLifetime'])->name('saas.admin.companies.override.lifetime');
    Route::post('/companies/{id}/override/temporary', [AdminCompanyController::class, 'grantTemporary'])->name('saas.admin.companies.override.temporary');
    Route::delete('/companies/{id}/override', [AdminCompanyController::class, 'removeOverride'])->name('saas.admin.companies.override.remove');
    Route::get('/bin', [AdminCompanyController::class, 'bin'])->name('saas.admin.companies.bin');
    Route::post('/bin/{id}/restore', [AdminCompanyController::class, 'restore'])->name('saas.admin.companies.restore');
    Route::delete('/bin/{id}/destroy', [AdminCompanyController::class, 'forceDelete'])->name('saas.admin.companies.destroy');
    Route::get('/plans', [AdminPlanController::class, 'index'])->name('saas.admin.plans');
    Route::post('/plans', [AdminPlanController::class, 'store'])->name('saas.admin.plans.store');
    Route::put('/plans/{id}', [AdminPlanController::class, 'update'])->name('saas.admin.plans.update');
    Route::get('/sales', [AdminSaleController::class, 'index'])->name('saas.admin.sales');
    Route::post('/sales', [AdminSaleController::class, 'store'])->name('saas.admin.sales.store');
    Route::post('/sales/{id}/toggle', [AdminSaleController::class, 'toggle'])->name('saas.admin.sales.toggle');
    Route::delete('/sales/{id}', [AdminSaleController::class, 'destroy'])->name('saas.admin.sales.destroy');
    Route::get('/subscriptions', [AdminSubscriptionController::class, 'index'])->name('saas.admin.subscriptions');
    Route::post('/subscriptions/assign', [AdminSubscriptionController::class, 'assign'])->name('saas.admin.subscriptions.assign');

    // Agents/Partners program (super-admin only, guarded in controller).
    Route::get('/agents', [\App\Http\Controllers\SaasAdmin\AdminAgentController::class, 'index'])->name('saas.admin.agents');
    Route::post('/agents', [\App\Http\Controllers\SaasAdmin\AdminAgentController::class, 'store'])->name('saas.admin.agents.store');
    Route::get('/agents/{id}', [\App\Http\Controllers\SaasAdmin\AdminAgentController::class, 'show'])->whereNumber('id')->name('saas.admin.agents.show');
    Route::put('/agents/{id}', [\App\Http\Controllers\SaasAdmin\AdminAgentController::class, 'update'])->name('saas.admin.agents.update');
    Route::post('/agents/{id}/toggle', [\App\Http\Controllers\SaasAdmin\AdminAgentController::class, 'toggle'])->name('saas.admin.agents.toggle');
    Route::get('/agents/{id}/export', [\App\Http\Controllers\SaasAdmin\AdminAgentController::class, 'export'])->name('saas.admin.agents.export');
    Route::post('/agents/{id}/clawback', [\App\Http\Controllers\SaasAdmin\AdminAgentController::class, 'clawback'])->name('saas.admin.agents.clawback');

    // Consultant program oversight: consultants, links, commissions, payouts.
    Route::get('/consultants', [\App\Http\Controllers\SaasAdmin\AdminConsultantController::class, 'index'])->name('saas.admin.consultants');
    Route::post('/consultants/{id}/toggle', [\App\Http\Controllers\SaasAdmin\AdminConsultantController::class, 'toggle'])->name('saas.admin.consultants.toggle');
    Route::post('/consultants/{id}/rate', [\App\Http\Controllers\SaasAdmin\AdminConsultantController::class, 'updateRate'])->name('saas.admin.consultants.rate');
    Route::post('/consultant-links/{id}/revoke', [\App\Http\Controllers\SaasAdmin\AdminConsultantController::class, 'revokeLink'])->name('saas.admin.consultants.revoke-link');
    Route::post('/consultant-commissions/{id}/paid', [\App\Http\Controllers\SaasAdmin\AdminConsultantController::class, 'markPaid'])->name('saas.admin.consultants.mark-paid');
    Route::post('/consultants/min-payout', [\App\Http\Controllers\SaasAdmin\AdminConsultantController::class, 'updateMinPayout'])->name('saas.admin.consultants.min-payout');
    Route::post('/subscriptions/{id}/toggle', [AdminSubscriptionController::class, 'toggle'])->name('saas.admin.subscriptions.toggle');
    Route::get('/franchises', [AdminFranchiseController::class, 'index'])->name('saas.admin.franchises');
    Route::post('/franchises', [AdminFranchiseController::class, 'store'])->name('saas.admin.franchises.store');
    Route::put('/franchises/{id}', [AdminFranchiseController::class, 'update'])->name('saas.admin.franchises.update');
    Route::post('/franchises/{id}/toggle', [AdminFranchiseController::class, 'toggleStatus'])->name('saas.admin.franchises.toggle');
    Route::get('/company-usage', [AdminUsageController::class, 'index'])->name('saas.admin.usage');
    Route::get('/system-control', [AdminSystemController::class, 'index'])->name('saas.admin.system');
    Route::post('/system-control/{key}/toggle', [AdminSystemController::class, 'toggle'])->name('saas.admin.system.toggle');
    Route::get('/settings', [AdminSettingsController::class, 'index'])->name('saas.admin.settings');
    Route::post('/settings', [AdminSettingsController::class, 'update'])->name('saas.admin.settings.update');
    Route::post('/settings/test-email', [AdminSettingsController::class, 'sendTestEmail'])->middleware('throttle:5,1')->name('saas.admin.settings.test-email');
    Route::post('/settings/smtp', [AdminSettingsController::class, 'updateSmtp'])->name('saas.admin.settings.smtp');
    Route::post('/settings/whatsapp', [AdminSettingsController::class, 'updateWhatsApp'])->name('saas.admin.settings.whatsapp');
    Route::get('/audit-logs', [AdminAuditController::class, 'index'])->name('saas.admin.audit');

    // Support Inbox — support@taxnest.com.pk (super admin only, guarded in controller)
    Route::get('/support-inbox', [\App\Http\Controllers\SaasAdmin\SupportInboxController::class, 'index'])->name('saas.admin.support-inbox');
    Route::post('/support-inbox/send', [\App\Http\Controllers\SaasAdmin\SupportInboxController::class, 'send'])->middleware('throttle:20,1')->name('saas.admin.support-inbox.send');
    Route::get('/support-inbox/unread', [\App\Http\Controllers\SaasAdmin\SupportInboxController::class, 'unread'])->name('saas.admin.support-inbox.unread');
    Route::get('/support-inbox/poll', [\App\Http\Controllers\SaasAdmin\SupportInboxController::class, 'poll'])->middleware('throttle:30,1')->name('saas.admin.support-inbox.poll');
    Route::get('/support-inbox/{box}/{uid}', [\App\Http\Controllers\SaasAdmin\SupportInboxController::class, 'show'])->name('saas.admin.support-inbox.show');
    Route::get('/support-inbox/{box}/{uid}/attachment/{index}', [\App\Http\Controllers\SaasAdmin\SupportInboxController::class, 'attachment'])->name('saas.admin.support-inbox.attachment');

    // Payment proof verification queue
    Route::get('/payment-proofs', [AdminPaymentProofController::class, 'index'])->name('saas.admin.payment-proofs');
    Route::get('/payment-proofs/{id}/download', [AdminPaymentProofController::class, 'download'])->name('saas.admin.payment-proofs.download');
    Route::post('/payment-proofs/{id}/approve', [AdminPaymentProofController::class, 'approve'])->name('saas.admin.payment-proofs.approve');
    Route::post('/payment-proofs/{id}/reject', [AdminPaymentProofController::class, 'reject'])->name('saas.admin.payment-proofs.reject');
});

Route::get('/franchise/login', [FranchiseAuthController::class, 'showLogin'])->name('franchise.login');
Route::post('/franchise/login', [FranchiseAuthController::class, 'login']);
Route::post('/franchise/logout', [FranchiseAuthController::class, 'logout'])->name('franchise.logout');

Route::prefix('franchise')->middleware(['franchise.auth'])->group(function () {
    Route::get('/dashboard', [FranchiseDashboardController::class, 'dashboard'])->name('franchise.dashboard');
    Route::get('/companies', [FranchiseDashboardController::class, 'companies'])->name('franchise.companies');
    Route::get('/subscriptions', [FranchiseDashboardController::class, 'subscriptions'])->name('franchise.subscriptions');
    Route::get('/revenue', [FranchiseDashboardController::class, 'revenue'])->name('franchise.revenue');
});

Route::get('/fbr-pos-landing', function () {
    $plans = \App\Models\PricingPlan::where('is_trial', false)->where('product_type', 'fbrpos')->orderBy('price')->get();
    return view('fbr-pos.landing', ['plans' => $plans]);
})->name('fbrpos.landing');

Route::get('/fbr-pos/login', [FbrPosAuthController::class, 'showLogin'])->name('fbrpos.login');
Route::post('/fbr-pos/login', [FbrPosAuthController::class, 'login']);
Route::get('/fbr-pos/register', [FbrPosAuthController::class, 'showRegister'])->name('fbrpos.register');
Route::post('/fbr-pos/register', [FbrPosAuthController::class, 'register']);
Route::post('/fbr-pos/logout', [FbrPosAuthController::class, 'logout'])->name('fbrpos.logout');
// Guest language picker on login/register (Aug 2026): session-only choice.
Route::post('/fbr-pos/guest-language', function (\Illuminate\Http\Request $request) {
    $lang = $request->input('language');
    if (\App\Support\PosLocale::isValid($lang)) {
        $request->session()->put(\App\Support\PosLocale::SESSION_KEY, $lang);
    }
    return back();
})->name('fbrpos.guest-language');

Route::prefix('fbr-pos')->middleware(['fbrpos.auth', 'company.approval'])->group(function () {
    Route::get('/dashboard', [FbrPosController::class, 'dashboard'])->name('fbrpos.dashboard');
    Route::post('/notifications/{id}/dismiss', [FbrPosController::class, 'dismissNotification'])->name('fbrpos.notifications.dismiss');
    Route::post('/notifications/dismiss-all', [FbrPosController::class, 'dismissAllNotifications'])->name('fbrpos.notifications.dismiss-all');
    Route::post('/whats-new/seen', [\App\Http\Controllers\AppUpdateController::class, 'markSeen'])->name('fbrpos.whats-new.seen');
    Route::post('/payment-proof', [\App\Http\Controllers\PaymentProofController::class, 'store'])
        ->name('fbrpos.payment-proof.store')->middleware('throttle:6,1');
    Route::get('/create', [FbrPosController::class, 'create'])->name('fbrpos.create');
    Route::post('/store', [FbrPosController::class, 'store'])->name('fbrpos.store')->middleware('plan.limit:invoices');
    Route::get('/transactions', [FbrPosController::class, 'transactions'])->name('fbrpos.transactions');
    Route::get('/transactions/{id}', [FbrPosController::class, 'show'])->name('fbrpos.show');
    Route::post('/transactions/{id}/retry-fbr', [FbrPosController::class, 'retryFbr'])->name('fbrpos.retryFbr');
    // Task 655: payment-complete popup polls this on fiscal_device 'pending' bills.
    Route::get('/transaction/{id}/fbr-status', [FbrPosController::class, 'apiFbrStatus'])->name('fbrpos.transaction.fbr-status');
    Route::get('/transactions/{id}/edit-failed', [FbrPosController::class, 'editFailed'])->name('fbrpos.editFailed');
    Route::post('/transactions/{id}/update-and-retry', [FbrPosController::class, 'updateAndRetry'])->name('fbrpos.updateAndRetry');
    Route::get('/fail-queue', [FbrPosController::class, 'failQueue'])->name('fbrpos.failQueue');
    Route::post('/fail-queue/retry-all', [FbrPosController::class, 'failQueueRetryAll'])->name('fbrpos.failQueue.retryAll');
    Route::post('/fail-queue/{id}/retry', [FbrPosController::class, 'failQueueRetryOne'])->name('fbrpos.failQueue.retryOne');
    Route::match(['get', 'post'], '/settings', [FbrPosController::class, 'fbrSettings'])->name('fbrpos.settings');
    Route::get('/agent/download', [\App\Http\Controllers\AgentManagementController::class, 'downloadAgent'])->name('fbrpos.agent.download');
    Route::post('/test-connection', [FbrPosController::class, 'testConnection'])->name('fbrpos.testConnection');
    Route::post('/api/toggle-fbr-reporting', [FbrPosController::class, 'toggleFbrReporting'])->name('fbrpos.api.toggle-fbr-reporting');
    // OFFLINE-FIRST BOOT (Aug 2026 — PRA port): freshness probe for the SW-cached sale screen.
    Route::get('/api/boot-check', [FbrPosController::class, 'bootCheck'])->name('fbrpos.api.boot-check');
    Route::post('/api/toggle-universal', [FbrPosController::class, 'toggleUniversal'])->name('fbrpos.api.toggle-universal');
    Route::post('/api/toggle-auto-kot', [FbrPosController::class, 'toggleAutoKot'])->name('fbrpos.api.toggle-auto-kot');
    Route::post('/settings/dashboard-style', [FbrPosController::class, 'updateDashboardStyle'])->name('fbrpos.settings.dashboard-style');
    Route::post('/settings/theme', [FbrPosController::class, 'updateTheme'])->name('fbrpos.settings.theme');
    Route::post('/settings/guided-flow', [FbrPosController::class, 'updateGuidedFlow'])->name('fbrpos.settings.guided-flow');
    // Language system (2 Aug 2026): per-user choice + company default. PosLocale: 'en' / 'rur' Roman Urdu / 'ur' Urdu script.
    Route::post('/set-language', function (\Illuminate\Http\Request $request) {
        $lang = $request->input('language');
        if (in_array($lang, \App\Support\PosLocale::ALL, true)) {
            $u = auth()->guard('fbrpos')->user();
            $u->language = $lang;
            $u->save();
        }
        return back()->with('success', __('pos.language_saved'));
    })->name('fbrpos.set-language');
    Route::post('/settings/default-language', function (\Illuminate\Http\Request $request) {
        $u = auth()->guard('fbrpos')->user();
        abort_unless($u && $u->isPosAdmin(), 403);
        $lang = $request->input('default_language');
        if (in_array($lang, \App\Support\PosLocale::ALL, true)) {
            $u->company->update(['default_language' => $lang]);
        }
        return back()->with('success', __('pos.language_saved'));
    })->name('fbrpos.settings.default-language');
    Route::get('/customize', [FbrPosController::class, 'customize'])->name('fbrpos.customize');
    Route::match(['get', 'post'], '/receipt-settings', [FbrPosController::class, 'fbrReceiptSettings'])->name('fbrpos.receipt-settings');

    // 🎯 Universal Header API — Local / Provisional bills (F10) + Failed bills (F11)
    Route::get('/api/provisional-bills', [FbrPosController::class, 'apiProvisionalBills'])->name('fbrpos.api.provisional-bills');
    Route::post('/api/provisional-bills/{id}/delete', [FbrPosController::class, 'apiDeleteProvisional'])->name('fbrpos.api.provisional.delete');
    Route::post('/api/provisional-bills/{id}/promote', [FbrPosController::class, 'apiPromoteProvisional'])->name('fbrpos.api.provisional.promote');
    Route::post('/api/verify-pin', [FbrPosController::class, 'verifyPin'])->name('fbrpos.api.verify-pin');
    Route::get('/api/check-pin-session', [FbrPosController::class, 'checkPinSession'])->name('fbrpos.api.check-pin-session');
    Route::get('/billing', [FbrPosController::class, 'billing'])->name('fbrpos.billing');
    Route::get('/reports', [FbrPosController::class, 'reports'])->name('fbrpos.reports');
    // Staff Hazri — FBR mirror (Task #560) — ADMIN/MANAGER-ONLY (403 in controller).
    Route::get('/reports/hazri', [FbrPosController::class, 'hazriReport'])->name('fbrpos.reports.hazri');
    Route::get('/reports/export-csv', [FbrPosController::class, 'exportReportCsv'])->name('fbrpos.reports.export-csv');

    // 👥 Team management (FBR twin of /pos/team) — admin-only in controller.
    Route::get('/team', [FbrPosController::class, 'fbrTeam'])->name('fbrpos.team');
    Route::post('/team', [FbrPosController::class, 'fbrStoreTeamMember'])->name('fbrpos.team.store');
    Route::put('/team/{id}', [FbrPosController::class, 'fbrUpdateTeamMember'])->name('fbrpos.team.update');
    Route::post('/team/{id}/toggle', [FbrPosController::class, 'fbrToggleTeamMember'])->name('fbrpos.team.toggle');

    // 🏬 Branch management (multi-branch v1) — admin-only in controller.
    Route::get('/branches', [\App\Http\Controllers\FbrPosBranchController::class, 'index'])->name('fbrpos.branches');
    Route::post('/branches', [\App\Http\Controllers\FbrPosBranchController::class, 'store'])->name('fbrpos.branches.store');
    Route::put('/branches/{id}', [\App\Http\Controllers\FbrPosBranchController::class, 'update'])->name('fbrpos.branches.update');
    Route::post('/branches/{id}/toggle', [\App\Http\Controllers\FbrPosBranchController::class, 'toggle'])->name('fbrpos.branches.toggle');
    Route::get('/tax-reports', [FbrPosController::class, 'taxReports'])->name('fbrpos.tax-reports');
    // Task 698: CSV/PDF export of the monthly FBR tax report (PRA parity).
    Route::get('/tax-reports/csv', [FbrPosController::class, 'exportTaxReportCsv'])->name('fbrpos.tax-reports.csv');
    Route::get('/tax-reports/pdf', [FbrPosController::class, 'exportTaxReportPdf'])->name('fbrpos.tax-reports.pdf');
    Route::match(['get', 'post'], '/business-profile', [FbrPosController::class, 'businessProfile'])->name('fbrpos.business-profile');
    Route::match(['get', 'post'], '/my-profile', [FbrPosController::class, 'myProfile'])->name('fbrpos.my-profile');
    Route::get('/transaction/{id}/receipt', [FbrPosController::class, 'receipt'])->name('fbrpos.receipt');
    // Order Matching (Aug 2026) — FBR KOT print endpoints
    Route::get('/held/{id}/kitchen-ticket', [FbrPosController::class, 'kotTicket'])->name('fbrpos.held.kot');
    Route::get('/transaction/{id}/kot-reprint', [FbrPosController::class, 'kotReprint'])->name('fbrpos.transaction.kot-reprint');
    Route::get('/transaction/{id}/pdf', [FbrPosController::class, 'downloadPdf'])->name('fbrpos.pdf');
    Route::get('/transaction/{id}/pdf-preview', [FbrPosController::class, 'previewPdf'])->name('fbrpos.pdf.preview');
    Route::get('/day-close', [FbrPosController::class, 'dayCloseReport'])->name('fbrpos.day-close');
    Route::post('/day-close', [FbrPosController::class, 'closeDayReport'])->name('fbrpos.close-day');
    Route::post('/day-close/close-all-prior', [FbrPosController::class, 'closeAllPriorDays'])->name('fbrpos.close-all-days');
    // Task 676 (FBR mirror of PRA Task 661): auto day-close checkbox + cutoff
    // selector on the FBR day-close page — cashier-blocked in the controller.
    Route::post('/settings/auto-dayclose-toggle', [FbrPosController::class, 'toggleAutoDayclose'])->name('fbrpos.settings.auto-dayclose-toggle');
    Route::post('/settings/dayclose-cutoff', [FbrPosController::class, 'updateDaycloseCutoff'])->name('fbrpos.settings.dayclose-cutoff');
    Route::get('/day-close/{id}/pdf', [FbrPosController::class, 'dayCloseReportPdf'])->name('fbrpos.day-close-pdf');
    Route::get('/day-close/{id}/thermal', [FbrPosController::class, 'dayCloseThermal'])->name('fbrpos.day-close-thermal');
    Route::get('/reports/analytics-pdf', [FbrPosController::class, 'reportsAnalyticsPdf'])->name('fbrpos.reports.analytics-pdf');
    // 🚀 Smart auto-close (rush/holiday recovery — closes any past day with sales but no Z-report)
    Route::post('/api/auto-close-day', [FbrPosController::class, 'apiAutoCloseDay'])->name('fbrpos.api.auto-close-day');

    Route::get('/products', [FbrPosController::class, 'products'])->name('fbrpos.products');
    Route::get('/products/create', [FbrPosController::class, 'createProduct'])->name('fbrpos.products.create');
    // 📦 Excel export/template + bulk import (FBR mirror of pos.products.template/import).
    // NO plan.limit middleware on import (Task 361): at-cap shops must still be
    // able to run UPDATE-only imports (the middleware 403s the whole request at
    // cap). Access + per-row plan cap are enforced inside importProducts
    // (SubscriptionAccessService gate + remaining allowance).
    Route::get('/products/template', [FbrPosController::class, 'downloadProductTemplate'])->name('fbrpos.products.template');
    Route::post('/products/import', [FbrPosController::class, 'importProducts'])->name('fbrpos.products.import');
    Route::post('/products', [FbrPosController::class, 'storeProduct'])->name('fbrpos.products.store')->middleware('plan.limit:products');
    Route::get('/products/{id}/edit', [FbrPosController::class, 'editProduct'])->name('fbrpos.products.edit');
    Route::put('/products/{id}', [FbrPosController::class, 'updateProduct'])->name('fbrpos.products.update');
    Route::post('/products/{id}/toggle', [FbrPosController::class, 'toggleProduct'])->name('fbrpos.products.toggle');
    Route::post('/products/{id}/toggle-sale', [FbrPosController::class, 'toggleProductSale'])->name('fbrpos.products.toggle-sale');
    Route::post('/products/bulk-sale', [FbrPosController::class, 'bulkToggleSale'])->name('fbrpos.products.bulk-sale');
    Route::delete('/products/{id}', [FbrPosController::class, 'destroyProduct'])->name('fbrpos.products.destroy');
    Route::get('/api/products/search', [FbrPosController::class, 'searchProducts'])->name('fbrpos.api.products.search');
    Route::get('/api/products/barcode', [FbrPosController::class, 'lookupByBarcode'])->name('fbrpos.api.products.barcode');
    // 🔄 Auto-Sync engine — silent 30-sec frontend poller + manual Failed modal
    Route::get('/api/failed-bills', [FbrPosController::class, 'apiFailedBills'])->name('fbrpos.api.failed-bills');
    Route::post('/api/failed-bills/{id}/retry', [FbrPosController::class, 'apiRetryFailed'])->name('fbrpos.api.failed.retry');

    // 🌐 Universal sale screen APIs — customer + quick product (PRA-shape mirrors)
    Route::get('/api/customer-search', [FbrPosController::class, 'apiCustomerSearch'])->name('fbrpos.api.customer-search');
    Route::get('/api/customer-lookup', [FbrPosController::class, 'apiCustomerLookup'])->name('fbrpos.api.customer-lookup');
    Route::post('/api/customer-store', [FbrPosController::class, 'apiCustomerStore'])->name('fbrpos.api.customer-store');
    Route::get('/api/customer-history/{id}', [FbrPosController::class, 'apiCustomerHistory'])->name('fbrpos.api.customer-history');
    // Task 163: cashier-editable delivery addresses — customers live in the shared
    // pos_customers/pos_customer_addresses tables, so PosController handlers are
    // reused as-is (they only depend on currentCompanyId, bound here too).
    Route::get('/api/customer-addresses', [PosController::class, 'apiCustomerAddresses'])->name('fbrpos.api.customer-addresses');
    Route::post('/api/customer-addresses', [PosController::class, 'apiStoreCustomerAddress'])->name('fbrpos.api.customer-addresses.store');
    Route::post('/api/customer-addresses/delete', [PosController::class, 'apiDeleteCustomerAddress'])->name('fbrpos.api.customer-addresses.delete');
    // NO plan.limit middleware (Task 362): the middleware would 403 the whole
    // request at cap, breaking dedupe/barcode-rescue of EXISTING products for
    // at-cap shops. The controller enforces the cap on genuinely NEW rows only.
    Route::post('/api/products/quick-create', [FbrPosController::class, 'apiQuickCreateProduct'])->name('fbrpos.api.products.quick-create');
    Route::post('/api/products/{id}/quick-price', [FbrPosController::class, 'apiQuickUpdatePrice'])->name('fbrpos.api.products.quick-price');

    // -------------------------------------------------------- Phase 2: Mall-Grade Universal Features --------------------------------------------------------
    // Terminals (multi-counter)
    Route::get('/terminals', [\App\Http\Controllers\FbrPosPhase2Controller::class, 'terminals'])->name('fbrpos.phase2.terminals');
    Route::post('/terminals', [\App\Http\Controllers\FbrPosPhase2Controller::class, 'storeTerminal'])->middleware('plan.limit:terminals')->name('fbrpos.phase2.terminals.store');
    Route::post('/terminals/{id}/toggle', [\App\Http\Controllers\FbrPosPhase2Controller::class, 'toggleTerminal'])->name('fbrpos.phase2.terminals.toggle');
    Route::delete('/terminals/{id}', [\App\Http\Controllers\FbrPosPhase2Controller::class, 'deleteTerminal'])->name('fbrpos.phase2.terminals.delete');

    // Held sales / Park sale
    Route::post('/api/hold', [\App\Http\Controllers\FbrPosPhase2Controller::class, 'holdSale'])->name('fbrpos.phase2.hold');
    Route::get('/api/held', [\App\Http\Controllers\FbrPosPhase2Controller::class, 'listHeld'])->name('fbrpos.phase2.held.list');
    Route::get('/api/held/{id}/recall', [\App\Http\Controllers\FbrPosPhase2Controller::class, 'recallHeld'])->name('fbrpos.phase2.held.recall');
    Route::delete('/api/held/{id}', [\App\Http\Controllers\FbrPosPhase2Controller::class, 'deleteHeld'])->name('fbrpos.phase2.held.delete');

    // Promotions
    Route::get('/promotions', [\App\Http\Controllers\FbrPosPhase2Controller::class, 'promotions'])->name('fbrpos.phase2.promotions');
    Route::post('/promotions', [\App\Http\Controllers\FbrPosPhase2Controller::class, 'storePromotion'])->name('fbrpos.phase2.promotions.store');
    Route::post('/promotions/{id}/toggle', [\App\Http\Controllers\FbrPosPhase2Controller::class, 'togglePromotion'])->name('fbrpos.phase2.promotions.toggle');
    Route::delete('/promotions/{id}', [\App\Http\Controllers\FbrPosPhase2Controller::class, 'deletePromotion'])->name('fbrpos.phase2.promotions.delete');
    Route::post('/api/promo/validate', [\App\Http\Controllers\FbrPosPhase2Controller::class, 'validatePromo'])->name('fbrpos.phase2.promo.validate');

    // Loyalty
    Route::match(['get', 'post'], '/loyalty', [\App\Http\Controllers\FbrPosPhase2Controller::class, 'loyaltySettings'])->name('fbrpos.phase2.loyalty');
    Route::get('/api/customer/{phone}/points', [\App\Http\Controllers\FbrPosPhase2Controller::class, 'customerPoints'])->name('fbrpos.phase2.customer.points');

    // Shifts / Cash drawer
    Route::get('/shifts', [\App\Http\Controllers\FbrPosPhase2Controller::class, 'shiftsIndex'])->name('fbrpos.phase2.shifts');
    Route::post('/shifts/open', [\App\Http\Controllers\FbrPosPhase2Controller::class, 'openShift'])->name('fbrpos.phase2.shift.open');
    Route::post('/shifts/close', [\App\Http\Controllers\FbrPosPhase2Controller::class, 'closeShift'])->name('fbrpos.phase2.shift.close');
    Route::get('/shifts/{id}/report', [\App\Http\Controllers\FbrPosPhase2Controller::class, 'shiftReport'])->name('fbrpos.phase2.shift.report');
    Route::post('/shifts/cash-movement', [\App\Http\Controllers\FbrPosPhase2Controller::class, 'cashMovement'])->name('fbrpos.phase2.shift.cash');

    // Returns / Refunds
    // Quick Return lookup (Task 685) — sale screen se bill number → return form.
    // /api/ prefix keeps it inside sw.js skipPatterns (never SW-cached).
    Route::get('/api/return-lookup', [\App\Http\Controllers\FbrPosPhase2Controller::class, 'quickReturnLookup'])->name('fbrpos.phase2.return.lookup');
    Route::get('/transactions/{id}/return', [\App\Http\Controllers\FbrPosPhase2Controller::class, 'returnForm'])->name('fbrpos.phase2.return.form');
    Route::post('/transactions/{id}/return', [\App\Http\Controllers\FbrPosPhase2Controller::class, 'processReturn'])->name('fbrpos.phase2.return.process');

    // Udhaar / Khata (Aug 2026 — Retail Core)
    Route::get('/khata', [\App\Http\Controllers\FbrPosKhataController::class, 'index'])->name('fbrpos.khata');
    Route::get('/khata/{customer}/ledger', [\App\Http\Controllers\FbrPosKhataController::class, 'ledger'])->name('fbrpos.khata.ledger');
    Route::post('/khata/wasooli', [\App\Http\Controllers\FbrPosKhataController::class, 'wasooli'])->name('fbrpos.khata.wasooli');

    // Stock / Purchase / Suppliers (Aug 2026 — Retail Core)
    Route::get('/stock', [\App\Http\Controllers\FbrPosStockController::class, 'index'])->name('fbrpos.stock');
    Route::get('/stock/purchases', [\App\Http\Controllers\FbrPosStockController::class, 'purchases'])->name('fbrpos.stock.purchases');
    Route::get('/stock/movements', [\App\Http\Controllers\FbrPosStockController::class, 'movements'])->name('fbrpos.stock.movements');
    Route::get('/stock/corrections', [\App\Http\Controllers\FbrPosStockController::class, 'corrections'])->name('fbrpos.stock.corrections');
    Route::post('/stock/toggle', [\App\Http\Controllers\FbrPosStockController::class, 'toggle'])->name('fbrpos.stock.toggle')->middleware('plan.limit:inventory');
    Route::post('/stock/supplier', [\App\Http\Controllers\FbrPosStockController::class, 'storeSupplier'])->name('fbrpos.stock.supplier')->middleware('plan.limit:inventory');
    Route::post('/stock/supplier/{id}/update', [\App\Http\Controllers\FbrPosStockController::class, 'updateSupplier'])->name('fbrpos.stock.supplier.update')->middleware('plan.limit:inventory');
    Route::post('/stock/supplier/{id}/delete', [\App\Http\Controllers\FbrPosStockController::class, 'deleteSupplier'])->name('fbrpos.stock.supplier.delete')->middleware('plan.limit:inventory');
    Route::post('/stock/supplier/{id}/reactivate', [\App\Http\Controllers\FbrPosStockController::class, 'reactivateSupplier'])->name('fbrpos.stock.supplier.reactivate')->middleware('plan.limit:inventory');
    Route::post('/stock/purchase', [\App\Http\Controllers\FbrPosStockController::class, 'storePurchase'])->name('fbrpos.stock.purchase')->middleware('plan.limit:inventory');
    Route::post('/stock/purchase/{id}/void', [\App\Http\Controllers\FbrPosStockController::class, 'voidPurchase'])->name('fbrpos.stock.purchase.void')->middleware('plan.limit:inventory');
    Route::post('/stock/min-level', [\App\Http\Controllers\FbrPosStockController::class, 'updateMinLevel'])->name('fbrpos.stock.minlevel')->middleware('plan.limit:inventory');
    Route::post('/stock/item', [\App\Http\Controllers\FbrPosStockController::class, 'updateItem'])->name('fbrpos.stock.item')->middleware('plan.limit:inventory');
    Route::get('/munafa', [\App\Http\Controllers\FbrPosStockController::class, 'munafa'])->name('fbrpos.munafa');

    // 🚚 Delivery Riders (Aug 2026 — FBR port of PRA PosRiderController)
    // Board + mutations: admin + cashier. Rider CRUD: admin-only (checked in controller).
    Route::get('/deliveries', [\App\Http\Controllers\FbrPosRiderController::class, 'deliveries'])->name('fbrpos.deliveries');
    Route::post('/deliveries/{id}/assign', [\App\Http\Controllers\FbrPosRiderController::class, 'assign'])->name('fbrpos.deliveries.assign');
    Route::post('/deliveries/{id}/status', [\App\Http\Controllers\FbrPosRiderController::class, 'updateStatus'])->name('fbrpos.deliveries.status');
    Route::post('/deliveries/{id}/mark-prepaid', [\App\Http\Controllers\FbrPosRiderController::class, 'markPrepaid'])->name('fbrpos.deliveries.mark-prepaid');
    Route::post('/deliveries/{id}/unmark-prepaid', [\App\Http\Controllers\FbrPosRiderController::class, 'unmarkPrepaid'])->name('fbrpos.deliveries.unmark-prepaid');
    Route::post('/deliveries/rider/{riderId}/bulk-status', [\App\Http\Controllers\FbrPosRiderController::class, 'bulkStatus'])->name('fbrpos.deliveries.bulk');
    Route::post('/riders/{id}/settle', [\App\Http\Controllers\FbrPosRiderController::class, 'settle'])->name('fbrpos.riders.settle');
    Route::get('/riders', [\App\Http\Controllers\FbrPosRiderController::class, 'index'])->name('fbrpos.riders');
    Route::post('/riders', [\App\Http\Controllers\FbrPosRiderController::class, 'store'])->name('fbrpos.riders.store');
    Route::put('/riders/{id}', [\App\Http\Controllers\FbrPosRiderController::class, 'update'])->name('fbrpos.riders.update');
    Route::post('/riders/{id}/login', [\App\Http\Controllers\FbrPosRiderController::class, 'saveLogin'])->name('fbrpos.riders.login');
});

Route::get('/setup-migrate-xK9mP2', function () {
    try {
        Artisan::call('migrate', ['--force' => true]);
        $output = Artisan::output();
        Artisan::call('config:clear');
        Artisan::call('view:clear');
        return '<pre>Migration Output:\n' . $output . '\n\nConfig & View cache cleared.\nDone! Now delete this route from routes/web.php</pre>';
    } catch (\Exception $e) {
        return '<pre>Error: ' . $e->getMessage() . '</pre>';
    }
});

Route::get('/setup-seed-xK9mP2', function () {
    try {
        Artisan::call('db:seed', ['--force' => true]);
        $output = Artisan::output();
        return '<pre>Seed Output:\n' . $output . '\nDone!</pre>';
    } catch (\Exception $e) {
        return '<pre>Error: ' . $e->getMessage() . '</pre>';
    }
});

// --------------------------------------------------------
// AGENT API (TaxNest Desktop Sync Agent)
// Bearer token auth, no CSRF
// --------------------------------------------------------
// ── TaxNest Rider app API (Aug 2026) — stateless bearer-token JSON.
// Rider signs in with his portal login; token rotates per login (one device).
// CSRF-exempt via bootstrap/app.php ('api/rider-app/*').
Route::prefix('api/rider-app/v1')->middleware(['throttle:120,1'])->withoutMiddleware($statelessMachine)->group(function () {
    Route::post('/login', [\App\Http\Controllers\PosRiderTrackingController::class, 'appLogin'])->middleware('throttle:15,1')->name('riderapp.login');
    Route::post('/duty', [\App\Http\Controllers\PosRiderTrackingController::class, 'appDuty'])->name('riderapp.duty');
    // Task #1106: FCM push-token registration (async — token arrives after login / rotates via onNewToken).
    Route::post('/fcm-token', [\App\Http\Controllers\PosRiderTrackingController::class, 'appFcmToken'])->name('riderapp.fcmtoken');
    Route::post('/locations', [\App\Http\Controllers\PosRiderTrackingController::class, 'appLocations'])->name('riderapp.locations');
    Route::get('/me', [\App\Http\Controllers\PosRiderTrackingController::class, 'appMe'])->name('riderapp.me');
    Route::get('/version', [\App\Http\Controllers\PosRiderTrackingController::class, 'appVersion'])->name('riderapp.version');
    Route::post('/logout', [\App\Http\Controllers\PosRiderTrackingController::class, 'appLogout'])->name('riderapp.logout');
});

// ── TaxNest Caller ID app API (Task 1039) — stateless bearer-token JSON.
// Shop admin/manager signs in with the portal login; token rotates per login
// (one active phone per company). CSRF-exempt via bootstrap/app.php
// ('api/caller-app/*'). Open to all POS plans for now.
Route::prefix('api/caller-app/v1')->middleware(['throttle:120,1'])->withoutMiddleware($statelessMachine)->group(function () {
    Route::post('/login', [\App\Http\Controllers\PosCallerIdController::class, 'appLogin'])->middleware('throttle:15,1')->name('callerapp.login');
    Route::post('/ring', [\App\Http\Controllers\PosCallerIdController::class, 'appRing'])->name('callerapp.ring');
    Route::get('/me', [\App\Http\Controllers\PosCallerIdController::class, 'appMe'])->name('callerapp.me');
    Route::get('/version', [\App\Http\Controllers\PosCallerIdController::class, 'appVersion'])->name('callerapp.version');
    Route::post('/logout', [\App\Http\Controllers\PosCallerIdController::class, 'appLogout'])->name('callerapp.logout');
});

Route::prefix('api/agent')->middleware(['agent.auth'])->withoutMiddleware($statelessMachine)->group(function () {
    Route::post('/heartbeat', [\App\Http\Controllers\AgentController::class, 'heartbeat']);
    Route::get('/pending-invoices', [\App\Http\Controllers\AgentController::class, 'pendingInvoices']);
    Route::post('/submit-result', [\App\Http\Controllers\AgentController::class, 'submitResult']);
    // Silent printer routing — agent reports printers + polls/prints queued jobs.
    Route::post('/printers', [\App\Http\Controllers\AgentController::class, 'reportPrinters']);
    Route::get('/print-jobs', [\App\Http\Controllers\AgentController::class, 'claimPrintJobs']);
    Route::get('/print-jobs/{id}/content', [\App\Http\Controllers\AgentController::class, 'printJobContent']);
    Route::post('/print-jobs/{id}/result', [\App\Http\Controllers\AgentController::class, 'printJobResult']);
});

// === Public business profile + menu (F8) — slug-only, throttled, 404 on unknown ===
Route::get('/menu/{slug}', [\App\Http\Controllers\PublicProfileController::class, 'show'])
    ->middleware('throttle:60,1')
    ->name('public.company-profile');

// === Public bill page (Task 777) — the receipt QR on non-fiscal (local/
// provisional) bills opens this. Token-only lookup (64-hex share_token),
// throttled, 404 on unknown — no login, no expiry (customers scan late),
// archived bills open too. ===
Route::get('/bill/{token}', [\App\Http\Controllers\PublicProfileController::class, 'showBill'])
    ->middleware('throttle:60,1')
    ->name('public.bill');

// === PWA Diagnostics page (public — no sensitive data, only client-side checks) ===
Route::get('/pwa-status', function () {
    return response()->view('pwa-status')
        ->header('Cache-Control', 'no-store');
})->name('pwa.status');

// === PWA Push Notification subscription endpoints (works for any auth guard) ===
Route::post('/api/push/subscribe', [\App\Http\Controllers\PushSubscriptionController::class, 'store'])
    ->middleware('throttle:30,1')
    ->name('push.subscribe');
Route::post('/api/push/unsubscribe', [\App\Http\Controllers\PushSubscriptionController::class, 'destroy'])
    ->middleware('throttle:30,1')
    ->name('push.unsubscribe');

// FBR Reference Data Demo (public — no auth, just shows the data is queryable)
Route::get('/fbr/reference-demo', [\App\Http\Controllers\FbrReferenceController::class, 'demo'])->name('fbr.reference.demo');
Route::get('/api/fbr/hs-search', [\App\Http\Controllers\FbrReferenceController::class, 'searchHs'])->name('fbr.api.hs-search');
Route::get('/api/fbr/sro-search', [\App\Http\Controllers\FbrReferenceController::class, 'searchSro'])->name('fbr.api.sro-search');
Route::get('/api/fbr/item-sr-search', [\App\Http\Controllers\FbrReferenceController::class, 'searchItemSr'])->name('fbr.api.item-sr-search');
Route::get('/api/fbr/hs-detail', [\App\Http\Controllers\FbrReferenceController::class, 'hsDetail'])->name('fbr.api.hs-detail');

require __DIR__.'/auth.php';





