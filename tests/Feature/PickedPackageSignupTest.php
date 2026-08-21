<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * PICKED PACKAGE ARRIVES AT SIGNUP (Task 1483).
 *
 * The three marketing pricing pages now sell straight from the comparison
 * table: every package column carries a Choose button that opens that
 * product's signup with ?plan=<package name>.
 *
 * What each signup does with that name differs BY DESIGN:
 *   - PRA POS already owns a package picker, so the named package arrives
 *     ticked and register() still validates whatever is finally posted.
 *   - FBR POS and Digital Invoice have no picker (the admin assigns the plan
 *     at approval), so the name is echoed back as a label only. Carrying it
 *     past the signup page is deliberately a separate piece of work — these
 *     tests lock the label behaviour, not a promise of persistence.
 *
 * Locked here:
 *   1. A real package name preselects (PRA) / is named back (FBR, DI).
 *   2. A missing name changes nothing on any of the three.
 *   3. An unknown or tampered name falls back to nothing — never echoed raw.
 *   4. The lookup is product-scoped: a PRA package cannot be picked on the
 *      FBR or DI signup, and vice versa.
 */
class PickedPackageSignupTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropAllTables();

        Schema::create('pricing_plans', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('product_type')->default('pos');
            $table->decimal('price', 12, 2)->default(0);
            $table->decimal('price_quarterly', 12, 2)->nullable();
            $table->integer('invoice_limit')->default(0);
            $table->integer('user_limit')->nullable();
            $table->integer('branch_limit')->nullable();
            $table->integer('max_products')->nullable();
            $table->boolean('is_trial')->default(false);
            $table->text('features')->nullable();
            $table->timestamps();
        });

        $rows = [
            ['name' => 'Business',  'product_type' => 'pos',    'price' => 24999, 'price_quarterly' => 7199],
            ['name' => 'Pro Max',   'product_type' => 'pos',    'price' => 49999, 'price_quarterly' => 14399],
            ['name' => 'FBR Growth', 'product_type' => 'fbrpos', 'price' => 22549],
            ['name' => 'DI Premium', 'product_type' => 'di',     'price' => 3499],
            // A trial row must never be pickable on any surface.
            ['name' => 'Free Trial', 'product_type' => 'pos',    'price' => 0, 'is_trial' => true],
        ];

        foreach ($rows as $row) {
            DB::table('pricing_plans')->insert(array_merge([
                'invoice_limit' => 1000,
                'user_limit'    => 5,
                'branch_limit'  => 1,
                'max_products'  => -1,
                'is_trial'      => false,
                'created_at'    => now(),
                'updated_at'    => now(),
            ], $row));
        }
    }

    private function planId(string $name): int
    {
        return (int) DB::table('pricing_plans')->where('name', $name)->value('id');
    }

    // ---------------------------------------------------------------- PRA POS

    public function test_pra_signup_ticks_the_package_the_shop_clicked(): void
    {
        $response = $this->get('/pos/register?plan=Pro+Max');

        $response->assertOk();
        $this->assertSame($this->planId('Pro Max'), (int) $response->viewData('preselectedPlanId'));
        // The picker's Alpine state boots on that id, so the column arrives ticked.
        $response->assertSee("planId: '" . $this->planId('Pro Max') . "'", false);
    }

    public function test_pra_signup_matches_the_package_name_case_insensitively(): void
    {
        $response = $this->get('/pos/register?plan=business');

        $response->assertOk();
        $this->assertSame($this->planId('Business'), (int) $response->viewData('preselectedPlanId'));
    }

    public function test_pra_signup_leaves_the_picker_empty_without_a_package(): void
    {
        $response = $this->get('/pos/register');

        $response->assertOk();
        $this->assertNull($response->viewData('preselectedPlanId'));
        $response->assertSee("planId: ''", false);
    }

    public function test_pra_signup_ignores_an_unknown_or_tampered_package(): void
    {
        foreach (['Platinum Deluxe', '999', '<script>alert(1)</script>', 'Free Trial'] as $bogus) {
            $response = $this->get('/pos/register?plan=' . urlencode($bogus));

            $response->assertOk();
            $this->assertNull(
                $response->viewData('preselectedPlanId'),
                "'{$bogus}' must not preselect any package."
            );
            $response->assertDontSee('alert(1)', false);
        }
    }

    public function test_pra_signup_cannot_be_pointed_at_another_products_package(): void
    {
        foreach (['FBR Growth', 'DI Premium'] as $foreign) {
            $response = $this->get('/pos/register?plan=' . urlencode($foreign));

            $response->assertOk();
            $this->assertNull($response->viewData('preselectedPlanId'));
        }
    }

    // ---------------------------------------------------------------- FBR POS

    public function test_fbr_signup_names_the_package_the_shop_clicked(): void
    {
        $response = $this->get('/fbr-pos/register?plan=FBR+Growth');

        $response->assertOk();
        $this->assertSame('FBR Growth', $response->viewData('pickedPlanName'));
        $response->assertSee('FBR Growth', false);
    }

    public function test_fbr_signup_shows_no_package_notice_without_a_package(): void
    {
        $response = $this->get('/fbr-pos/register');

        $response->assertOk();
        $this->assertNull($response->viewData('pickedPlanName'));
        $response->assertDontSee(__('pos.auth_picked_package'), false);
    }

    public function test_fbr_signup_ignores_an_unknown_or_foreign_package(): void
    {
        foreach (['Platinum Deluxe', '<script>alert(1)</script>', 'Business', 'DI Premium'] as $bogus) {
            $response = $this->get('/fbr-pos/register?plan=' . urlencode($bogus));

            $response->assertOk();
            $this->assertNull(
                $response->viewData('pickedPlanName'),
                "'{$bogus}' must not be named back on the FBR POS signup."
            );
            $response->assertDontSee('alert(1)', false);
        }
    }

    // -------------------------------------------------------- Digital Invoice

    public function test_di_signup_names_the_package_the_visitor_clicked(): void
    {
        $response = $this->get('/register?plan=DI+Premium');

        $response->assertOk();
        $this->assertSame('DI Premium', $response->viewData('pickedPlanName'));
        $response->assertSee('DI Premium', false);
    }

    public function test_di_signup_shows_no_package_notice_without_a_package(): void
    {
        $response = $this->get('/register');

        $response->assertOk();
        $this->assertNull($response->viewData('pickedPlanName'));
        $response->assertDontSee('Package you picked', false);
    }

    public function test_di_signup_ignores_an_unknown_or_foreign_package(): void
    {
        foreach (['Platinum Deluxe', '<script>alert(1)</script>', 'Business', 'FBR Growth'] as $bogus) {
            $response = $this->get('/register?plan=' . urlencode($bogus));

            $response->assertOk();
            $this->assertNull(
                $response->viewData('pickedPlanName'),
                "'{$bogus}' must not be named back on the Digital Invoice signup."
            );
            $response->assertDontSee('alert(1)', false);
        }
    }
}
