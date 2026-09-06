<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\SystemSetting;
use App\Models\User;
use App\Services\HealthAccessService;
use App\Services\HealthModuleService;
use App\Services\HealthOnboardingImportService as Onboarding;
use App\Services\HealthScopeService;
use App\Support\HealthPanel;
use App\Support\NestErps;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * PILOT READINESS SWEEP (Task 1555).
 *
 * The pilot's own features are tested in their own files. This one guards the
 * three things that are nobody's feature and therefore nobody's job — the ones
 * that rot silently as the next module lands:
 *
 *  1. NO UNGATED DOOR. Every signed-in healthcare route must resolve to a
 *     capability, either from its own middleware or from the path map. A route
 *     added without either is a ward's records open to the pharmacy counter,
 *     and it will not announce itself.
 *  2. ONE PRODUCT IDENTITY. Every customer-visible surface names the product
 *     line, with the vertical underneath it, through the single authority.
 *     Billing, accounts and audit screens shipped AFTER the rename, which is
 *     exactly how a stale one-off name gets back in.
 *  3. THREE LANGUAGES, ONE KEY SET. A hospital that switches to Urdu must not
 *     be shown raw keys. English, Roman Urdu and Urdu carry identical keys, and
 *     every key a healthcare Blade asks for exists in all three.
 *
 * Run:
 *   php vendor/bin/phpunit tests/Feature/HealthPilotReadinessTest.php --testdox
 */
class HealthPilotReadinessTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Screens every signed-in member reaches regardless of role.
     *
     * Each entry is a deliberate decision, not an oversight: the dashboard
     * decides its own tiles, the display toggles are the member's own, and the
     * /health/my/* desks are a person's own attendance, leave and payslip. A
     * capability on any of them would lock a nurse out of her own record.
     */
    private const SELF_SERVICE_PATHS = [
        'health',
        'health/dashboard',
        'health/set-dark-mode',
        'health/set-language',
        'health/my/attendance',
        'health/my/punch',
        'health/my/correction',
        'health/my/leave',
        'health/my/leave/{id}/cancel',
        'health/my/earnings',
        'health/hr/leave/{id}/cancel',
    ];

    /* ─────────────────────── 1. No ungated door ───────────────────────────── */

    public function test_every_signed_in_healthcare_route_resolves_to_a_capability(): void
    {
        $ungated = [];

        foreach (Route::getRoutes() as $route) {
            $uri = $route->uri();
            if (!str_starts_with($uri, 'health')) {
                continue;
            }

            $middleware = $route->gatherMiddleware();
            $joined = implode(',', array_map(fn ($m) => is_string($m) ? $m : '', $middleware));

            // Public surfaces (login, register, the landing page) are not the
            // subject here.
            if (!str_contains($joined, 'health.auth')) {
                continue;
            }

            if (str_contains($joined, 'health.can')) {
                continue;
            }

            // No explicit argument? Then the path map must supply one.
            if (HealthAccessService::capabilityForPath($uri) !== null) {
                continue;
            }

            if (in_array($uri, self::SELF_SERVICE_PATHS, true)) {
                continue;
            }

            $ungated[] = implode('|', $route->methods()) . ' ' . $uri;
        }

        $this->assertSame(
            [],
            $ungated,
            "These healthcare routes are reachable by any signed-in member with no capability behind them.\n"
            . "Either give the route a health.can argument, add its prefix to HealthAccessService::PATH_MAP,\n"
            . "or — if it really is everybody's own screen — add it to SELF_SERVICE_PATHS with a reason:\n  "
            . implode("\n  ", $ungated)
        );
    }

    public function test_the_owner_only_set_is_never_offered_on_the_team_screen(): void
    {
        $company = $this->company();
        $delegatable = HealthAccessService::delegatableCapabilities($company);

        foreach (HealthAccessService::OWNER_ONLY as $capability) {
            $this->assertNotContains(
                $capability,
                $delegatable,
                "{$capability} is owner-only, so it must not appear as a tick box the owner can hand to a member."
            );
        }
    }

    public function test_a_capability_that_no_role_and_no_screen_uses_is_not_left_lying_around(): void
    {
        // Every capability the panel defines must be reachable: held by some
        // role, or owner-only. A capability nobody can ever hold is a gate that
        // silently refuses everyone, which reads to the hospital as a broken
        // screen rather than as a permission.
        $held = [];
        foreach (HealthAccessService::ROLE_CAPABILITIES as $capabilities) {
            foreach ($capabilities as $capability) {
                $held[$capability] = true;
            }
        }

        // Every capability a gate names must be one some role actually carries,
        // or an owner-only one. Anything else refuses everybody in silence.
        $gated = [];
        foreach (Route::getRoutes() as $route) {
            foreach ($route->gatherMiddleware() as $middleware) {
                // `health.can:a,b` is an OR list — Laravel passes it as one
                // comma-separated argument string.
                if (is_string($middleware) && str_starts_with($middleware, 'health.can:')) {
                    foreach (preg_split('/[,|]/', substr($middleware, strlen('health.can:'))) as $capability) {
                        $gated[trim($capability)] = true;
                    }
                }
            }
        }
        $orphans = [];
        foreach (array_keys($gated) as $capability) {
            if (isset($held[$capability])) {
                continue;
            }
            if (in_array($capability, HealthAccessService::OWNER_ONLY, true)) {
                continue;
            }
            $orphans[] = $capability;
        }

        $this->assertSame([], $orphans, 'A gate names a capability no role can ever hold: ' . implode(', ', $orphans));
    }

    /* ─────────────────────── 2. One product identity ──────────────────────── */

    public function test_no_customer_visible_surface_still_carries_the_old_one_off_product_name(): void
    {
        // The line was presented as a one-off healthcare product before it
        // became a product line with verticals under it. Anything still
        // spelling the old name is a surface the rename missed.
        $stale = ['Healthcare ERP', 'HealthCare ERP', 'Health ERP'];

        $offenders = $this->scanCustomerVisible(function (string $line) use ($stale) {
            foreach ($stale as $needle) {
                if (str_contains($line, $needle)) {
                    return true;
                }
            }

            return false;
        });

        $this->assertSame(
            [],
            $offenders,
            "These customer-visible surfaces still carry the pre-rename product name:\n  " . implode("\n  ", $offenders)
        );
    }

    public function test_the_product_name_is_spelled_only_by_its_authority(): void
    {
        // NestErps is the single place the product name exists as a literal.
        // A Blade or service that types it again is a surface that will not
        // follow the next rename.
        $allowed = [
            'app/Support/NestErps.php',
            'lang/en/health.php',
            'lang/rur/health.php',
            'lang/ur/health.php',
        ];

        $offenders = $this->scanCustomerVisible(
            fn (string $line) => str_contains($line, NestErps::LABEL),
            $allowed
        );

        $this->assertSame(
            [],
            $offenders,
            "The product name must come from NestErps or a lang key, never be typed again:\n  " . implode("\n  ", $offenders)
        );
    }

    public function test_the_panel_names_the_line_first_and_the_vertical_underneath(): void
    {
        $this->assertStringStartsWith(
            NestErps::LABEL,
            __('health.panel_name'),
            'The panel name must lead with the product line; the vertical is shown under it, never instead of it.'
        );
        $this->assertStringContainsString('Healthcare', __('health.panel_name'));

        // English and Roman Urdu keep the brand in Latin script. Urdu does NOT:
        // the panel is written in pure Urdu script, so the brand is
        // transliterated there rather than left as an island of Latin letters.
        // What matters is that all three name SOMETHING, and that neither of
        // the Latin-script locales quietly translated the brand away.
        foreach (['en', 'rur'] as $locale) {
            $this->assertStringContainsString(
                NestErps::LABEL,
                (string) __('health.product_name', [], $locale),
                "The product name must survive into {$locale} — a brand is not translated."
            );
        }

        $urdu = (string) __('health.product_name', [], 'ur');
        $this->assertNotSame('', trim($urdu));
        $this->assertSame(
            0,
            preg_match('/[A-Za-z]/', $urdu),
            'The Urdu panel is written in Urdu script throughout; a Latin-script brand would be the one word that breaks it.'
        );
    }

    public function test_the_signed_in_shell_and_the_login_screen_both_show_the_identity(): void
    {
        $company = $this->company();
        $owner = $this->owner($company);

        $this->get('/health/login')
            ->assertOk()
            ->assertSee(NestErps::LABEL, false);

        $this->actingAs($owner, HealthPanel::GUARD)
            ->get('/health/dashboard')
            ->assertOk()
            ->assertSee(NestErps::LABEL, false);
    }

    /* ──────────────────── 3. Three languages, one key set ─────────────────── */

    public function test_the_three_healthcare_language_files_carry_identical_keys(): void
    {
        $en = require base_path('lang/en/health.php');
        $rur = require base_path('lang/rur/health.php');
        $ur = require base_path('lang/ur/health.php');

        $this->assertSame(
            [],
            array_values(array_diff(array_keys($en), array_keys($rur))),
            'Keys present in English but missing from Roman Urdu render as raw keys on a hospital screen.'
        );
        $this->assertSame([], array_values(array_diff(array_keys($rur), array_keys($en))));
        $this->assertSame(
            [],
            array_values(array_diff(array_keys($en), array_keys($ur))),
            'Keys present in English but missing from Urdu render as raw keys on a hospital screen.'
        );
        $this->assertSame([], array_values(array_diff(array_keys($ur), array_keys($en))));
    }

    public function test_no_healthcare_string_is_left_untranslated_or_empty(): void
    {
        $en = require base_path('lang/en/health.php');
        $empty = [];

        foreach (['rur', 'ur'] as $locale) {
            $translated = require base_path("lang/{$locale}/health.php");
            foreach ($translated as $key => $value) {
                if (!is_string($value) || trim($value) === '') {
                    $empty[] = "{$locale}.{$key}";
                }
            }
        }

        $this->assertSame([], $empty, 'Blank translations: ' . implode(', ', $empty));
        $this->assertNotEmpty($en);
    }

    public function test_every_health_lang_key_a_blade_asks_for_actually_exists(): void
    {
        $en = require base_path('lang/en/health.php');
        $missing = [];

        foreach ($this->healthBladeFiles() as $file) {
            $body = (string) file_get_contents($file);
            // Only whole keys. `__('health.gender_' . $row->gender)` builds its
            // key at runtime, and the halves it is built from are not keys.
            preg_match_all("/__\(\s*'health\.([a-z0-9_]+)'\s*[,)]/i", $body, $matches);
            foreach ($matches[1] as $key) {
                if (!array_key_exists($key, $en)) {
                    $missing[] = str_replace(base_path() . '/', '', $file) . ' → health.' . $key;
                }
            }
        }

        $this->assertSame(
            [],
            array_values(array_unique($missing)),
            "A Blade asks for a key that does not exist — the hospital sees the raw key:\n  "
            . implode("\n  ", array_unique($missing))
        );
    }

    public function test_the_import_screen_has_a_label_and_a_description_for_every_dataset(): void
    {
        $en = require base_path('lang/en/health.php');

        foreach (array_keys(Onboarding::DATASETS) as $dataset) {
            foreach ([Onboarding::labelKey($dataset), Onboarding::descriptionKey($dataset)] as $key) {
                $short = str_replace('health.', '', $key);
                $this->assertArrayHasKey($short, $en, "The setup importer offers {$dataset} with no wording for it.");
            }
        }
    }

    /* ───────────────────────────── helpers ────────────────────────────────── */

    /** @return array<int, string> */
    private function healthBladeFiles(): array
    {
        $files = glob(base_path('resources/views/health/**/*.blade.php'), GLOB_BRACE) ?: [];
        $files = array_merge($files, glob(base_path('resources/views/health/*.blade.php')) ?: []);
        $files = array_merge($files, glob(base_path('resources/views/health/**/**/*.blade.php')) ?: []);
        $files = array_merge($files, [base_path('resources/views/layouts/health-app.blade.php')]);

        return array_values(array_unique(array_filter($files, 'is_file')));
    }

    /**
     * Walk everything a hospital or its patients can actually read, and hand
     * each line to a predicate. PHP comments are skipped: a comment recording
     * what a thing used to be called is history, not a surface.
     *
     * @param  callable(string): bool  $matches
     * @param  array<int, string>  $allowedFiles
     * @return array<int, string>
     */
    private function scanCustomerVisible(callable $matches, array $allowedFiles = []): array
    {
        $roots = [
            base_path('resources/views/health'),
            base_path('app/Http/Controllers/Health'),
            base_path('app/Services'),
            base_path('lang/en'),
            base_path('lang/rur'),
            base_path('lang/ur'),
        ];
        $extra = [
            base_path('resources/views/layouts/health-app.blade.php'),
            base_path('app/Support/HealthPanel.php'),
        ];

        $offenders = [];

        foreach ($this->filesUnder($roots, $extra) as $file) {
            $relative = str_replace(base_path() . '/', '', $file);
            if (in_array($relative, $allowedFiles, true)) {
                continue;
            }
            if (!str_contains($relative, 'health') && !str_contains($relative, 'Health') && !str_contains($relative, 'NestErps')) {
                continue;
            }

            foreach (explode("\n", $this->withoutComments((string) file_get_contents($file))) as $number => $line) {
                if (trim($line) === '') {
                    continue;
                }
                if ($matches($line)) {
                    $offenders[] = $relative . ':' . ($number + 1);
                }
            }
        }

        return $offenders;
    }

    /**
     * Blank out every comment while keeping the line numbering intact.
     *
     * A comment that records what something used to be called is history, and
     * history is exactly what a rename leaves behind on purpose. Only what the
     * hospital can actually read counts as a surface.
     */
    private function withoutComments(string $body): string
    {
        $blanked = preg_replace_callback(
            '/\{\{--.*?--\}\}|\/\*.*?\*\//s',
            fn (array $m) => str_repeat("\n", substr_count($m[0], "\n")),
            $body
        );

        return preg_replace('/^\s*(\/\/|\*|#).*$/m', '', (string) $blanked) ?? '';
    }

    /**
     * @param  array<int, string>  $roots
     * @param  array<int, string>  $extra
     * @return array<int, string>
     */
    private function filesUnder(array $roots, array $extra = []): array
    {
        $files = array_filter($extra, 'is_file');

        foreach ($roots as $root) {
            if (!is_dir($root)) {
                continue;
            }
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
            foreach ($iterator as $entry) {
                /** @var \SplFileInfo $entry */
                if ($entry->isFile() && in_array($entry->getExtension(), ['php'], true)) {
                    $files[] = $entry->getPathname();
                }
            }
        }

        return array_values(array_unique($files));
    }

    /* ─────────── the readiness check itself must be runnable ──────────────── */

    public function test_the_readiness_command_passes_for_a_fully_configured_hospital(): void
    {
        // A runbook nobody can EXECUTE is a runbook nobody has verified. This
        // is the command launch morning depends on, so it has to be green
        // against a hospital that really is ready — otherwise its own noise
        // teaches the operator to ignore it.
        $company = $this->company();
        $this->owner($company);
        $this->schedulerIsAlive();

        $this->artisan('health:pilot-readiness', ['--company' => $company->id])
            ->assertExitCode(0);
    }

    public function test_readiness_refuses_a_host_where_a_module_table_never_migrated(): void
    {
        /*
         * The deploy landed and the migration did not.
         *
         * This is the quietest production failure healthcare has: the panel
         * comes up, the OPD queue works, and the pharmacist is the one who
         * finds out — mid queue, on a 500 — that dispensing has no table to
         * write to. Readiness has to name that before launch morning, for
         * EVERY module a hospital could switch on later, not just the ones it
         * happens to be using today.
         */
        $company = $this->company();
        $this->owner($company);
        $this->schedulerIsAlive();

        // SQLite DDL is transactional, so RefreshDatabase's rollback puts every
        // dropped table back for the next test.
        foreach ([
            'health_medicine_batches',   // pharmacy
            'health_admission_charges',  // inpatient
            'health_journal_lines',      // accounts
            'health_roster_entries',     // HR
            'health_doctor_slots',       // OPD
        ] as $table) {
            Schema::drop($table);

            $this->artisan('health:pilot-readiness', ['--company' => $company->id])
                ->expectsOutputToContain($table)
                ->assertExitCode(1);
        }
    }

    public function test_readiness_refuses_a_hospital_whose_core_directory_table_is_gone(): void
    {
        /*
         * The per-hospital rows read a table each, so a missing table has to
         * FAIL that row rather than skip it. Skipping turns the worst case
         * into no output at all — and a green summary printed underneath it,
         * which is worse than never having run the check.
         */
        $company = $this->company();
        $this->owner($company);
        $this->schedulerIsAlive();

        Schema::drop('health_doctors');

        $this->artisan('health:pilot-readiness', ['--company' => $company->id])
            ->expectsOutputToContain('health_doctors')
            ->assertExitCode(1);
    }

    public function test_readiness_refuses_a_host_where_the_scheduler_has_never_run(): void
    {
        // The command class ships with the code, so its existence proves
        // nothing at all. What has to be proved is that a schedule:run cron
        // exists on THIS host — without one, a ward stops accruing bed-days
        // and nothing anywhere says so; the stay looks fine and the discharge
        // bill is simply short.
        $company = $this->company();
        $this->owner($company);

        $this->assertTrue(
            class_exists(\App\Console\Commands\HealthPostIpdDailyCharges::class),
            'The poster class is present — which is exactly why its presence cannot be the check.'
        );

        $this->artisan('health:pilot-readiness', ['--company' => $company->id])
            ->assertExitCode(1);
    }

    public function test_readiness_refuses_a_ward_that_stopped_accruing_days_ago(): void
    {
        // The nastier version: cron is alive, so every other signal looks
        // healthy, but the bed-day entry itself has not posted for days.
        $company = $this->company();
        $this->owner($company);
        $this->schedulerIsAlive();
        SystemSetting::set('health_ipd_daily_charges_last_run', now()->subDays(4)->toDateTimeString());

        $this->artisan('health:pilot-readiness', ['--company' => $company->id])
            ->assertExitCode(1);
    }

    public function test_readiness_refuses_a_hospital_that_is_only_half_approved(): void
    {
        /*
         * The two columns are read by different panels and both have to be
         * right. A "pending" hospital fails the approval middleware on every
         * write — including the setup import the pilot begins with — however
         * active the other column says it is; and an "approved" hospital that
         * is not active is locked out just the same. A readiness check that
         * accepted either would wave through a hospital that cannot save a row.
         */
        foreach ([['pending', 'active'], ['approved', 'inactive']] as [$status, $companyStatus]) {
            HealthScopeService::forget();
            HealthModuleService::forget();

            $company = $this->company();
            $company->forceFill(['status' => $status, 'company_status' => $companyStatus])->save();
            $this->owner($company);
            $this->schedulerIsAlive();

            $this->artisan('health:pilot-readiness', ['--company' => $company->id])
                ->assertExitCode(1, "status={$status}, company_status={$companyStatus} must not read as ready.");

            User::withoutGlobalScopes()->where('company_id', $company->id)->forceDelete();
            Company::withoutGlobalScopes()->whereKey($company->id)->forceDelete();
        }
    }

    /** Both heartbeats fresh: a host whose cron really is firing. */
    private function schedulerIsAlive(): void
    {
        SystemSetting::set('scheduler_last_heartbeat', now()->subMinutes(5)->toDateTimeString());
        SystemSetting::set('health_ipd_daily_charges_last_run', now()->subHours(6)->toDateTimeString());
    }

    public function test_the_readiness_command_refuses_a_hospital_with_no_owner_login(): void
    {
        // The importer and the module switches are owner-only. A pilot handed
        // over without an active owner cannot be set up at all, and that must
        // be a FAIL before go-live rather than a discovery on day one.
        $company = $this->company();

        $this->artisan('health:pilot-readiness', ['--company' => $company->id])
            ->assertExitCode(1);
    }

    private function company(): Company
    {
        HealthScopeService::forget();
        HealthModuleService::forget();

        return Company::create([
            'name' => 'Pilot Readiness Hospital',
            'ntn' => 'PILOT-READY-' . Str::random(8),
            'product_type' => NestErps::PRODUCT_TYPE,
            NestErps::VERTICAL_COLUMN => NestErps::HEALTH,
            'status' => 'approved',
            'company_status' => 'active',
            'health_org_type' => 'hospital',
            'health_modules' => json_encode(HealthModuleService::MODULES),
        ]);
    }

    private function owner(Company $company): User
    {
        return User::create([
            'name' => 'Pilot Owner',
            'email' => 'pilot.ready.' . Str::random(6) . '@example.test',
            'password' => Hash::make('Passw0rd!2026'),
            'company_id' => $company->id,
            'role' => 'company_admin',
            'health_role' => HealthAccessService::ROLE_OWNER,
            'is_active' => true,
        ]);
    }
}
