<?php
namespace Tests\Feature;
use App\Services\PlanLimitService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;
/** Leaves a real legacy capability answer for the next rebuilt-schema test. */
class PlanLimitLegacySchemaProbeTest extends TestCase
{
    public function test_legacy_invoice_schema_without_submission_stamp_is_supported():void
    {
        Schema::dropAllTables();
        Schema::create('invoices',function(Blueprint $table){$table->id();$table->unsignedBigInteger('company_id');$table->string('fbr_status')->nullable();$table->timestamps();});
        self::assertSame(0,PlanLimitService::countedInvoices(1));
        self::assertFalse(Schema::hasColumn('invoices','submitted_at'));
        self::assertNull(\App\Services\ProspectiveQuotaRetention::policy(1));
    }
}
