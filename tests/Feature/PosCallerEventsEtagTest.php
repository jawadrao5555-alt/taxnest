<?php

namespace Tests\Feature;

use App\Http\Controllers\PosCallerIdController;
use App\Models\Company;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Caller-events cursor regression — Task 1097.
 *
 * When the events poll returns no fresh rows it must still advance the
 * `last_id` cursor past any EXPIRED events that arrived after the client's
 * last-seen position, so those stale rows are never re-scanned on subsequent
 * polls.  The original MAX-all-company-events query did this correctly; the
 * Task-1097 optimisation must preserve that behaviour.
 *
 * Run:
 *   php vendor/bin/phpunit tests/Feature/PosCallerEventsEtagTest.php --testdox
 */
class PosCallerEventsEtagTest extends TestCase
{
    private const FRESH_SECONDS = 120; // mirrors PosCallerIdController::EVENT_FRESH_SECONDS

    protected int $companyId;

    protected function setUp(): void
    {
        parent::setUp();
        Schema::dropAllTables();

        Schema::create('companies', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->boolean('caller_id_enabled')->default(true);
            $t->softDeletes();
            $t->timestamps();
        });

        Schema::create('users', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('company_id')->nullable();
            $t->string('name')->nullable();
            $t->string('role')->nullable();
            $t->string('pos_role')->nullable();
            $t->text('pos_custom_access')->nullable();
            $t->timestamps();
        });

        // plan_features / subscriptions tables needed by PosFeatureService
        // planAllows falls back gracefully when tables are absent in test env —
        // but we need companies.caller_id_enabled = true so the early exit passes.
        Schema::create('pos_caller_events', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('company_id');
            $t->string('phone', 20)->nullable();
            $t->string('caller_name', 120)->nullable();
            $t->string('source', 12)->default('sim');
            $t->dateTime('ring_at');
            $t->timestamp('created_at')->useCurrent();
            $t->index(['company_id', 'id']);
        });

        $this->companyId = DB::table('companies')->insertGetId([
            'name'               => 'Caller Test Shop',
            'caller_id_enabled'  => true,
            'created_at'         => now(), 'updated_at' => now(),
        ]);
        app()->bind('currentCompanyId', fn () => $this->companyId);

        // Cache the table-existence check so it does not hit DB on every call.
        Cache::put('caller_events_table_exists', true, 300);
    }

    protected function tearDown(): void
    {
        Cache::forget('caller_events_table_exists');
        Auth::guard('pos')->logout();
        parent::tearDown();
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    protected function actAsAdmin(): void
    {
        DB::table('users')->insert([
            'company_id' => $this->companyId,
            'name'       => 'Admin',
            'role'       => 'user',
            'pos_role'   => 'pos_admin',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        Auth::guard('pos')->setUser(User::orderByDesc('id')->first());
    }

    protected function insertEvent(bool $expired = false): int
    {
        $ts = $expired
            ? now()->subSeconds(self::FRESH_SECONDS + 60)->toDateTimeString()
            : now()->toDateTimeString();

        return DB::table('pos_caller_events')->insertGetId([
            'company_id'  => $this->companyId,
            'phone'       => null,  // null → matchCustomer short-circuits, no pos_customers query
            'caller_name' => null,
            'source'      => 'sim',
            'ring_at'     => $ts,
            'created_at'  => $ts,
        ]);
    }

    protected function eventsResponse(int $after = 0): \Illuminate\Http\JsonResponse
    {
        $req = Request::create('/pos/api/caller-events?after=' . $after, 'GET');
        return (new PosCallerIdController())->events($req);
    }

    // ── Tests ─────────────────────────────────────────────────────────────────

    /**
     * Fresh events ARE returned and last_id advances to the delivered row's id.
     */
    public function test_fresh_event_is_returned_and_last_id_advances(): void
    {
        $this->actAsAdmin();
        $eventId = $this->insertEvent(expired: false);

        $data = $this->eventsResponse(0)->getData();
        $this->assertTrue($data->enabled);
        $this->assertCount(1, $data->events);
        $this->assertEquals($eventId, $data->last_id);
    }

    /**
     * Core regression: after > 0, no fresh events, but an EXPIRED event exists
     * with id > after.  The cursor must advance past the expired event so the
     * next poll does not re-scan it.
     *
     * The broken `$after > 0 → return $after` short-circuit would leave the
     * cursor stuck; the fixed `MAX(id > $after)` query advances it correctly.
     */
    public function test_cursor_advances_past_expired_event_above_after(): void
    {
        $this->actAsAdmin();

        // One fresh event → baseline cursor.
        $freshId = $this->insertEvent(expired: false);
        $r1 = $this->eventsResponse(0)->getData();
        $this->assertEquals($freshId, $r1->last_id, 'Setup: fresh event delivered, cursor at its id');

        // That event ages out (simulate: insert a NEW expired event ABOVE the cursor).
        $expiredId = $this->insertEvent(expired: true);
        $this->assertGreaterThan($freshId, $expiredId, 'Setup: expired event has a higher id');

        // Poll with after=$freshId: no fresh rows, but expired event sits above cursor.
        $r2 = $this->eventsResponse($freshId)->getData();
        $this->assertCount(0, $r2->events, 'Expired event must not appear in events list');

        // The cursor MUST advance to $expiredId so the next poll skips it.
        $this->assertEquals(
            $expiredId,
            $r2->last_id,
            'last_id must advance past the expired event above the cursor to avoid re-scanning it'
        );

        // Confirm: a subsequent poll with the advanced cursor returns nothing and
        // stays put (no new events to skip).
        $r3 = $this->eventsResponse($expiredId)->getData();
        $this->assertEquals($expiredId, $r3->last_id, 'Subsequent poll stays at the advanced cursor');
    }

    /**
     * When no event exists above the cursor (truly nothing new), the cursor
     * stays at $after without an unnecessary MAX query result change.
     */
    public function test_cursor_stays_put_when_no_event_above_after(): void
    {
        $this->actAsAdmin();
        $eventId = $this->insertEvent(expired: false);
        $after   = $eventId; // client is already up-to-date

        $data = $this->eventsResponse($after)->getData();
        $this->assertCount(0, $data->events);
        $this->assertEquals($after, $data->last_id, 'Nothing new → cursor stays put');
    }
}
