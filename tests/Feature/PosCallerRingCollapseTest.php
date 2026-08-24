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
 * CALLER ID — ek call ki saari copies ek ring (24 Aug 2026, ZFC Pizza Point).
 *
 * Live par ek hi number 12 minute mein 10 alag rings ban gaya jabke phone par
 * koi call thi hi nahi: phone ke do detector (telephony pehle — number, naam
 * khali; dialer notification baad mein — naam) ek hi call par alag alag waqt
 * bolte hain, aur Android us notification ke HAR update par listener ko dobara
 * jagata hai. Purana 20-second ka window in mein se sirf pehli do copies
 * pakarta tha, baqi har copy nayi ring ban kar cashier ke samne popup kholti
 * rahi.
 *
 * Yahan jo tay kiya ja raha hai:
 *   1. Wohi number window ke andar dobara aaye → nai row nahi banti.
 *   2. Pehli copy be-naam thi aur baad wali mein naam hai → naam usi mojooda
 *      row mein bhar jata hai (Haaliya calls list ko naam milta hai, cashier
 *      ko doosra popup nahi).
 *   3. Mojooda naam kabhi khali se nahi mitta.
 *   4. Window guzar jane ke baad asli dobara call phir bhi darj hoti hai —
 *      collapse "khamoshi" nahi bunta.
 *   5. Do alag numbers kabhi ek doosre mein nahi milte.
 *   6. Be-number (sirf naam) rings bhi isi usool par chalti hain.
 *
 * Pattern: sqlite :memory: + minimal Schema::create, controller invoked
 * directly (same style as PosCallerIdPlanGateTest).
 */
class PosCallerRingCollapseTest extends TestCase
{
    private string $token;
    private int $companyId;

    protected function setUp(): void
    {
        parent::setUp();

        PosFeatureService::flushGateCaches();
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
            $t->timestamp('ring_at')->nullable();
            $t->timestamp('created_at')->nullable();
        });

        // Internal account: planAllows ka escape hatch, taake yeh test sirf
        // collapse ka faisla naapay — plan gate ka apna test alag hai.
        $company = Company::create(['name' => 'Ring Collapse Shop', 'product_type' => 'pos']);
        $this->companyId = (int) $company->id;

        // DB::table par likha ja raha hai: yeh columns Company ke $fillable mein
        // nahi hain aur Eloquent unhen khamoshi se gira deta hai
        // (eloquent-missing-attribute-null) — phir har ring "disabled" ban jati.
        $plain = $company->id . '|ringcollapsetoken';
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
     * Telephony 21:48:45 par bolti hai (number, naam khali), dialer ki
     * notification 21:49:25 par (naam ke sath). Purane 20-second window mein
     * yeh DO rings banti thin — ab ek.
     */
    public function test_second_copy_of_the_same_ring_does_not_create_a_row(): void
    {
        Carbon::setTestNow('2026-08-24 21:48:45');
        $first = $this->ring(['phone' => '03022452414', 'source' => 'sim']);
        $this->assertTrue($first['accepted']);

        Carbon::setTestNow('2026-08-24 21:49:25'); // +40s — purane window se bahar
        $second = $this->ring(['phone' => '03022452414', 'name' => 'Asif Langah', 'source' => 'sim']);

        $this->assertFalse($second['accepted']);
        $this->assertSame('duplicate', $second['reason']);
        $this->assertCount(1, $this->rows());
    }

    /** Be-naam pehli copy ko baad wali copy ka naam mil jata hai. */
    public function test_name_from_the_later_copy_fills_the_existing_row(): void
    {
        Carbon::setTestNow('2026-08-24 21:48:45');
        $this->ring(['phone' => '03022452414', 'source' => 'sim']);
        $this->assertNull($this->rows()->first()->caller_name);

        Carbon::setTestNow('2026-08-24 21:49:25');
        $this->ring(['phone' => '03022452414', 'name' => 'Asif Langah', 'source' => 'sim']);

        $rows = $this->rows();
        $this->assertCount(1, $rows);
        $this->assertSame('Asif Langah', $rows->first()->caller_name);
    }

    /** Jo naam pehle se darj hai woh baad wali be-naam copy se mitta nahi. */
    public function test_existing_name_is_never_blanked_by_a_later_nameless_copy(): void
    {
        Carbon::setTestNow('2026-08-24 21:48:45');
        $this->ring(['phone' => '03022452414', 'name' => 'Asif Langah', 'source' => 'sim']);

        Carbon::setTestNow('2026-08-24 21:49:25');
        $this->ring(['phone' => '03022452414', 'source' => 'sim']);

        $rows = $this->rows();
        $this->assertCount(1, $rows);
        $this->assertSame('Asif Langah', $rows->first()->caller_name);
    }

    /**
     * Collapse "khamoshi" nahi bunta: window guzarne ke baad grahak dobara
     * call kare to woh ring darj bhi hoti hai aur popup bhi kholti hai.
     */
    public function test_a_real_repeat_call_after_the_window_is_recorded(): void
    {
        Carbon::setTestNow('2026-08-24 21:48:45');
        $this->ring(['phone' => '03022452414', 'source' => 'sim']);

        Carbon::setTestNow('2026-08-24 21:51:30'); // +165s
        $again = $this->ring(['phone' => '03022452414', 'source' => 'sim']);

        $this->assertTrue($again['accepted']);
        $this->assertCount(2, $this->rows());
    }

    /** Do alag grahak kabhi ek row mein nahi milte. */
    public function test_a_different_number_is_never_collapsed_into_another(): void
    {
        Carbon::setTestNow('2026-08-24 21:48:45');
        $this->ring(['phone' => '03022452414', 'source' => 'sim']);

        Carbon::setTestNow('2026-08-24 21:48:55'); // usi lamhe ke andar
        $other = $this->ring(['phone' => '03006872227', 'source' => 'sim']);

        $this->assertTrue($other['accepted']);
        $this->assertCount(2, $this->rows());
    }

    /**
     * Be-number ring (WhatsApp par saved contact — sirf naam aata hai) bhi isi
     * usool par: wohi naam window ke andar dobara aaye to nai row nahi.
     */
    public function test_nameless_number_rings_collapse_on_the_caller_name(): void
    {
        Carbon::setTestNow('2026-08-24 21:48:45');
        $first = $this->ring(['name' => 'Zeshan Butt', 'source' => 'whatsapp']);
        $this->assertTrue($first['accepted']);

        Carbon::setTestNow('2026-08-24 21:49:40');
        $second = $this->ring(['name' => 'Zeshan Butt', 'source' => 'whatsapp']);

        $this->assertFalse($second['accepted']);
        $this->assertCount(1, $this->rows());
    }
}
