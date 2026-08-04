<?php

namespace Tests\Feature;

use Tests\TestCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;

/**
 * Root ADMS endpoints — SN-identified (4 Aug 2026).
 *
 * K50/K40-class ZKTeco firmware only accepts a bare server address + port
 * (no URL path), so those devices push to /iclock/cdata at the domain root
 * and are identified by ?SN= against a pre-registered device_sn.
 *
 * Tests:
 *   1. GET /iclock/cdata?SN=known       → 200 handshake (GET OPTION FROM).
 *   2. GET /iclock/cdata?SN=unknown     → 403 ERROR.
 *   3. GET /iclock/cdata (no SN)        → 403 ERROR.
 *   4. POST ATTLOG with known SN        → punch saved, company-scoped, PIN mapped.
 *   5. Duplicate active SN (2 devices)  → 403, nothing saved (cross-company guard).
 *   6. Inactive device SN               → 403.
 *   7. /iclock/getrequest               → 200 OK (no commands).
 *
 * Pattern: APP_ENV=testing + sqlite :memory: + manual Schema::create.
 *
 * Run:
 *   env -u DATABASE_URL -u DB_CONNECTION -u PGHOST -u PGPORT -u PGUSER \
 *     -u PGPASSWORD -u PGDATABASE APP_ENV=testing DB_CONNECTION=sqlite \
 *     DB_DATABASE=':memory:' php vendor/bin/phpunit tests/Feature/BiometricAdmsRootSnTest.php
 */
class BiometricAdmsRootSnTest extends TestCase
{
    private int $companyId;
    private int $staffId;
    private int $deviceId;
    private string $sn = 'BDC2190780001';

    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropAllTables();

        Schema::create('companies', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('product_type')->nullable();
            $table->string('status')->default('approved');
            $table->string('company_status')->default('approved');
            $table->softDeletes();
            $table->timestamps();
        });

        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password');
            $table->unsignedBigInteger('company_id')->nullable();
            $table->string('role')->nullable();
            $table->string('pos_role')->nullable();
            $table->boolean('is_active')->default(true);
            $table->rememberToken();
            $table->timestamps();
        });

        Schema::create('system_settings', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->text('value')->nullable();
            $table->timestamps();
        });

        Schema::create('pos_biometric_devices', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->string('label', 100);
            $table->string('device_sn', 100)->nullable();
            $table->string('push_token', 64)->unique();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
        Schema::create('pos_biometric_user_map', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('device_id');
            $table->string('device_pin', 50);
            $table->unsignedBigInteger('user_id');
            $table->timestamps();
            $table->unique(['device_id', 'device_pin'], 'pbum_device_pin_unique');
        });
        Schema::create('pos_biometric_punches', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('device_id')->nullable();
            $table->string('device_pin', 50)->nullable();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->dateTime('punched_at');
            $table->enum('punch_type', ['check_in', 'check_out', 'unknown'])->default('unknown');
            $table->string('raw_data', 500)->nullable();
            $table->string('source', 20)->default('adms');
            $table->timestamps();
            $table->unique(['device_id', 'device_pin', 'punched_at'], 'pbp_device_pin_ts_unique');
        });
        Schema::create('pos_bio_pin_alerts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->string('device_pin', 50);
            $table->dateTime('first_seen_at');
            $table->dateTime('dismissed_at')->nullable();
            $table->dateTime('mapped_at')->nullable();
            $table->timestamps();
            $table->unique(['company_id', 'device_pin'], 'pbpa_company_pin_unique');
        });

        $now = now();
        $this->companyId = DB::table('companies')->insertGetId([
            'name' => 'K50 Shop', 'product_type' => 'pos',
            'created_at' => $now, 'updated_at' => $now,
        ]);
        $this->staffId = DB::table('users')->insertGetId([
            'name' => 'Mapped Staff', 'email' => 'k50staff@test.test',
            'password' => 'x', 'company_id' => $this->companyId,
            'pos_role' => 'pos_cashier',
            'created_at' => $now, 'updated_at' => $now,
        ]);
        $this->deviceId = DB::table('pos_biometric_devices')->insertGetId([
            'company_id' => $this->companyId,
            'label'      => 'K50 Main Door',
            'device_sn'  => $this->sn,
            'push_token' => 'k50token123abc',
            'is_active'  => true,
            'created_at' => $now, 'updated_at' => $now,
        ]);
        DB::table('pos_biometric_user_map')->insert([
            'company_id' => $this->companyId,
            'device_id'  => $this->deviceId,
            'device_pin' => '7',
            'user_id'    => $this->staffId,
            'created_at' => $now, 'updated_at' => $now,
        ]);
    }

    /** Raw-body POST helper (ADMS pushes plain-text tab-separated lines). */
    private function admsRootPost(string $sn, string $body): \Illuminate\Testing\TestResponse
    {
        return $this->call(
            'POST',
            '/iclock/cdata?SN=' . urlencode($sn) . '&table=ATTLOG',
            [], [], [],
            ['CONTENT_TYPE' => 'text/plain'],
            $body
        );
    }

    public function test_root_handshake_with_registered_sn(): void
    {
        $res = $this->get('/iclock/cdata?SN=' . $this->sn . '&options=all');
        $res->assertStatus(200);
        $this->assertStringContainsString('GET OPTION FROM', $res->getContent());
        $this->assertStringContainsString('ServerName=NestPOS', $res->getContent());
    }

    public function test_root_handshake_unknown_sn_rejected(): void
    {
        $this->get('/iclock/cdata?SN=NOPE999')->assertStatus(403);
    }

    public function test_root_handshake_missing_sn_rejected(): void
    {
        $this->get('/iclock/cdata')->assertStatus(403);
    }

    public function test_root_attlog_saves_punch_and_maps_pin(): void
    {
        $body = "7\t2026-08-04 09:05:00\t1\t0\t0\t\r\n"
              . "42\t2026-08-04 09:06:00\t1\t1\t0\t\r\n";
        $res = $this->admsRootPost($this->sn, $body);
        $res->assertStatus(200);
        $this->assertStringContainsString('OK: 2', $res->getContent());

        $this->assertDatabaseHas('pos_biometric_punches', [
            'company_id' => $this->companyId,
            'device_id'  => $this->deviceId,
            'device_pin' => '7',
            'user_id'    => $this->staffId,
            'punch_type' => 'check_in',
            'source'     => 'adms',
        ]);
        // Unmapped PIN 42: saved with null user + alert fired.
        $this->assertDatabaseHas('pos_biometric_punches', [
            'device_pin' => '42',
            'user_id'    => null,
            'punch_type' => 'check_out',
        ]);
        $this->assertDatabaseHas('pos_bio_pin_alerts', [
            'company_id' => $this->companyId,
            'device_pin' => '42',
        ]);
    }

    public function test_duplicate_active_sn_rejected(): void
    {
        // Second company registers the SAME serial number — ambiguity must be
        // rejected so punches never leak into the wrong company.
        $otherCompany = DB::table('companies')->insertGetId([
            'name' => 'Other Shop', 'product_type' => 'pos',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('pos_biometric_devices')->insert([
            'company_id' => $otherCompany,
            'label'      => 'Clone SN',
            'device_sn'  => $this->sn,
            'push_token' => 'othertoken456def',
            'is_active'  => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->get('/iclock/cdata?SN=' . $this->sn)->assertStatus(403);
        $this->admsRootPost($this->sn, "7\t2026-08-04 10:00:00\t1\t0\t0\t\r\n")->assertStatus(403);
        $this->assertDatabaseCount('pos_biometric_punches', 0);
    }

    public function test_inactive_device_sn_rejected(): void
    {
        DB::table('pos_biometric_devices')->where('id', $this->deviceId)->update(['is_active' => false]);
        $this->get('/iclock/cdata?SN=' . $this->sn)->assertStatus(403);
    }

    public function test_getrequest_returns_ok(): void
    {
        $this->get('/iclock/getrequest?SN=' . $this->sn)->assertStatus(200);
        $this->assertStringContainsString('OK', $this->get('/iclock/getrequest?SN=' . $this->sn)->getContent());
    }
}
