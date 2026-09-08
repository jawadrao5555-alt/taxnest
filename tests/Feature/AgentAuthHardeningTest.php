<?php

namespace Tests\Feature;

use App\Http\Middleware\AgentAuth;
use App\Models\Company;
use App\Support\AgentApiKey;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Desktop-agent API key hardening (security remediation, Sep 2026).
 *
 *  - AgentAuth authenticates through companies.agent_api_key_hash (sha256),
 *    re-verified with hash_equals; legacy plaintext-only rows still work once
 *    and are healed (hash backfilled).
 *  - The Company model keeps the hash in sync and never serialises the key.
 *  - /api/agent/* carries the named 'agent-api' throttle; the 401 path is
 *    additionally rate-limited per IP without locking out known-good keys.
 */
class AgentAuthHardeningTest extends TestCase
{
    private const KEY = 'tnk_hardening_test_key_0123456789abcdef0123456789abcdef';

    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropAllTables();

        Schema::create('companies', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('company_status')->default('approved');
            $table->string('agent_api_key')->nullable();
            $table->string('agent_api_key_hash', 64)->nullable()->index();
            $table->boolean('agent_enabled')->default(false);
            $table->timestamp('agent_last_seen')->nullable();
            $table->text('pos_printer_settings')->nullable();
            $table->softDeletes();
            $table->timestamps();
        });

        Schema::create('pos_print_jobs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->string('type');
            $table->string('target_printer')->nullable();
            $table->unsignedBigInteger('transaction_id')->nullable();
            $table->unsignedBigInteger('restaurant_order_id')->nullable();
            $table->string('render_query')->nullable();
            $table->string('status')->default('pending');
            $table->string('claim_token')->nullable();
            $table->unsignedInteger('attempts')->default(0);
            $table->text('error')->nullable();
            $table->timestamps();
        });

        Cache::flush();
    }

    private function insertCompany(array $attrs): int
    {
        return DB::table('companies')->insertGetId(array_merge([
            'name' => 'Hardening Shop',
            'agent_enabled' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ], $attrs));
    }

    private function poll(?string $key, array $headers = []): \Illuminate\Testing\TestResponse
    {
        if ($key !== null) {
            $headers['Authorization'] = 'Bearer ' . $key;
        }

        return $this->getJson('/api/agent/print-jobs', $headers);
    }

    // ── Lookup paths ──────────────────────────────────────────────────────

    public function test_hash_only_row_authenticates_via_hash_column(): void
    {
        $this->insertCompany([
            'agent_api_key' => null,
            'agent_api_key_hash' => hash('sha256', self::KEY),
        ]);

        $this->poll(self::KEY)->assertOk()->assertJson(['ok' => true]);
    }

    public function test_x_agent_key_header_authenticates_via_hash_column(): void
    {
        $this->insertCompany([
            'agent_api_key' => self::KEY,
            'agent_api_key_hash' => hash('sha256', self::KEY),
        ]);

        $this->getJson('/api/agent/print-jobs', ['X-Agent-Key' => self::KEY])->assertOk();
    }

    public function test_legacy_plaintext_only_row_authenticates_and_is_backfilled(): void
    {
        $id = $this->insertCompany(['agent_api_key' => self::KEY, 'agent_api_key_hash' => null]);

        $this->poll(self::KEY)->assertOk();

        $this->assertSame(
            hash('sha256', self::KEY),
            DB::table('companies')->where('id', $id)->value('agent_api_key_hash'),
            'legacy row must be healed on first successful auth'
        );

        // Second request takes the hashed path (plaintext no longer needed).
        DB::table('companies')->where('id', $id)->update(['agent_api_key' => null]);
        $this->poll(self::KEY)->assertOk();
    }

    public function test_wrong_key_is_401_with_legacy_json_shape(): void
    {
        $this->insertCompany([
            'agent_api_key' => self::KEY,
            'agent_api_key_hash' => hash('sha256', self::KEY),
        ]);

        $this->poll('tnk_definitely_not_the_key')
            ->assertStatus(401)
            ->assertExactJson(['error' => 'Invalid or disabled agent key']);
    }

    public function test_missing_key_is_401_with_legacy_json_shape(): void
    {
        $this->poll(null)
            ->assertStatus(401)
            ->assertExactJson(['error' => 'Missing agent API key']);
    }

    public function test_disabled_agent_is_401_even_with_correct_key(): void
    {
        $this->insertCompany([
            'agent_api_key' => self::KEY,
            'agent_api_key_hash' => hash('sha256', self::KEY),
            'agent_enabled' => false,
        ]);

        $this->poll(self::KEY)->assertStatus(401)->assertJson(['error' => 'Invalid or disabled agent key']);
    }

    // ── Model behaviour ───────────────────────────────────────────────────

    public function test_model_keeps_hash_in_sync_and_hides_key_from_serialisation(): void
    {
        $company = Company::create(['name' => 'Model Shop', 'agent_api_key' => self::KEY, 'agent_enabled' => true]);

        $this->assertSame(hash('sha256', self::KEY), $company->fresh()->getAttribute('agent_api_key_hash'));

        $company->update(['agent_api_key' => 'tnk_rotated']);
        $this->assertSame(hash('sha256', 'tnk_rotated'), $company->fresh()->getAttribute('agent_api_key_hash'));

        // Explicit reads keep working (panel blades / regenerateKey JSON).
        $this->assertSame('tnk_rotated', $company->agent_api_key);

        $array = $company->fresh()->toArray();
        $json = json_decode($company->fresh()->toJson(), true);
        $this->assertArrayNotHasKey('agent_api_key', $array);
        $this->assertArrayNotHasKey('agent_api_key_hash', $array);
        $this->assertArrayNotHasKey('agent_api_key', $json);
        $this->assertStringNotContainsString('tnk_rotated', $company->fresh()->toJson());

        $company->update(['agent_api_key' => null]);
        $this->assertNull($company->fresh()->getAttribute('agent_api_key_hash'));
    }

    public function test_hash_helper_tolerates_schema_without_hash_column(): void
    {
        Schema::table('companies', function (Blueprint $t) {
            $t->dropIndex(['agent_api_key_hash']);
            $t->dropColumn('agent_api_key_hash');
        });
        AgentApiKey::flushSchemaCache();

        $this->assertFalse(AgentApiKey::hashColumnAvailable());

        Company::create(['name' => 'Old Schema Shop', 'agent_api_key' => self::KEY, 'agent_enabled' => true]);

        // Plaintext path still authenticates (pre-migration deploy window).
        $this->poll(self::KEY)->assertOk();
    }

    // ── Throttling ────────────────────────────────────────────────────────

    public function test_agent_routes_carry_named_throttle_and_v2_is_not_double_wrapped(): void
    {
        $routes = Route::getRoutes();

        $v1 = $routes->match(Request::create('/api/agent/print-jobs', 'GET'));
        $this->assertContains('throttle:agent-api', $v1->gatherMiddleware());
        $this->assertContains('agent.auth', $v1->gatherMiddleware());

        $heartbeat = $routes->match(Request::create('/api/agent/heartbeat', 'POST'));
        $this->assertContains('throttle:agent-api', $heartbeat->gatherMiddleware());

        $v2 = $routes->match(Request::create('/api/agent/v2/status', 'GET'));
        $this->assertNotContains('throttle:agent-api', $v2->gatherMiddleware());
        $this->assertContains('throttle:60,1', $v2->gatherMiddleware());
    }

    public function test_named_throttle_returns_429_once_limit_exceeded(): void
    {
        RateLimiter::for('agent-api', fn () => Limit::perMinute(2)->by('hardening-test'));

        $this->poll('tnk_bad')->assertStatus(401);
        $this->poll('tnk_bad')->assertStatus(401);
        $this->poll('tnk_bad')->assertStatus(429);
    }

    public function test_named_throttle_is_keyed_by_presented_key(): void
    {
        $this->insertCompany([
            'agent_api_key' => self::KEY,
            'agent_api_key_hash' => hash('sha256', self::KEY),
        ]);

        $limiter = RateLimiter::limiter('agent-api');
        $good = $limiter(Request::create('/api/agent/heartbeat', 'POST', [], [], [], ['HTTP_AUTHORIZATION' => 'Bearer ' . self::KEY]));
        $other = $limiter(Request::create('/api/agent/heartbeat', 'POST', [], [], [], ['HTTP_AUTHORIZATION' => 'Bearer tnk_other']));
        $none = $limiter(Request::create('/api/agent/heartbeat', 'POST'));

        $this->assertSame(600, $good->maxAttempts);
        $this->assertNotSame($good->key, $other->key, 'different keys must not share a bucket');
        $this->assertStringNotContainsString(self::KEY, $good->key, 'raw key must not appear in cache keys');
        $this->assertStringStartsWith('agent-ip:', $none->key);
    }

    public function test_failed_auth_is_throttled_per_ip_but_known_good_key_keeps_working(): void
    {
        $this->insertCompany([
            'agent_api_key' => self::KEY,
            'agent_api_key_hash' => hash('sha256', self::KEY),
        ]);

        // Establish the healthy counter first.
        $this->poll(self::KEY)->assertOk();

        for ($i = 0; $i < AgentAuth::FAIL_LIMIT_PER_MINUTE - 1; $i++) {
            $this->poll('tnk_guess_' . $i)->assertStatus(401);
        }
        // The attempt that crosses the budget answers 429, as does everything after.
        $this->poll('tnk_guess_final')->assertStatus(429);
        $this->poll('tnk_guess_again')->assertStatus(429)->assertHeader('Retry-After');

        // A never-seen key from the same IP is refused before the DB lookup.
        $this->poll('tnk_still_guessing')->assertStatus(429);

        // The healthy counter behind the same NAT is unaffected.
        $this->poll(self::KEY)->assertOk();
    }

    public function test_long_poll_wait_still_holds_under_new_auth(): void
    {
        $this->insertCompany([
            'agent_api_key' => self::KEY,
            'agent_api_key_hash' => hash('sha256', self::KEY),
        ]);
        Cache::put('print_recent_activity_1', 1, now()->addMinutes(20));
        config(['print.longpoll_max_holds' => 10, 'print.longpoll_max_wait' => 8]);

        $res = $this->getJson('/api/agent/print-jobs?wait=1', ['Authorization' => 'Bearer ' . self::KEY]);
        $res->assertOk()->assertJson(['ok' => true, 'count' => 0, 'held' => true]);
    }
}
