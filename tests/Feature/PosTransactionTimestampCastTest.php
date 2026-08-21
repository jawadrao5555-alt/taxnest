<?php

namespace Tests\Feature;

use App\Models\PosTransaction;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Every hand-added timestamp column on PosTransaction must be a real date on a
 * row read back from the DB (Task 1339 follow-up).
 *
 * The Archive portal 500'd because archived_at was NOT in $casts, so the raw
 * DB string reached ->format() and the null-safe arrow (?->) did not save it
 * ("Call to a member function format() on string"). The same model still had
 * several un-cast timestamp columns (rider_assigned_at, delivered_at,
 * returned_at, rider_settled_at, receipt_printed_at, share_token_created_at,
 * prepaid_converted_at) — the next screen or report that formats one of them
 * would die exactly the same way. This locks the cast so a raw string can
 * never reach a ->format() again.
 *
 * business_date is DELIBERATELY excluded: reports compare it as a plain DATE
 * string, so it must stay a bare string and never become a Carbon value.
 *
 * Run:
 *   env -u DATABASE_URL -u DB_CONNECTION -u PGHOST -u PGPORT -u PGUSER \
 *     -u PGPASSWORD -u PGDATABASE APP_ENV=testing DB_CONNECTION=sqlite \
 *     DB_DATABASE=':memory:' CACHE_STORE=array php vendor/bin/phpunit \
 *     tests/Feature/PosTransactionTimestampCastTest.php --testdox
 */
class PosTransactionTimestampCastTest extends TestCase
{
    /** Raw DB string exactly as MySQL/sqlite hand a timestamp column back. */
    private const TS = '2026-08-14 21:45:30';

    /** Every stamp that MUST be a Carbon instance after a DB round-trip. */
    private const DATETIME_COLUMNS = [
        'rider_assigned_at',
        'delivered_at',
        'returned_at',
        'rider_settled_at',
        'receipt_printed_at',
        'share_token_created_at',
        'prepaid_converted_at',
        'archived_at',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        Schema::dropAllTables();
        $this->buildSchema();
    }

    public function test_all_delivery_and_receipt_stamps_are_cast_to_dates(): void
    {
        // Inserted through the query builder on purpose: the row must carry a
        // plain string timestamp, exactly like a bill written by day-close /
        // a rider settle and read back later.
        $row = ['company_id' => 1, 'invoice_number' => 'INV-1'];
        foreach (self::DATETIME_COLUMNS as $col) {
            $row[$col] = self::TS;
        }
        $row['business_date'] = '2026-08-14';
        $row['created_at'] = self::TS;
        $row['updated_at'] = self::TS;
        $id = DB::table('pos_transactions')->insertGetId($row);

        $bill = PosTransaction::withoutGlobalScope('hide_archived')->findOrFail($id);

        foreach (self::DATETIME_COLUMNS as $col) {
            $this->assertInstanceOf(
                \Illuminate\Support\Carbon::class,
                $bill->{$col},
                "{$col} must be cast to a date — a raw string kills ->format() the way archived_at did"
            );
            // The null-safe ->format() call that lives all over the views/CSV
            // must now be safe on every one of these columns.
            $this->assertSame('14 Aug 2026', $bill->{$col}?->format('d M Y'));
        }
    }

    public function test_business_date_stays_a_plain_string(): void
    {
        // Reports do `where('business_date', '=', $ymd)` and `(string) $b->business_date`
        // in dozens of places; casting it to a Carbon/whereDate value would
        // silently change those comparisons.
        $id = DB::table('pos_transactions')->insertGetId([
            'company_id'    => 1,
            'invoice_number' => 'INV-2',
            'business_date' => '2026-08-14',
            'created_at'    => self::TS,
            'updated_at'    => self::TS,
        ]);

        $bill = PosTransaction::withoutGlobalScope('hide_archived')->findOrFail($id);

        $this->assertIsString($bill->business_date, 'business_date must NOT be cast to a date');
        $this->assertSame('2026-08-14', $bill->business_date);
    }

    private function buildSchema(): void
    {
        Schema::create('pos_transactions', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('company_id');
            $t->string('invoice_number');
            $t->string('invoice_mode')->nullable();
            $t->string('pra_status')->nullable();
            $t->string('pra_invoice_number')->nullable();
            $t->boolean('is_archived')->default(false);
            $t->string('business_date')->nullable();
            $t->timestamp('rider_assigned_at')->nullable();
            $t->timestamp('delivered_at')->nullable();
            $t->timestamp('returned_at')->nullable();
            $t->timestamp('rider_settled_at')->nullable();
            $t->timestamp('receipt_printed_at')->nullable();
            $t->timestamp('share_token_created_at')->nullable();
            $t->timestamp('prepaid_converted_at')->nullable();
            $t->timestamp('archived_at')->nullable();
            $t->timestamps();
        });
    }
}
