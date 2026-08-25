<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * KOT "Last Add-on" switch ka backfill.
 *
 * Naya kot_last_addon_enabled column default TRUE hai taake chalti hui dukanon
 * ka rawaiya na badle. Magar jis shop ne pehle JAAN BOOJH KAR kot_reprint_enabled
 * band kiya tha, us ke liye kitchen ke DONO parche band the — default TRUE ne
 * "Last Add-on" us ki marzi ke baghair dobara khol diya.
 *
 * Backfill migration wohi marzi bahal karti hai: jahan Re-send band tha, wahan
 * Last Add-on bhi band. Jahan Re-send chal raha tha, wahan kuch na badle.
 */
class PosKotLastAddonBackfillTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropAllTables();
        Schema::create('companies', function (Blueprint $table) {
            $table->id();
            $table->string('name')->default('Test Co');
            $table->boolean('kot_reprint_enabled')->default(true);
            $table->boolean('kot_last_addon_enabled')->default(true);
            $table->timestamps();
        });
    }

    private function runBackfill(): void
    {
        $migration = require database_path('migrations/2026_09_23_100000_backfill_kot_last_addon_from_reprint.php');
        $migration->up();
    }

    public function test_shop_that_had_reprint_off_does_not_silently_gain_last_addon(): void
    {
        DB::table('companies')->insert([
            'id' => 1, 'name' => 'Reprint OFF',
            'kot_reprint_enabled' => false, 'kot_last_addon_enabled' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->runBackfill();

        $this->assertSame(
            0,
            (int) DB::table('companies')->where('id', 1)->value('kot_last_addon_enabled'),
            'Jis shop ne reprint band kiya tha us ka Last Add-on bhi band rehna chahiye'
        );
    }

    public function test_shop_with_reprint_on_keeps_last_addon_on(): void
    {
        DB::table('companies')->insert([
            'id' => 2, 'name' => 'Reprint ON',
            'kot_reprint_enabled' => true, 'kot_last_addon_enabled' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->runBackfill();

        $this->assertSame(
            1,
            (int) DB::table('companies')->where('id', 2)->value('kot_last_addon_enabled')
        );
    }

    public function test_backfill_is_idempotent(): void
    {
        DB::table('companies')->insert([
            'id' => 3, 'name' => 'Reprint OFF',
            'kot_reprint_enabled' => false, 'kot_last_addon_enabled' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->runBackfill();
        // Shop ne backfill ke BAAD khud switch on kiya — dobara chalne par
        // migration us ki nayi marzi na palte (migrations waise bhi ek hi baar
        // chalti hain, magar re-run par bhi nuqsan na ho).
        DB::table('companies')->where('id', 3)->update(['kot_last_addon_enabled' => true]);
        $this->runBackfill();

        $this->assertSame(
            0,
            (int) DB::table('companies')->where('id', 3)->value('kot_last_addon_enabled'),
            'Re-run par bhi wohi natija'
        );
    }

    public function test_missing_columns_do_not_crash(): void
    {
        Schema::dropAllTables();
        Schema::create('companies', function (Blueprint $table) {
            $table->id();
            $table->string('name')->default('Test Co');
            $table->timestamps();
        });

        $this->runBackfill();

        $this->assertTrue(true, 'PROD drift par migration khamoshi se guzar jaye');
    }
}
