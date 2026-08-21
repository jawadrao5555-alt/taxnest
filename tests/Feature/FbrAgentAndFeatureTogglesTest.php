<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\Company;
use App\Models\User;
use App\Services\PosFeatureService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * FBR POS: Desktop Agent page + the FBR-owned feature switches (Task 1403).
 *
 * Three invariants this file exists to defend:
 *
 *  1. PAIRING NEVER CHANGES SUBMISSION ROUTING. Minting or rotating the agent
 *     key is a PRINTING concern. Before this task the only way an FBR shop
 *     could get a key was the FBR Settings form, which wrote
 *     fbr_connection_mode='fiscal_device' in the same breath — a shop that
 *     just wanted silent printing silently switched how its invoices reach
 *     FBR. The key writer must leave fbr_connection_mode exactly as it was.
 *
 *  2. THE FEATURE ENDPOINT IS THE ONLY WRITER, AND IT IS HONEST. Admin-only;
 *     plan-gated on the way ON but never on the way OFF (a shop that loses a
 *     package must still be able to switch its own features off); it answers
 *     with what actually STUCK after PosFeatureService::normalize(), never
 *     with what the click asked for.
 *
 *  3. STORE SLIP OFF DRAGS ITS DEPENDANTS DOWN. auto_print_kot rides on the
 *     slip; leaving it true while the slip is off means slips start printing
 *     again the moment the feature is re-enabled, with no card on screen that
 *     could have shown or cleared it.
 *
 * Note on kitchen_notes: it is a RESTAURANT_FLAG and every fbrpos plan ships
 * restaurant_enabled=0, so PosFeatureService::forCompany() masks it to false
 * forever. rawFlag() is the read the FBR panel must use — covered below.
 *
 * Run:
 *   env -u DATABASE_URL -u DB_CONNECTION -u PGHOST -u PGPORT -u PGUSER \
 *     -u PGPASSWORD -u PGDATABASE APP_ENV=testing DB_CONNECTION=sqlite \
 *     DB_DATABASE=':memory:' CACHE_STORE=array php vendor/bin/phpunit \
 *     tests/Feature/FbrAgentAndFeatureTogglesTest.php --testdox
 */
class FbrAgentAndFeatureTogglesTest extends TestCase
{
    private Company $company;
    private User $admin;
    private User $cashier;

    protected function setUp(): void
    {
        parent::setUp();
        Schema::dropAllTables();
        // planAllows() memoizes per company id in a static array that survives
        // between tests in the same process — without this, test N would read
        // test N-1's package.
        PosFeatureService::flushGateCaches();
        $this->buildSchema();
        $this->seedShop();
    }

    // ── 1. Key minting must not touch submission routing ────────────────────

    public function test_generating_the_agent_key_does_not_change_fbr_connection_mode(): void
    {
        $this->company->update(['fbr_connection_mode' => 'cloud', 'agent_api_key' => null]);

        $this->actingAs($this->admin, 'fbrpos')
            ->from('/fbr-pos/agent')
            ->post('/fbr-pos/agent/generate')
            ->assertRedirect('/fbr-pos/agent');

        $this->company->refresh();
        $this->assertNotEmpty($this->company->agent_api_key, 'A key should have been minted');
        $this->assertTrue((bool) $this->company->agent_enabled, 'Pairing enables the agent');
        $this->assertSame('cloud', $this->company->fbr_connection_mode,
            'Minting a printing key must NEVER re-route how invoices reach FBR');
    }

    public function test_regenerating_the_agent_key_does_not_change_fbr_connection_mode(): void
    {
        $this->company->update(['fbr_connection_mode' => 'cloud', 'agent_api_key' => 'tnk_original_key_value']);

        $this->actingAs($this->admin, 'fbrpos')
            ->from('/fbr-pos/agent')
            ->post('/fbr-pos/agent/regenerate')
            ->assertRedirect('/fbr-pos/agent');

        $this->company->refresh();
        $this->assertNotEmpty($this->company->agent_api_key);
        $this->assertNotSame('tnk_original_key_value', $this->company->agent_api_key, 'Key should rotate');
        $this->assertSame('cloud', $this->company->fbr_connection_mode);
    }

    public function test_generate_is_a_no_op_when_a_key_already_exists(): void
    {
        $this->company->update(['agent_api_key' => 'tnk_already_paired']);

        $this->actingAs($this->admin, 'fbrpos')
            ->from('/fbr-pos/agent')
            ->post('/fbr-pos/agent/generate')
            ->assertRedirect('/fbr-pos/agent');

        $this->company->refresh();
        $this->assertSame('tnk_already_paired', $this->company->agent_api_key,
            'A second Generate click must not silently re-key a live agent');
    }

    public function test_an_already_paired_shop_can_rotate_even_without_the_offline_package(): void
    {
        // Package downgraded away from offline_enabled, but the agent is live and
        // printing. Stranding it behind a plan wall would kill silent printing.
        $this->setPlanFlags(['offline_enabled' => false]);
        $this->company->update(['agent_api_key' => 'tnk_paired_before_downgrade']);

        $this->actingAs($this->admin, 'fbrpos')
            ->from('/fbr-pos/agent')
            ->post('/fbr-pos/agent/regenerate')
            ->assertRedirect('/fbr-pos/agent')
            ->assertSessionHas('success');

        $this->company->refresh();
        $this->assertNotSame('tnk_paired_before_downgrade', $this->company->agent_api_key);
    }

    public function test_an_unpaired_shop_without_the_offline_package_cannot_mint_a_key(): void
    {
        $this->setPlanFlags(['offline_enabled' => false]);
        $this->company->update(['agent_api_key' => null]);

        $this->actingAs($this->admin, 'fbrpos')
            ->from('/fbr-pos/agent')
            ->post('/fbr-pos/agent/generate')
            ->assertRedirect('/fbr-pos/agent')
            ->assertSessionHas('error');

        $this->company->refresh();
        $this->assertEmpty($this->company->agent_api_key);
    }

    public function test_a_cashier_cannot_mint_or_rotate_the_agent_key(): void
    {
        $this->company->update(['agent_api_key' => null]);

        $this->actingAs($this->cashier, 'fbrpos')->post('/fbr-pos/agent/generate')->assertForbidden();
        $this->actingAs($this->cashier, 'fbrpos')->post('/fbr-pos/agent/regenerate')->assertForbidden();

        $this->company->refresh();
        $this->assertEmpty($this->company->agent_api_key);
    }

    // ── 2. Feature endpoint: gate, plan, honesty ────────────────────────────

    public function test_a_cashier_cannot_flip_an_fbr_feature(): void
    {
        $this->company->update(['kitchen_printer_enabled' => false]);

        $this->actingAs($this->cashier, 'fbrpos')
            ->postJson('/fbr-pos/settings/feature-toggle', ['feature' => 'store_slip', 'enabled' => true])
            ->assertStatus(403);

        $this->company->refresh();
        $this->assertFalse((bool) $this->company->kitchen_printer_enabled);
    }

    public function test_store_slip_can_be_switched_on_and_off(): void
    {
        $this->company->update(['kitchen_printer_enabled' => false]);

        $this->actingAs($this->admin, 'fbrpos')
            ->postJson('/fbr-pos/settings/feature-toggle', ['feature' => 'store_slip', 'enabled' => true])
            ->assertOk()
            ->assertJson(['success' => true, 'enabled' => true]);

        $this->company->refresh();
        $this->assertTrue((bool) $this->company->kitchen_printer_enabled);

        $this->actingAs($this->admin, 'fbrpos')
            ->postJson('/fbr-pos/settings/feature-toggle', ['feature' => 'store_slip', 'enabled' => false])
            ->assertOk()
            ->assertJson(['success' => true, 'enabled' => false]);

        $this->company->refresh();
        $this->assertFalse((bool) $this->company->kitchen_printer_enabled);
    }

    public function test_switching_store_slip_off_also_clears_auto_print(): void
    {
        $this->company->update(['kitchen_printer_enabled' => true, 'auto_print_kot' => true]);

        $this->actingAs($this->admin, 'fbrpos')
            ->postJson('/fbr-pos/settings/feature-toggle', ['feature' => 'store_slip', 'enabled' => false])
            ->assertOk();

        $this->company->refresh();
        $this->assertFalse((bool) $this->company->auto_print_kot,
            'auto_print_kot must not survive the slip being switched off — its card is gone, nobody could clear it');
    }

    public function test_a_package_without_store_slip_cannot_switch_it_on_but_can_switch_it_off(): void
    {
        $this->setPlanFlags(['kot_enabled' => false]);
        $this->company->update(['kitchen_printer_enabled' => true]);

        // ON is blocked...
        $this->actingAs($this->admin, 'fbrpos')
            ->postJson('/fbr-pos/settings/feature-toggle', ['feature' => 'store_slip', 'enabled' => true])
            ->assertStatus(403);

        // ...OFF is always allowed, even on a package that no longer includes it.
        $this->actingAs($this->admin, 'fbrpos')
            ->postJson('/fbr-pos/settings/feature-toggle', ['feature' => 'store_slip', 'enabled' => false])
            ->assertOk();

        $this->company->refresh();
        $this->assertFalse((bool) $this->company->kitchen_printer_enabled);
    }

    public function test_a_package_without_riders_cannot_switch_delivery_on(): void
    {
        $this->setPlanFlags(['riders_enabled' => false]);

        $this->actingAs($this->admin, 'fbrpos')
            ->postJson('/fbr-pos/settings/feature-toggle', ['feature' => 'delivery', 'enabled' => true])
            ->assertStatus(403);

        $this->company->refresh();
        $this->assertFalse((bool) (PosFeatureService::forCompany($this->company)->delivery ?? false));
    }

    public function test_switching_delivery_on_also_switches_on_the_customer_field_it_depends_on(): void
    {
        $this->company->update(['feature_flags' => ['delivery' => false, 'customer_profile' => false]]);

        $this->actingAs($this->admin, 'fbrpos')
            ->postJson('/fbr-pos/settings/feature-toggle', ['feature' => 'delivery', 'enabled' => true])
            ->assertOk()
            ->assertJson(['success' => true, 'enabled' => true]);

        $this->company->refresh();
        $flags = $this->company->feature_flags;
        $this->assertTrue((bool) ($flags['delivery'] ?? false),
            'Delivery must actually stick — without customer_profile, normalize() would drop it straight back off');
        $this->assertTrue((bool) ($flags['customer_profile'] ?? false));
    }

    public function test_the_store_note_flag_round_trips_through_the_raw_read(): void
    {
        // kitchen_notes is a RESTAURANT_FLAG and fbrpos plans have
        // restaurant_enabled=0, so forCompany() masks it — the FBR panel must
        // read it raw or the switch would look permanently off.
        // The note rides ON the slip, so the slip has to exist first.
        $this->company->update(['kitchen_printer_enabled' => true]);

        $this->actingAs($this->admin, 'fbrpos')
            ->postJson('/fbr-pos/settings/feature-toggle', ['feature' => 'store_notes', 'enabled' => true])
            ->assertOk()
            ->assertJson(['success' => true, 'enabled' => true]);

        $this->company->refresh();
        $this->assertTrue(PosFeatureService::rawFlag($this->company, 'kitchen_notes'),
            'rawFlag must see the stored value');

        $this->actingAs($this->admin, 'fbrpos')
            ->postJson('/fbr-pos/settings/feature-toggle', ['feature' => 'store_notes', 'enabled' => false])
            ->assertOk()
            ->assertJson(['enabled' => false]);

        $this->company->refresh();
        $this->assertFalse(PosFeatureService::rawFlag($this->company, 'kitchen_notes'));
    }

    public function test_switching_store_slip_off_also_switches_off_the_per_item_note(): void
    {
        $this->company->update(['kitchen_printer_enabled' => true]);
        $this->actingAs($this->admin, 'fbrpos')
            ->postJson('/fbr-pos/settings/feature-toggle', ['feature' => 'store_notes', 'enabled' => true])
            ->assertOk();

        $this->actingAs($this->admin, 'fbrpos')
            ->postJson('/fbr-pos/settings/feature-toggle', ['feature' => 'store_slip', 'enabled' => false])
            ->assertOk();

        $this->company->refresh();
        $this->assertFalse(PosFeatureService::rawFlag($this->company, 'kitchen_notes'),
            'The note only exists to be printed on the slip — killing the slip must kill it in the DB too, '
            . 'not just on screen, or it springs back to ON on the next page load');
    }

    public function test_the_per_item_note_cannot_be_switched_on_without_the_store_slip(): void
    {
        $this->company->update(['kitchen_printer_enabled' => false]);

        $this->actingAs($this->admin, 'fbrpos')
            ->postJson('/fbr-pos/settings/feature-toggle', ['feature' => 'store_notes', 'enabled' => true])
            ->assertStatus(422);

        $this->company->refresh();
        $this->assertFalse(PosFeatureService::rawFlag($this->company, 'kitchen_notes'),
            'Saying "saved" for a note that has no slip to print on would be a lie');
    }

    public function test_an_unknown_feature_name_is_rejected(): void
    {
        $this->actingAs($this->admin, 'fbrpos')
            ->postJson('/fbr-pos/settings/feature-toggle', ['feature' => 'restaurant', 'enabled' => true])
            ->assertStatus(422);
    }

    // ── helpers ─────────────────────────────────────────────────────────────

    /** Overwrite one or more plan feature columns on the shop's active plan. */
    private function setPlanFlags(array $flags): void
    {
        \DB::table('pricing_plans')->where('id', 1)->update($flags);
        PosFeatureService::flushGateCaches();
    }

    private function seedShop(): void
    {
        $this->company = Company::create([
            'name'                 => 'FBR Agent Toggle Shop',
            'product_type'         => 'fbrpos',
            'status'               => 'active',
            'company_status'       => 'active',
            'fbr_pos_enabled'      => true,
            'fbr_connection_mode'  => 'cloud',
            'feature_flags'        => [],
        ]);

        \DB::table('pricing_plans')->insert([
            'id'                  => 1,
            'name'                => 'FBR Pro',
            'product_type'        => 'fbrpos',
            'is_trial'            => 0,
            'restaurant_enabled'  => 0,
            'kot_enabled'         => 1,
            'riders_enabled'      => 1,
            'offline_enabled'     => 1,
            'created_at'          => now(),
            'updated_at'          => now(),
        ]);

        \DB::table('subscriptions')->insert([
            'id'              => 1,
            'company_id'      => $this->company->id,
            'pricing_plan_id' => 1,
            'active'          => 1,
            'ends_at'         => now()->addYear(),
            'created_at'      => now(),
            'updated_at'      => now(),
        ]);

        $this->admin = User::create([
            'name'       => 'FBR Admin',
            'email'      => 'admin@fbragent.test',
            'password'   => bcrypt('secret'),
            'company_id' => $this->company->id,
            'role'       => 'company_admin',
            'pos_role'   => 'pos_admin',
            'is_active'  => true,
        ]);

        $this->cashier = User::create([
            'name'       => 'FBR Cashier',
            'email'      => 'cashier@fbragent.test',
            'password'   => bcrypt('secret'),
            'company_id' => $this->company->id,
            'role'       => 'pos_user',
            'pos_role'   => 'pos_cashier',
            'is_active'  => true,
        ]);
    }

    private function buildSchema(): void
    {
        Schema::create('companies', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->string('product_type')->default('fbrpos');
            $t->string('status')->default('active');
            $t->string('company_status')->default('active');
            $t->boolean('is_internal_account')->default(false);
            $t->boolean('fbr_pos_enabled')->default(false);
            $t->string('fbr_connection_mode')->nullable();
            $t->string('fbr_pos_environment')->nullable();
            // Desktop Agent pairing
            $t->string('agent_api_key')->nullable();
            $t->boolean('agent_enabled')->default(false);
            $t->timestamp('agent_last_seen')->nullable();
            $t->string('agent_version')->nullable();
            // Feature switches this endpoint owns
            $t->boolean('kitchen_printer_enabled')->default(false);
            $t->boolean('auto_print_kot')->default(false);
            $t->text('feature_flags')->nullable();
            $t->boolean('inventory_enabled')->default(false);
            $t->boolean('restaurant_mode')->default(false);
            $t->softDeletes();
            $t->timestamps();
        });

        Schema::create('pricing_plans', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->string('product_type')->default('fbrpos');
            $t->boolean('is_trial')->default(false);
            $t->boolean('restaurant_enabled')->default(false);
            $t->boolean('kot_enabled')->default(false);
            $t->boolean('riders_enabled')->default(false);
            $t->boolean('offline_enabled')->default(false);
            $t->boolean('inventory_enabled')->default(false);
            $t->integer('invoice_limit')->nullable();
            $t->integer('user_limit')->nullable();
            $t->timestamps();
        });

        Schema::create('subscriptions', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('company_id');
            $t->unsignedBigInteger('pricing_plan_id')->nullable();
            $t->boolean('active')->default(true);
            $t->timestamp('ends_at')->nullable();
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
            $t->string('language')->nullable();
            $t->rememberToken();
            $t->timestamps();
        });
    }
}
