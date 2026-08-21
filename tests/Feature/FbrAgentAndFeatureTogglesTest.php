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

    // ── 4. Store Slip OFF is a real server-side gate, not just a hidden card ──
    //
    // The switch is only honest if the SERVER refuses. A sale screen opened
    // before the owner switched Store Slip off keeps its old JS state, and the
    // ticket URLs are plain authenticated GETs anyone can retype — so all three
    // slip paths (pre-pay ticket, post-pay reprint, silent print job) must check
    // the shop's own switch, not just the package.

    public function test_pre_pay_store_slip_ticket_is_refused_once_the_switch_is_off(): void
    {
        $this->company->update(['kitchen_printer_enabled' => false]);
        $heldId = \DB::table('fbr_pos_held_sales')->insertGetId([
            'company_id' => $this->company->id,
            'user_id'    => $this->admin->id,
            'hold_name'  => 'Table 4',
            'cart_data'  => json_encode(['items' => [['item_name' => 'Chai', 'quantity' => 2]]]),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->actingAs($this->admin, 'fbrpos')
            ->get("/fbr-pos/held/{$heldId}/kitchen-ticket")
            ->assertRedirect(route('fbrpos.customize'));

        // ...and back on once the shop switches it on again.
        $this->company->update(['kitchen_printer_enabled' => true]);
        $this->actingAs($this->admin, 'fbrpos')
            ->get("/fbr-pos/held/{$heldId}/kitchen-ticket")
            ->assertOk();
    }

    public function test_post_pay_store_slip_reprint_is_refused_once_the_switch_is_off(): void
    {
        $this->company->update(['kitchen_printer_enabled' => false]);
        $txnId = \DB::table('fbr_pos_transactions')->insertGetId([
            'company_id'     => $this->company->id,
            'user_id'        => $this->admin->id,
            'invoice_number' => 'SLIP-GATE-1',
            'total_amount'   => 250,
            'created_at'     => now(), 'updated_at' => now(),
        ]);

        $this->actingAs($this->admin, 'fbrpos')
            ->get("/fbr-pos/transaction/{$txnId}/kot-reprint")
            ->assertRedirect(route('fbrpos.customize'));

        $this->company->update(['kitchen_printer_enabled' => true]);
        $this->actingAs($this->admin, 'fbrpos')
            ->get("/fbr-pos/transaction/{$txnId}/kot-reprint")
            ->assertOk();
    }

    /**
     * The stale-client case the reviewer called out: the sale screen was opened
     * while Store Slip was ON, the owner switched it off in another tab, and the
     * screen still fires its silent-print call. The queue must refuse it — while
     * the BILL job on the same endpoint keeps working.
     */
    public function test_a_stale_sale_screen_cannot_queue_a_store_slip_after_the_switch_is_off(): void
    {
        $this->company->update([
            'kitchen_printer_enabled' => false,
            'agent_enabled'           => true,
            'agent_last_seen'         => now(),
            'pos_printer_settings'    => [
                'silent_print_enabled' => true,
                'receipt_printer'      => 'EPSON-80',
                'kot_printer'          => 'KITCHEN-80',
            ],
        ]);
        $txnId = \DB::table('fbr_pos_transactions')->insertGetId([
            'company_id'     => $this->company->id,
            'invoice_number' => 'SLIP-GATE-2',
            'total_amount'   => 100,
            'created_at'     => now(), 'updated_at' => now(),
        ]);
        $heldId = \DB::table('fbr_pos_held_sales')->insertGetId([
            'company_id' => $this->company->id,
            'cart_data'  => json_encode(['items' => []]),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        // Pre-pay slip from a held sale — refused.
        $this->actingAs($this->admin, 'fbrpos')
            ->postJson('/fbr-pos/api/print-jobs', ['type' => 'fbr_kot', 'restaurant_order_id' => $heldId])
            ->assertStatus(409)
            ->assertJson(['success' => false, 'reason' => 'store_slip_off']);

        // Post-pay slip reprint — refused.
        $this->actingAs($this->admin, 'fbrpos')
            ->postJson('/fbr-pos/api/print-jobs', ['type' => 'fbr_kot', 'transaction_id' => $txnId])
            ->assertStatus(409)
            ->assertJson(['success' => false, 'reason' => 'store_slip_off']);

        $this->assertSame(0, \DB::table('pos_print_jobs')->where('type', 'fbr_kot')->count(),
            'A switched-off shop must never get a store slip on the print queue');

        // The BILL must be unaffected — switching the slip off is not a
        // printing-wide kill switch.
        $this->actingAs($this->admin, 'fbrpos')
            ->postJson('/fbr-pos/api/print-jobs', ['type' => 'fbr_bill', 'transaction_id' => $txnId])
            ->assertOk()
            ->assertJson(['success' => true]);
        $this->assertSame(1, \DB::table('pos_print_jobs')->where('type', 'fbr_bill')->count());
    }

    public function test_a_package_without_store_slip_cannot_queue_one_even_with_the_column_on(): void
    {
        \DB::table('pricing_plans')->where('id', 1)->update(['kot_enabled' => 0]);
        PosFeatureService::flushGateCaches();
        $this->company->update([
            'kitchen_printer_enabled' => true,
            'agent_enabled'           => true,
            'agent_last_seen'         => now(),
            'pos_printer_settings'    => [
                'silent_print_enabled' => true,
                'receipt_printer'      => 'EPSON-80',
                'kot_printer'          => 'KITCHEN-80',
            ],
        ]);
        $heldId = \DB::table('fbr_pos_held_sales')->insertGetId([
            'company_id' => $this->company->id,
            'cart_data'  => json_encode(['items' => []]),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->actingAs($this->admin, 'fbrpos')
            ->postJson('/fbr-pos/api/print-jobs', ['type' => 'fbr_kot', 'restaurant_order_id' => $heldId])
            ->assertStatus(409)
            ->assertJson(['success' => false, 'reason' => 'store_slip_off']);
    }

    // ── 5. The per-item Store note obeys the same switch, on BOTH sides ──────
    //
    // Turning the note off must actually stop notes. The sale screen keeps its
    // old state after a toggle or a downgrade, so neither a posted payload nor
    // an already-stored note can be trusted — the switch decides at write time
    // AND again at print time.

    /** Helper: minimal cart for a note-carrying held sale. */
    private function heldWithNote(): int
    {
        return \DB::table('fbr_pos_held_sales')->insertGetId([
            'company_id' => $this->company->id,
            'cart_data'  => json_encode(['items' => [
                ['item_name' => 'Biryani', 'quantity' => 1, 'special_notes' => 'mirch tez'],
            ]]),
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** Helper: a completed bill whose line already carries a note. */
    private function txnWithNote(): int
    {
        $txnId = \DB::table('fbr_pos_transactions')->insertGetId([
            'company_id'     => $this->company->id,
            'invoice_number' => 'NOTE-GATE-1',
            'total_amount'   => 500,
            'created_at'     => now(), 'updated_at' => now(),
        ]);
        \DB::table('fbr_pos_transaction_items')->insert([
            'transaction_id' => $txnId,
            'item_name'      => 'Biryani',
            'quantity'       => 1,
            'special_notes'  => 'mirch tez',
            'created_at'     => now(), 'updated_at' => now(),
        ]);
        return $txnId;
    }

    public function test_a_held_cart_note_stops_printing_once_the_note_feature_is_off(): void
    {
        $this->company->update([
            'kitchen_printer_enabled' => true,
            'feature_flags'           => ['kitchen_notes' => true],
        ]);
        $heldId = $this->heldWithNote();

        $this->actingAs($this->admin, 'fbrpos')
            ->get("/fbr-pos/held/{$heldId}/kitchen-ticket")
            ->assertOk()
            ->assertSee('mirch tez');

        // Owner switches the note off — the SAME held cart must print clean.
        $this->company->update(['feature_flags' => ['kitchen_notes' => false]]);
        $this->actingAs($this->admin, 'fbrpos')
            ->get("/fbr-pos/held/{$heldId}/kitchen-ticket")
            ->assertOk()
            ->assertDontSee('mirch tez');
    }

    public function test_a_stored_bill_note_stops_reprinting_once_the_note_feature_is_off(): void
    {
        $this->company->update([
            'kitchen_printer_enabled' => true,
            'feature_flags'           => ['kitchen_notes' => true],
        ]);
        $txnId = $this->txnWithNote();

        $this->actingAs($this->admin, 'fbrpos')
            ->get("/fbr-pos/transaction/{$txnId}/kot-reprint")
            ->assertOk()
            ->assertSee('mirch tez');

        $this->company->update(['feature_flags' => ['kitchen_notes' => false]]);
        $this->actingAs($this->admin, 'fbrpos')
            ->get("/fbr-pos/transaction/{$txnId}/kot-reprint")
            ->assertOk()
            ->assertDontSee('mirch tez');
    }

    public function test_a_downgraded_shop_stops_printing_notes_even_though_they_are_stored(): void
    {
        $this->company->update([
            'kitchen_printer_enabled' => true,
            'feature_flags'           => ['kitchen_notes' => true],
        ]);
        $txnId = $this->txnWithNote();

        // Package loses store slips entirely — the slip itself is gone, so the
        // note must be too. (The slip gate answers first with a redirect.)
        \DB::table('pricing_plans')->where('id', 1)->update(['kot_enabled' => 0]);
        PosFeatureService::flushGateCaches();

        $this->actingAs($this->admin, 'fbrpos')
            ->get("/fbr-pos/transaction/{$txnId}/kot-reprint")
            ->assertRedirect(route('fbrpos.billing'));
    }

    /**
     * A DEAL is one cart line but many transaction rows. The note must survive
     * onto the persisted rows — exactly once, not once per component — or a note
     * the cashier saw on the pre-pay ticket disappears from the reprint.
     */
    public function test_a_deal_line_note_survives_onto_the_reprint_exactly_once(): void
    {
        $this->company->update([
            'kitchen_printer_enabled' => true,
            'feature_flags'           => ['kitchen_notes' => true],
        ]);

        // Three component rows sharing one deal_group, as store() writes them:
        // the note rides on the FIRST row only.
        $txnId = \DB::table('fbr_pos_transactions')->insertGetId([
            'company_id'     => $this->company->id,
            'invoice_number' => 'DEAL-NOTE-1',
            'total_amount'   => 900,
            'created_at'     => now(), 'updated_at' => now(),
        ]);
        foreach ([['Zinger Burger', 'mirch tez'], ['Fries', null], ['Drink', null]] as [$name, $note]) {
            \DB::table('fbr_pos_transaction_items')->insert([
                'transaction_id' => $txnId,
                'item_name'      => $name,
                'quantity'       => 1,
                'special_notes'  => $note,
                'created_at'     => now(), 'updated_at' => now(),
            ]);
        }

        $html = $this->actingAs($this->admin, 'fbrpos')
            ->get("/fbr-pos/transaction/{$txnId}/kot-reprint")
            ->assertOk()
            ->assertSee('Zinger Burger')
            ->assertSee('Fries')
            ->getContent();

        $this->assertSame(1, substr_count($html, 'mirch tez'),
            'A deal note must print once for the combo, not once per component');

        // ...and the same switch silences it.
        $this->company->update(['feature_flags' => ['kitchen_notes' => false]]);
        $this->actingAs($this->admin, 'fbrpos')
            ->get("/fbr-pos/transaction/{$txnId}/kot-reprint")
            ->assertOk()
            ->assertSee('Zinger Burger')
            ->assertDontSee('mirch tez');
    }

    // ── 6. The SILENT (Desktop Agent) path obeys the same switches ───────────
    //
    // The agent fetches a queued job by agent key — no session, no fbrpos user —
    // so an auth-based gate silently passes it. A slip queued a second before the
    // owner switched Store Slip off must still not come out of the printer, and
    // the note rules apply to what the agent renders too.

    /** Helper: queue a store-slip job and fetch it the way the agent does. */
    private function agentFetch(int $jobId)
    {
        return $this->withHeaders(['X-Agent-Key' => 'agent-key-1403'])
            ->get("/api/agent/print-jobs/{$jobId}/content");
    }

    private function queueSlipJob(array $attrs): int
    {
        $this->company->update(['agent_api_key' => 'agent-key-1403', 'agent_enabled' => true]);
        return \DB::table('pos_print_jobs')->insertGetId(array_merge([
            'company_id'     => $this->company->id,
            'type'           => 'fbr_kot',
            'target_printer' => 'KITCHEN-80',
            'status'         => 'printing',
            'created_at'     => now(), 'updated_at' => now(),
        ], $attrs));
    }

    public function test_a_slip_queued_before_the_switch_went_off_is_not_printed_by_the_agent(): void
    {
        $this->company->update(['kitchen_printer_enabled' => true]);
        $heldId = $this->heldWithNote();
        $jobId  = $this->queueSlipJob(['restaurant_order_id' => $heldId]);

        // Still on → the agent gets the slip.
        $this->agentFetch($jobId)->assertOk()->assertSee('Biryani');

        // Owner switches Store Slip off AFTER the job was queued → nothing to print.
        $this->company->update(['kitchen_printer_enabled' => false]);
        $this->agentFetch($jobId)->assertNoContent(204);

        // Same for a package that loses store slips entirely.
        $this->company->update(['kitchen_printer_enabled' => true]);
        \DB::table('pricing_plans')->where('id', 1)->update(['kot_enabled' => 0]);
        PosFeatureService::flushGateCaches();
        $this->agentFetch($jobId)->assertNoContent(204);
    }

    public function test_the_agent_prints_notes_only_while_the_note_feature_is_on(): void
    {
        $this->company->update([
            'kitchen_printer_enabled' => true,
            'feature_flags'           => ['kitchen_notes' => true],
        ]);

        // Pre-pay slip from a held cart.
        $heldJob = $this->queueSlipJob(['restaurant_order_id' => $this->heldWithNote()]);
        $this->agentFetch($heldJob)->assertOk()->assertSee('mirch tez');

        // Post-pay reprint from a completed bill — this used to be hardcoded to
        // print no note at all.
        $txnJob = $this->queueSlipJob(['transaction_id' => $this->txnWithNote()]);
        $this->agentFetch($txnJob)->assertOk()->assertSee('mirch tez');

        // Note switched off → both silent paths print clean, slip still prints.
        $this->company->update(['feature_flags' => ['kitchen_notes' => false]]);
        $this->agentFetch($heldJob)->assertOk()->assertSee('Biryani')->assertDontSee('mirch tez');
        $this->agentFetch($txnJob)->assertOk()->assertSee('Biryani')->assertDontSee('mirch tez');
    }

    // ── 7. The downgraded shop's card must still HAVE a working switch ───────
    //
    // The endpoint accepts OFF from a shop whose package no longer covers the
    // feature — but that promise is worthless if the only control on screen is
    // a disabled toggle. These render the real page and evaluate the real
    // :disabled expression, because that is where the promise can quietly die.

    /**
     * Evaluate a toggle's rendered :disabled expression against the initial
     * x-data the same page shipped. Only booleans, !, &&, || and parens can
     * survive the whitelist, so this is the Alpine truth, not a re-implementation.
     */
    private function toggleDisabled(string $html, string $feature): bool
    {
        $q = preg_quote($feature, '/');
        $this->assertSame(1, preg_match('/<button[^>]*featSave\(\''.$q.'\'[^>]*>/', $html, $btn),
            "No toggle button rendered for '{$feature}' — a downgraded shop needs a switch, not a padlock");
        $this->assertSame(1, preg_match('/:disabled="([^"]+)"/', $btn[0], $d));

        $resolved = preg_replace_callback('/[A-Za-z_][A-Za-z0-9_]*/', function ($t) use ($html, $feature) {
            $this->assertSame(1, preg_match('/\b'.$t[0].':\s*(true|false)\b/', $html, $v),
                "x-data ships no initial value for '{$t[0]}' used by the {$feature} toggle");
            return $v[1];
        }, $d[1]);
        $this->assertMatchesRegularExpression('/^[a-z()!&| ]+$/', $resolved, 'Unexpected token in :disabled');

        return (bool) eval("return {$resolved};");
    }

    public function test_a_downgraded_shop_can_still_switch_its_locked_features_off(): void
    {
        // Package taken away while all three features are still running.
        $this->company->update([
            'kitchen_printer_enabled' => true,
            'feature_flags'           => ['delivery' => true, 'kitchen_notes' => true, 'customer_profile' => true],
        ]);
        $this->setPlanFlags(['kot_enabled' => false, 'riders_enabled' => false]);

        $html = $this->actingAs($this->admin, 'fbrpos')->get('/fbr-pos/customize')
            ->assertOk()->getContent();

        foreach (['store_slip', 'delivery', 'store_notes'] as $feature) {
            $this->assertFalse($this->toggleDisabled($html, $feature),
                "A locked-but-still-ON '{$feature}' must stay clickable so the owner can turn it off");
        }

        // And the click actually lands: OFF is accepted for each of them.
        foreach ([['store_slip', 'storeSlipOn'], ['delivery', 'deliveryOn'], ['store_notes', 'storeNoteOn']] as [$feature, $_]) {
            $this->company->update([
                'kitchen_printer_enabled' => true,
                'feature_flags'           => ['delivery' => true, 'kitchen_notes' => true, 'customer_profile' => true],
            ]);
            $this->actingAs($this->admin, 'fbrpos')
                ->postJson('/fbr-pos/settings/feature-toggle', ['feature' => $feature, 'enabled' => false])
                ->assertOk()->assertJson(['success' => true, 'enabled' => false]);
        }
    }

    public function test_a_locked_feature_cannot_be_switched_back_on_from_the_card(): void
    {
        // Same downgraded shop, but the features are already off — the switch
        // must now be frozen so it cannot come back without an upgrade.
        $this->company->update(['kitchen_printer_enabled' => false, 'feature_flags' => []]);
        $this->setPlanFlags(['kot_enabled' => false, 'riders_enabled' => false]);

        $html = $this->actingAs($this->admin, 'fbrpos')->get('/fbr-pos/customize')
            ->assertOk()->getContent();

        // Nothing is ON, so no card renders a live switch at all.
        foreach (['store_slip', 'delivery', 'store_notes'] as $feature) {
            $this->assertSame(0, preg_match('/<button[^>]*featSave\(\''.preg_quote($feature, '/').'\'/', $html),
                "A fully locked '{$feature}' should show the padlock, not a switch");
        }
    }

    public function test_a_covered_shop_gets_a_normal_two_way_switch(): void
    {
        $this->company->update(['kitchen_printer_enabled' => false, 'feature_flags' => []]);
        $this->setPlanFlags(['kot_enabled' => true, 'riders_enabled' => true]);

        $html = $this->actingAs($this->admin, 'fbrpos')->get('/fbr-pos/customize')
            ->assertOk()->getContent();

        // Slip and delivery are freely switchable; the note waits on the slip
        // it prints on, which is the dependency the card explains on screen.
        $this->assertFalse($this->toggleDisabled($html, 'store_slip'));
        $this->assertFalse($this->toggleDisabled($html, 'delivery'));
        $this->assertTrue($this->toggleDisabled($html, 'store_notes'),
            'The note switch has nothing to print on until the Store Slip is on');
    }

    public function test_the_note_gate_reports_off_when_the_slip_master_switch_is_off(): void
    {
        // Slip off but the note flag still set (exactly the stale state a sale
        // screen holds after the owner switches the slip off in another tab).
        $this->company->update([
            'kitchen_printer_enabled' => false,
            'feature_flags'           => ['kitchen_notes' => true],
        ]);

        $this->actingAs($this->admin, 'fbrpos');
        $controller = new \App\Http\Controllers\FbrPosController();
        $method = new \ReflectionMethod($controller, 'fbrStoreNotesEnabled');
        $method->setAccessible(true);

        $this->assertFalse(
            $method->invoke($controller, $this->company->fresh()),
            'A posted note must be dropped while the Store Slip master switch is off'
        );

        $this->company->update(['kitchen_printer_enabled' => true]);
        $this->assertTrue($method->invoke($controller, $this->company->fresh()));
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
            $t->text('pos_printer_settings')->nullable();
            $t->softDeletes();
            $t->timestamps();
        });

        // Slip surfaces: a held sale (pre-pay ticket), a completed transaction
        // (post-pay reprint) and the silent print queue.
        Schema::create('fbr_pos_held_sales', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('company_id');
            $t->unsignedBigInteger('user_id')->nullable();
            $t->string('hold_name')->nullable();
            $t->string('customer_name')->nullable();
            $t->text('cart_data')->nullable();
            $t->unsignedSmallInteger('token_no')->nullable();
            $t->string('order_code', 10)->nullable();
            $t->timestamps();
        });

        Schema::create('fbr_pos_transactions', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('company_id');
            $t->unsignedBigInteger('user_id')->nullable();
            $t->string('invoice_number');
            $t->string('customer_name')->nullable();
            $t->decimal('subtotal', 12, 2)->default(0);
            $t->decimal('tax_amount', 12, 2)->default(0);
            $t->decimal('total_amount', 12, 2)->default(0);
            $t->string('payment_method')->nullable();
            $t->unsignedSmallInteger('token_no')->nullable();
            $t->string('order_code', 10)->nullable();
            $t->timestamps();
        });

        Schema::create('fbr_pos_transaction_items', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('transaction_id');
            $t->string('item_name');
            $t->decimal('quantity', 12, 4)->default(1);
            $t->string('special_notes', 190)->nullable();
            $t->timestamps();
        });

        Schema::create('pos_print_jobs', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('company_id');
            $t->string('type', 10);
            $t->string('target_printer');
            $t->unsignedBigInteger('transaction_id')->nullable();
            $t->unsignedBigInteger('restaurant_order_id')->nullable();
            $t->string('device_uid')->nullable();
            $t->string('status', 12)->default('pending');
            $t->unsignedBigInteger('created_by')->nullable();
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
