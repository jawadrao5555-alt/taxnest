<?php

namespace Tests\Feature;

use Tests\TestCase;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use App\Http\Controllers\PosRiderController;
use App\Services\RiderPushService;

/**
 * FCM "key present" log dedupe — Task #1144.
 *
 * Confirms that PosRiderController::logFcmKeyPresenceOnce() — the real
 * production guard that backs the index() page — fires the
 * "Firebase credential is present" log entry at most ONCE per 24 h window,
 * regardless of how many times the riders page is loaded.
 *
 * Approach:
 *  - Configure a structurally valid (but fake) Firebase service-account JSON
 *    via config('services.fcm.credentials_json') so that
 *    RiderPushService::isConfigured() returns TRUE, exercising the full
 *    credential-load → isConfigured() → cache check → log chain.
 *  - Reset RiderPushService's per-request static memo between tests via
 *    Reflection so each test sees a clean credential load.
 *  - Use Carbon::setTestNow() to simulate 24-hour TTL expiry instead of
 *    manually forgetting the cache key.
 */
class FcmKeyPresentLogDedupeTest extends TestCase
{
    private const CACHE_KEY = 'fcm_key_logged';
    private const LOG_MSG   = 'RiderPushService: Firebase credential is present — instant push is ACTIVE.';

    /** Minimal structurally-valid service-account JSON (private_key need not be a real RSA key for isConfigured()). */
    private const FAKE_CREDENTIAL = [
        'type'          => 'service_account',
        'project_id'    => 'taxnest-test-project',
        'client_email'  => 'test@taxnest-test-project.iam.gserviceaccount.com',
        'private_key'   => "-----BEGIN RSA PRIVATE KEY-----\nMIIEpAIBAAKCAQEA0Z3VS5JJcds3xHn/ygWep4PAtEsHAATT6IXvbVeOG8s1\n-----END RSA PRIVATE KEY-----\n",
    ];

    protected function setUp(): void
    {
        parent::setUp();

        // Wire the fake credential so isConfigured() returns true.
        config(['services.fcm.credentials_json' => json_encode(self::FAKE_CREDENTIAL)]);
        // Also clear the file path so the JSON env takes precedence.
        config(['services.fcm.credentials_file' => '']);

        // Reset RiderPushService static memo (per-request memoisation).
        $this->resetRiderPushServiceMemo();

        // Start each test with no cache entry and real-world time.
        Cache::forget(self::CACHE_KEY);
        Carbon::setTestNow(null);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow(null);
        Cache::forget(self::CACHE_KEY);
        $this->resetRiderPushServiceMemo();
        parent::tearDown();
    }

    // ─── Helper ─────────────────────────────────────────────────────────────

    /**
     * Reset the static memoisation in RiderPushService so the next
     * isConfigured() call re-reads the credential from config rather than
     * returning the cached result from a previous test.
     */
    private function resetRiderPushServiceMemo(): void
    {
        $ref = new \ReflectionClass(RiderPushService::class);

        $loaded = $ref->getProperty('credsLoaded');
        $loaded->setAccessible(true);
        $loaded->setValue(null, false);

        $memo = $ref->getProperty('credsMemo');
        $memo->setAccessible(true);
        $memo->setValue(null, null);
    }

    // ─── Sanity: credential is actually loaded ───────────────────────────────

    public function test_is_configured_returns_true_with_fake_credential(): void
    {
        $this->assertTrue(
            RiderPushService::isConfigured(),
            'Fake credential must make isConfigured() return true so the guard can run.'
        );
    }

    // ─── Core invariants ─────────────────────────────────────────────────────

    /** First load → exactly one log entry with the exact production message. */
    public function test_log_fires_once_on_first_load(): void
    {
        Log::spy();

        PosRiderController::logFcmKeyPresenceOnce();

        Log::shouldHaveReceived('info')
            ->once()
            ->with(self::LOG_MSG);
    }

    /** Second load within 24 h → log must NOT fire again. */
    public function test_log_does_not_fire_on_second_load_within_ttl(): void
    {
        Log::spy();

        PosRiderController::logFcmKeyPresenceOnce(); // first visit  — logs
        PosRiderController::logFcmKeyPresenceOnce(); // second visit — silent

        Log::shouldHaveReceived('info')
            ->once()     // still exactly one call total
            ->with(self::LOG_MSG);
    }

    /** Ten loads within the window → log fires exactly once regardless. */
    public function test_log_fires_exactly_once_across_many_loads(): void
    {
        Log::spy();

        foreach (range(1, 10) as $_) {
            PosRiderController::logFcmKeyPresenceOnce();
        }

        Log::shouldHaveReceived('info')
            ->once()
            ->with(self::LOG_MSG);
    }

    /** Cache key is set after the first load so future calls are suppressed. */
    public function test_cache_key_is_written_after_first_load(): void
    {
        $this->assertFalse(Cache::has(self::CACHE_KEY), 'cache key absent before first load');

        PosRiderController::logFcmKeyPresenceOnce();

        $this->assertTrue(Cache::has(self::CACHE_KEY), 'cache key present after first load');
    }

    /** No credential → guard is silent and no cache entry is written. */
    public function test_log_does_not_fire_when_credential_absent(): void
    {
        // Remove the credential.
        config(['services.fcm.credentials_json' => '', 'services.fcm.credentials_file' => '']);
        $this->resetRiderPushServiceMemo();

        Log::spy();

        PosRiderController::logFcmKeyPresenceOnce();

        Log::shouldNotHaveReceived('info');
        $this->assertFalse(Cache::has(self::CACHE_KEY));
    }

    /**
     * After the 24-hour TTL elapses the guard fires again on the next load.
     * Uses Carbon::setTestNow() to advance time — no manual cache::forget.
     */
    public function test_log_fires_again_after_24h_ttl_elapses(): void
    {
        Carbon::setTestNow(Carbon::now());

        Log::spy();

        PosRiderController::logFcmKeyPresenceOnce(); // first window: logs + sets cache

        // Advance past the 24-hour TTL so the array-driver entry expires.
        Carbon::setTestNow(Carbon::now()->addHours(25));

        PosRiderController::logFcmKeyPresenceOnce(); // second window: cache expired → logs again

        Log::shouldHaveReceived('info')
            ->twice()
            ->with(self::LOG_MSG);
    }

    /**
     * Confirm the production environment (no credential) keeps isConfigured()
     * false by default — guards against accidental log spam in CI.
     */
    public function test_is_configured_false_without_any_credential_config(): void
    {
        config(['services.fcm.credentials_json' => '', 'services.fcm.credentials_file' => '']);
        $this->resetRiderPushServiceMemo();

        $this->assertFalse(RiderPushService::isConfigured());
    }
}
