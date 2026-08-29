<?php

namespace Tests\Feature;

use App\Http\Controllers\PosCallerIdController;
use App\Models\Company;
use App\Services\PosFeatureService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * CALLER ID — ek call, ek popup, chahe ring kisi bhi raaste se aaye (LAN Mode).
 *
 * Internet girne par phone apni ring shop ke PC (LAN) ko bhejta hai; line
 * wapas aane par wohi ring cloud par bhi pohanchti hai. Agar ring ki apni
 * shanakht (uuid) dono raston par saath na chale to counter par ek hi call
 * do dafa popup karti hai.
 *
 * Yahan ka gap yeh hai jo waqt ka purana window pakar hi nahi sakta: phone ne
 * cloud ko POST kiya, jawab raaste mein gum ho gaya — phone samjha nakaam,
 * aur usne LAN par bhej diya. Dono jagah ek hi uuid hai, is liye cloud usay
 * pehchan kar dobara darj nahi karta.
 *
 * Jo yahan tay ho raha hai:
 *   1. Ek hi uuid ki doosri copy nai row nahi banati.
 *   2. Yeh pehchan waqt ki mohtaaj nahi — ghanton baad bhi wohi uuid duplicate
 *      hai (yehi purane 20-second window se asal farq hai).
 *   3. Alag uuid = alag call, poori tarah darj (server kabhi call nahi chupata).
 *   4. Purani app (bila uuid) bilkul pehle jaisi chalti hai.
 *   5. uuid asal mein column mein likha jata hai, warna agent ka replay usay
 *      pehchan hi nahi sakta.
 *   6. Be-naam pehli copy ko baad wali copy ka naam mil jata hai.
 */
class PosCallerRingUuidTest extends TestCase
{
    private string $token;
    private int $companyId;

    protected function setUp(): void
    {
        parent::setUp();

        PosFeatureService::flushGateCaches();
        PosCallerIdController::flushCallerUuidColumnCache();
        Schema::dropAllTables();

        Schema::create('companies', function (Blueprint $t) {
            $t->id();
            $t->string('name')->nullable();
            $t->string('product_type')->default('pos');
            $t->boolean('is_internal_account')->default(false);
            $t->boolean('caller_id_enabled')->default(false);
            $t->string('caller_app_token')->nullable();
            $t->unsignedBigInteger('caller_app_user_id')->nullable();
            $t->string('caller_app_device')->nullable();
            $t->timestamp('caller_app_last_seen_at')->nullable();
            $t->string('status')->default('active');
            $t->string('company_status')->default('active');
            $t->softDeletes();
            $t->timestamps();
        });

        Schema::create('pos_caller_events', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('company_id');
            $t->string('phone')->nullable();
            $t->string('caller_name')->nullable();
            $t->string('source')->default('sim');
            $t->string('offline_uuid', 64)->nullable();
            $t->timestamp('ring_at')->nullable();
            $t->timestamp('created_at')->nullable();
        });

        $company = Company::create(['name' => 'Uuid Lane Shop', 'product_type' => 'pos']);
        $this->companyId = (int) $company->id;

        $plain = $company->id . '|ringuuidtoken';
        DB::table('companies')->where('id', $company->id)->update([
            'is_internal_account' => true,
            'caller_id_enabled' => true,
            'caller_app_token' => hash('sha256', $plain),
        ]);
        $this->token = $plain;
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        PosCallerIdController::flushCallerUuidColumnCache();
        parent::tearDown();
    }

    /** POST /api/caller-app/v1/ring */
    private function ring(array $payload): array
    {
        $request = Request::create('/api/caller-app/v1/ring', 'POST', $payload);
        $request->headers->set('Authorization', 'Bearer ' . $this->token);
        app()->instance('request', $request);
        return app(PosCallerIdController::class)->appRing($request)->getData(true);
    }

    private function rows(): \Illuminate\Support\Collection
    {
        return DB::table('pos_caller_events')
            ->where('company_id', $this->companyId)->orderBy('id')->get();
    }

    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Cloud ka jawab gum hua, phone ne LAN par bheja, phir wohi ring cloud par
     * aai — dono par ek hi uuid, is liye ek hi row.
     */
    public function test_the_same_uuid_never_becomes_a_second_row(): void
    {
        Carbon::setTestNow('2026-08-29 20:10:00');
        $first = $this->ring(['phone' => '03022452414', 'source' => 'sim', 'uuid' => 'ring-abc-1']);
        $this->assertTrue($first['accepted']);

        $second = $this->ring(['phone' => '03022452414', 'source' => 'sim', 'uuid' => 'ring-abc-1']);
        $this->assertFalse($second['accepted']);
        $this->assertSame('duplicate', $second['reason']);
        $this->assertCount(1, $this->rows());
    }

    /**
     * ASAL FAIDA: yeh pehchan waqt ki mohtaaj nahi.
     *
     * Agent LAN ki rings tab bhejta hai jab line wapas aati hai — woh ghanton
     * baad ho sakta hai. Purana 20-second window aisi copy ko nai call samajh
     * kar darj kar leta, aur cashier ko doosra popup milta.
     */
    public function test_the_uuid_still_matches_hours_later(): void
    {
        Carbon::setTestNow('2026-08-29 14:00:00');
        $this->ring(['phone' => '03022452414', 'source' => 'sim', 'uuid' => 'ring-late-1']);

        Carbon::setTestNow('2026-08-29 19:30:00'); // line saarhay paanch ghantay baad aai
        $replay = $this->ring(['phone' => '03022452414', 'source' => 'sim', 'uuid' => 'ring-late-1']);

        $this->assertFalse($replay['accepted']);
        $this->assertSame('duplicate', $replay['reason']);
        $this->assertCount(1, $this->rows());
    }

    /** Alag uuid = alag call. Server kabhi asli call nahi chupata. */
    public function test_a_different_uuid_is_always_recorded(): void
    {
        Carbon::setTestNow('2026-08-29 20:10:00');
        $this->ring(['phone' => '03022452414', 'source' => 'sim', 'uuid' => 'ring-one']);

        Carbon::setTestNow('2026-08-29 20:12:00'); // asli dobara call
        $again = $this->ring(['phone' => '03022452414', 'source' => 'sim', 'uuid' => 'ring-two']);

        $this->assertTrue($again['accepted']);
        $this->assertCount(2, $this->rows());
    }

    /** Purani app uuid bhejti hi nahi — uska raasta bilkul pehle jaisa hai. */
    public function test_an_older_app_without_a_uuid_still_works(): void
    {
        Carbon::setTestNow('2026-08-29 20:10:00');
        $first = $this->ring(['phone' => '03022452414', 'source' => 'sim']);
        $this->assertTrue($first['accepted']);

        Carbon::setTestNow('2026-08-29 20:11:30'); // window se bahar = asli nai call
        $second = $this->ring(['phone' => '03022452414', 'source' => 'sim']);
        $this->assertTrue($second['accepted']);

        $rows = $this->rows();
        $this->assertCount(2, $rows);
        $this->assertNull($rows->first()->offline_uuid);
    }

    /**
     * uuid sirf jhaanka nahi jata, likha bhi jata hai — warna agent ka LAN
     * replay usi ring ko dobara darj kar deta.
     */
    public function test_the_uuid_is_actually_stored_on_the_row(): void
    {
        Carbon::setTestNow('2026-08-29 20:10:00');
        $this->ring(['phone' => '03022452414', 'source' => 'sim', 'uuid' => 'ring-stored-1']);

        $this->assertSame('ring-stored-1', $this->rows()->first()->offline_uuid);
    }

    /** Be-naam pehli copy ko baad wali copy ka naam mil jata hai. */
    public function test_a_later_copy_fills_in_a_missing_name(): void
    {
        Carbon::setTestNow('2026-08-29 20:10:00');
        $this->ring(['phone' => '03022452414', 'source' => 'sim', 'uuid' => 'ring-name-1']);
        $this->assertNull($this->rows()->first()->caller_name);

        Carbon::setTestNow('2026-08-29 20:40:00');
        $this->ring([
            'phone' => '03022452414', 'name' => 'Asif Langah',
            'source' => 'sim', 'uuid' => 'ring-name-1',
        ]);

        $rows = $this->rows();
        $this->assertCount(1, $rows);
        $this->assertSame('Asif Langah', $rows->first()->caller_name);
    }

    /**
     * Column abhi na bana ho (shop mid-migration) to ring phir bhi darj ho —
     * feature ghayab ho sakta hai, call nahi.
     */
    public function test_a_shop_without_the_column_still_records_the_ring(): void
    {
        Schema::table('pos_caller_events', function (Blueprint $t) {
            $t->dropColumn('offline_uuid');
        });
        PosCallerIdController::flushCallerUuidColumnCache();

        Carbon::setTestNow('2026-08-29 20:10:00');
        $res = $this->ring(['phone' => '03022452414', 'source' => 'sim', 'uuid' => 'ring-noschema']);

        $this->assertTrue($res['accepted']);
        $this->assertCount(1, $this->rows());
    }
}
