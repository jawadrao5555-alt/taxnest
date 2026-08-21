<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\PosTransaction;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Archive Portal must RENDER for the one role that can only ever open it
 * (Task #1339).
 *
 * An archive_viewer account signed in fine and was redirected to /pos/archive,
 * but the page itself 500'd:
 *   "Call to a member function format() on string
 *    (View: resources/views/pos/archive/index.blade.php)"
 *
 * Cause: pos_transactions.archived_at was NOT in PosTransaction::$casts, so
 * the raw DB string reached `$b->archived_at?->format(...)` — and `?->` only
 * guards NULL, never a string. The list, the detail page and the CSV export
 * all format the same value, so the whole portal was dead for that role.
 *
 * Locked here:
 *   1. archived_at is a Carbon instance on a row read back from the DB.
 *   2. /pos/archive renders 200 for an archive_viewer and prints the date.
 *   3. /pos/archive/{id} renders 200 and prints the same date.
 *   4. /pos/archive/export streams CSV with the archived timestamp.
 *
 * Run:
 *   env -u DATABASE_URL -u DB_CONNECTION -u PGHOST -u PGPORT -u PGUSER \
 *     -u PGPASSWORD -u PGDATABASE APP_ENV=testing DB_CONNECTION=sqlite \
 *     DB_DATABASE=':memory:' CACHE_STORE=array php vendor/bin/phpunit \
 *     tests/Feature/PosArchivePortalRenderTest.php --testdox
 */
class PosArchivePortalRenderTest extends TestCase
{
    /** Raw DB string exactly as MySQL/sqlite hand a timestamp column back. */
    private const ARCHIVED_AT = '2026-08-14 21:45:30';

    private Company $company;
    private User $viewer;
    private User $cashier;
    private int $billId;

    protected function setUp(): void
    {
        parent::setUp();

        User::flushScopeColumnCache();
        Schema::dropAllTables();
        $this->buildSchema();
        $this->seedShop();
    }

    // ── 1. Model cast ────────────────────────────────────────────────────────

    public function test_archived_at_is_cast_to_a_date_on_the_model(): void
    {
        $bill = PosTransaction::withoutGlobalScope('hide_archived')->findOrFail($this->billId);

        $this->assertInstanceOf(
            \Illuminate\Support\Carbon::class,
            $bill->archived_at,
            'archived_at must be cast to a date — a raw string kills ->format() in the archive views'
        );
        $this->assertSame('14 Aug 2026', $bill->archived_at->format('d M Y'));
    }

    // ── 2. Archive list ──────────────────────────────────────────────────────

    public function test_archive_viewer_can_open_the_archive_list(): void
    {
        $response = $this->actingAs($this->viewer, 'pos')->get('/pos/archive');

        $response->assertOk();
        $response->assertSee('LOCAL-0001');
        $response->assertSee('14 Aug 2026');
    }

    // ── 3. Detail page ───────────────────────────────────────────────────────

    public function test_archive_viewer_can_open_a_bill_detail(): void
    {
        $response = $this->actingAs($this->viewer, 'pos')->get('/pos/archive/' . $this->billId);

        $response->assertOk();
        $response->assertSee('LOCAL-0001');
        $response->assertSee('14 Aug 2026');
        $response->assertSee('Chai');
    }

    // ── 4. CSV export ────────────────────────────────────────────────────────

    public function test_csv_export_still_formats_the_archived_timestamp(): void
    {
        $response = $this->actingAs($this->viewer, 'pos')->get('/pos/archive/export');

        $response->assertOk();
        $csv = $response->streamedContent();

        $this->assertStringContainsString('LOCAL-0001', $csv);
        $this->assertStringContainsString(self::ARCHIVED_AT, $csv);
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    private function seedShop(): void
    {
        $this->company = Company::create([
            'name'                => 'Archive Portal Test Shop',
            'product_type'        => 'pos',
            'status'              => 'active',
            'company_status'      => 'active',
            'is_internal_account' => false,
        ]);

        $this->cashier = User::create([
            'name'       => 'Archive Cashier',
            'email'      => 'cashier@archive.test',
            'password'   => bcrypt('secret'),
            'company_id' => $this->company->id,
            'role'       => 'pos_user',
            'pos_role'   => 'pos_cashier',
            'is_active'  => true,
        ]);

        $this->viewer = User::create([
            'name'       => 'Archive Viewer',
            'email'      => 'viewer@archive.test',
            'password'   => bcrypt('secret'),
            'company_id' => $this->company->id,
            'role'       => 'pos_user',
            'pos_role'   => 'archive_viewer',
            'is_active'  => true,
        ]);

        // Inserted through the query builder on purpose: the row must carry a
        // plain string timestamp, exactly like a bill archived by day-close and
        // read back later.
        $this->billId = DB::table('pos_transactions')->insertGetId([
            'company_id'            => $this->company->id,
            'invoice_number'        => 'LOCAL-0001',
            // Archive portal only lists the PRA stream (invoice_mode pra/NULL)
            // with an unsubmitted pra_status — the day-close "wash" shape.
            'invoice_mode'          => 'pra',
            'customer_name'         => 'Walk-in',
            'customer_phone'        => '03001234567',
            'subtotal'              => 500.00,
            'discount_amount'       => 0.00,
            'tax_amount'            => 0.00,
            'total_amount'          => 500.00,
            'payment_method'        => 'cash',
            'status'                => 'completed',
            'pra_status'            => 'local',
            'created_by'            => $this->cashier->id,
            'business_date'         => '2026-08-14',
            'is_archived'           => true,
            'archived_at'           => self::ARCHIVED_AT,
            'archived_by_report_id' => null,
            'created_at'            => '2026-08-14 19:10:00',
            'updated_at'            => self::ARCHIVED_AT,
        ]);

        DB::table('pos_transaction_items')->insert([
            'transaction_id' => $this->billId,
            'item_name'      => 'Chai',
            'quantity'       => 2,
            'unit_price'     => 250.00,
            'subtotal'       => 500.00,
            'created_at'     => '2026-08-14 19:10:00',
            'updated_at'     => '2026-08-14 19:10:00',
        ]);

        DB::table('pos_day_close_reports')->insert([
            'company_id'    => $this->company->id,
            'report_number' => 'Z-0001',
            'report_date'   => '2026-08-14',
            'created_at'    => self::ARCHIVED_AT,
            'updated_at'    => self::ARCHIVED_AT,
        ]);
    }

    private function buildSchema(): void
    {
        Schema::create('companies', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->string('product_type')->default('pos');
            $t->string('status')->default('active');
            $t->string('company_status')->default('active');
            $t->boolean('is_internal_account')->default(false);
            $t->softDeletes();
            $t->timestamps();
        });

        Schema::create('branches', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('company_id');
            $t->string('name')->nullable();
            $t->boolean('is_head_office')->default(false);
            $t->boolean('is_active')->default(true);
            $t->timestamps();
        });

        Schema::create('users', function (Blueprint $t) {
            $t->id();
            $t->string('name')->nullable();
            $t->string('email')->nullable()->unique();
            $t->string('password')->nullable();
            $t->unsignedBigInteger('company_id')->nullable();
            $t->string('role')->nullable();
            $t->string('pos_role')->nullable();
            $t->boolean('is_active')->default(true);
            $t->text('pos_custom_access')->nullable();
            $t->unsignedBigInteger('default_branch_id')->nullable();
            $t->rememberToken();
            $t->timestamps();
        });

        Schema::create('pos_transactions', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('company_id');
            $t->unsignedBigInteger('branch_id')->nullable();
            $t->string('invoice_number');
            $t->string('invoice_mode')->nullable();
            $t->string('customer_name')->nullable();
            $t->string('customer_phone')->nullable();
            $t->decimal('subtotal', 15, 2)->default(0);
            $t->decimal('discount_amount', 15, 2)->default(0);
            $t->decimal('tax_amount', 15, 2)->default(0);
            $t->decimal('total_amount', 15, 2)->default(0);
            $t->string('payment_method')->default('cash');
            $t->string('status')->default('completed');
            $t->string('pra_status')->nullable();
            $t->unsignedBigInteger('created_by')->nullable();
            $t->date('business_date')->nullable();
            $t->boolean('is_archived')->default(false);
            $t->timestamp('archived_at')->nullable();
            $t->unsignedBigInteger('archived_by_report_id')->nullable();
            $t->timestamps();
        });

        Schema::create('pos_transaction_items', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('transaction_id');
            $t->string('item_name')->nullable();
            $t->decimal('quantity', 12, 3)->default(0);
            $t->decimal('unit_price', 15, 2)->default(0);
            $t->decimal('subtotal', 15, 2)->default(0);
            $t->timestamps();
        });

        Schema::create('pos_day_close_reports', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('company_id');
            $t->string('report_number')->nullable();
            $t->date('report_date')->nullable();
            $t->timestamps();
        });
    }
}
