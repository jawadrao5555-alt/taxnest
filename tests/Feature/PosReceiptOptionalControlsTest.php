<?php

namespace Tests\Feature;

use App\Models\Company;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class PosReceiptOptionalControlsTest extends TestCase
{
    private int $companyId;

    private int $adminUserId;

    private int $cashierUserId;

    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropAllTables();

        Schema::create('companies', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('product_type')->nullable();
            $table->string('status')->default('approved');
            $table->string('company_status')->default('active');
            $table->string('pos_theme')->nullable();
            $table->string('pos_dashboard_style')->nullable();
            $table->boolean('pra_reporting_enabled')->default(false);
            $table->boolean('fbr_pos_enabled')->default(false);
            $table->boolean('fbr_universal_enabled')->default(false);
            $table->boolean('restaurant_mode')->default(false);
            $table->string('default_language')->nullable();
            $table->string('receipt_printer_size')->nullable();
            $table->boolean('pos_receipt_show_tax')->default(true);
            $table->json('invoice_display_prefs')->nullable();
            $table->text('logo_path')->nullable();
            $table->string('address')->nullable();
            $table->string('city')->nullable();
            $table->string('phone')->nullable();
            $table->string('mobile')->nullable();
            $table->string('email')->nullable();
            $table->string('ntn')->nullable();
            $table->string('website')->nullable();
            $table->string('business_activity')->nullable();
            $table->string('fbr_registration_no')->nullable();
            $table->string('public_profile_slug')->nullable();
            $table->json('public_profile_settings')->nullable();
            $table->boolean('pos_setup_completed')->default(true);
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
            $table->string('language')->nullable();
            $table->boolean('is_active')->default(true);
            $table->json('pos_access_overrides')->nullable();
            $table->rememberToken();
            $table->timestamps();
        });

        Schema::create('pos_feature_flags', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->string('flag');
            $table->boolean('enabled')->default(false);
            $table->timestamps();
        });

        Schema::create('pos_subscriptions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->string('status')->default('active');
            $table->boolean('active')->default(true);
            $table->string('plan_key')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
        });

        // branches: needed by PosAuth → BranchContextService::autoSelectBranch
        Schema::create('branches', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->string('name');
            $table->boolean('is_active')->default(true);
            $table->boolean('is_head_office')->default(false);
            $table->timestamps();
        });

        // branch_user pivot: manager branch assignments
        Schema::create('branch_user', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('branch_id');
            $table->unsignedBigInteger('user_id');
            $table->timestamps();
        });

        // pos_terminals: PosAuth resolves terminal_id from session
        Schema::create('pos_terminals', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('branch_id')->nullable();
            $table->string('terminal_name')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        // subscriptions: PosAccessService checks subscription status
        Schema::create('subscriptions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('pricing_plan_id')->nullable();
            $table->boolean('active')->default(true);
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();
            $table->timestamp('trial_ends_at')->nullable();
            $table->string('override_type')->default('none');
            $table->timestamp('override_until')->nullable();
            $table->timestamp('override_granted_at')->nullable();
            $table->integer('free_invoice_limit')->nullable();
            $table->timestamps();
        });

        // pricing_plans: subscription plan gates
        Schema::create('pricing_plans', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('product_type')->default('pos');
            $table->boolean('is_trial')->default(false);
            $table->integer('invoice_limit')->nullable();
            $table->integer('user_limit')->nullable();
            $table->boolean('restaurant_enabled')->default(true);
            $table->boolean('deals_enabled')->default(false);
            $table->timestamps();
        });

        // app_updates: PosAccessService / layout may check for updates
        Schema::create('app_updates', function (Blueprint $table) {
            $table->id();
            $table->string('product_type')->default('pos');
            $table->string('title')->nullable();
            $table->text('description')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
        });

        // user_update_reads: tracks which updates a user has seen
        Schema::create('user_update_reads', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('app_update_id');
            $table->timestamps();
        });

        $company = DB::table('companies')->insertGetId([
            'name' => 'Test Shop',
            'product_type' => 'pos',
            'status' => 'approved',
            'company_status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->companyId = $company;

        $this->adminUserId = DB::table('users')->insertGetId([
            'name' => 'Admin',
            'email' => 'admin@test.com',
            'password' => Hash::make('password'),
            'company_id' => $this->companyId,
            'pos_role' => 'admin',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->cashierUserId = DB::table('users')->insertGetId([
            'name' => 'Cashier',
            'email' => 'cashier@test.com',
            'password' => Hash::make('password'),
            'company_id' => $this->companyId,
            'pos_role' => 'pos_cashier',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_options_save_independently_preserve_stale_fields_and_other_tenant(): void
    {
        $company = Company::findOrFail($this->companyId);
        $company->invoice_display_prefs = ['pos' => ['show_mobile' => false], 'pos_style' => ['bold' => false],
            'fbrpos' => ['sentinel' => true], 'receipt_options' => ['local' => ['show_waiter' => 'hide', 'future_option' => 'keep']]];
        $company->save();
        $other = Company::create(['name' => 'Other tenant', 'product_type' => 'pos', 'invoice_display_prefs' => ['sentinel' => 'other']]);
        $beforeOther = $other->fresh()->invoice_display_prefs;
        $this->actingAs(\App\Models\User::findOrFail($this->adminUserId), 'pos')
            ->post('/pos/receipt-settings', ['receipt_options_pra' => ['show_terminal' => 'hide', 'total_size' => 'large', 'design'=>'compact','kot_design'=>'checklist'],
                'receipt_options_local' => ['show_rate' => 'hide','design'=>'boxed','kot_design'=>'quantity_first']])->assertRedirect();
        $prefs = $company->fresh()->invoice_display_prefs;
        $this->assertSame('hide', $prefs['receipt_options']['pra']['show_terminal']);
        $this->assertSame('large', $prefs['receipt_options']['pra']['total_size']);
        $this->assertSame('compact',$prefs['receipt_options']['pra']['design']);
        $this->assertSame('checklist',$prefs['receipt_options']['pra']['kot_design']);
        $this->assertSame('boxed',$prefs['receipt_options']['local']['design']);
        $this->assertSame('quantity_first',$prefs['receipt_options']['local']['kot_design']);
        $this->assertSame(['bold'=>false],$prefs['pos_style'],'Scoped design Save preserves the exact legacy typography/logo representation');
        $this->assertSame('hide', $prefs['receipt_options']['local']['show_waiter']);
        $this->assertSame('keep', $prefs['receipt_options']['local']['future_option']);
        $this->assertSame(false, $prefs['pos']['show_mobile']);
        $this->assertSame(['sentinel' => true], $prefs['fbrpos']);
        $this->post('/pos/receipt-settings', [])->assertRedirect();
        $this->assertSame($prefs, $company->fresh()->invoice_display_prefs);
        $this->assertSame($beforeOther, $other->fresh()->invoice_display_prefs);
        $this->post('/pos/receipt-settings', ['receipt_options_pra' => ['show_terminal' => 'inherit']])->assertRedirect();
        $this->assertArrayNotHasKey('show_terminal', $company->fresh()->invoice_display_prefs['receipt_options']['pra']);
    }

    public function test_invalid_options_and_cashier_cannot_change_design(): void
    {
        $before = Company::findOrFail($this->companyId)->invoice_display_prefs;
        $this->actingAs(\App\Models\User::findOrFail($this->adminUserId), 'pos')
            ->post('/pos/receipt-settings', ['receipt_options_local' => ['show_terminal' => 'javascript:bad']])
            ->assertSessionHasErrors('receipt_options_local.show_terminal');
        $this->post('/pos/receipt-settings', ['receipt_options_pra' => ['arbitrary' => 'bad']])
            ->assertSessionHasErrors('receipt_options_pra');
        $this->actingAs(\App\Models\User::findOrFail($this->cashierUserId), 'pos')
            ->post('/pos/receipt-settings', ['receipt_options_local' => ['show_terminal' => 'hide']])->assertRedirect(route('pos.dashboard'));
        $this->assertSame($before, Company::findOrFail($this->companyId)->invoice_display_prefs);
    }

    private function bill(Company $company, ?string $status = null): \App\Models\PosTransaction
    {
        $bill = new \App\Models\PosTransaction(['invoice_number' => 'TEST-P2240', 'invoice_mode' => 'pra',
            'pra_status' => $status, 'pra_invoice_number' => $status === 'submitted' ? 'SYNTHETIC-FISCAL-2240' : null,
            'payment_method' => 'cash', 'subtotal' => 500, 'tax_rate' => 16, 'tax_amount' => 80,
            'discount_amount' => 0, 'total_amount' => 580]);
        $bill->id = 777;
        $bill->company_id = $company->id;
        $bill->created_at = now();
        $bill->setRelation('company', $company);
        $bill->setRelation('items', collect([new \App\Models\PosTransactionItem(['item_name' => 'Synthetic full product name',
            'item_type' => 'product', 'quantity' => 1, 'unit_price' => 500, 'subtotal' => 500, 'special_notes' => 'Synthetic item comment'])]));
        $bill->setRelation('terminal', (object) ['terminal_name' => 'SYNTHETIC-COUNTER']);
        $bill->setRelation('creator', (object) ['name' => 'SYNTHETIC-CASHIER']);
        $bill->setRelation('rider', null);
        $bill->setRelation('payments', collect());

        return $bill;
    }

    public function test_both_actual_receipts_keep_legacy_and_apply_only_selected_stream(): void
    {
        $company = Company::findOrFail($this->companyId);
        $company->invoice_display_prefs = ['pos_style' => ['show_menu_qr' => false, 'bold' => false],
            'receipt_options' => ['local' => ['show_terminal' => 'hide', 'show_cashier' => 'hide', 'show_rate' => 'hide',
                'show_quantity' => 'hide', 'show_item_serial' => 'show', 'show_item_notes' => 'hide',
                'total_size' => 'large', 'extra_note' => '<script>bad</script>']]];
        foreach (['80mm', '58mm'] as $paper) {
            $bill = $this->bill($company);
            $before = $bill->getAttributes();
            $html = view('pos.receipts.receipt_'.$paper, compact('company') + ['transaction' => $bill])->render();
            $this->assertStringNotContainsString('SYNTHETIC-COUNTER', $html);
            $this->assertStringNotContainsString('SYNTHETIC-CASHIER', $html);
            $this->assertStringNotContainsString('<th class="col-rate">', $html);
            $this->assertStringNotContainsString('<th class="col-qty">', $html);
            $this->assertStringNotContainsString('Synthetic item comment', $html);
            $this->assertStringContainsString('1. Synthetic full product name', $html);
            $this->assertStringContainsString('580.00', $html);
            $this->assertStringContainsString('&lt;script&gt;bad&lt;/script&gt;', $html);
            $this->assertSame($before, $bill->getAttributes());
            // Fiscal stream inherits unchanged existing controls and mandatory identity/QR.
            $html = view('pos.receipts.receipt_'.$paper, compact('company') + ['transaction' => $this->bill($company, 'submitted')])->render();
            $this->assertStringContainsString('SYNTHETIC-COUNTER', $html);
            $this->assertStringContainsString('SYNTHETIC-CASHIER', $html);
            $this->assertStringContainsString('<th class="col-rate">', $html);
            $this->assertStringContainsString('SYNTHETIC-FISCAL-2240', $html);
            $this->assertStringContainsString($paper === '58mm' ? 'PRA QR' : 'PRA Verification QR', $html);
        }
    }

    public function test_hide_all_optional_fields_still_keeps_fiscal_identity_items_and_payable(): void
    {
        $company = Company::findOrFail($this->companyId);
        $hidden = array_fill_keys(array_keys(\App\Support\PosReceiptOptions::VISIBILITY), 'hide');
        $company->invoice_display_prefs = ['receipt_options' => ['pra' => $hidden, 'local' => $hidden]];
        foreach (['80mm', '58mm'] as $paper) {
            $html = view('pos.receipts.receipt_'.$paper, ['company' => $company, 'transaction' => $this->bill($company, 'submitted')])->render();
            $this->assertStringContainsString('SYNTHETIC-FISCAL-2240', $html);
            $this->assertStringContainsString('TEST-P2240', $html);
            $this->assertStringContainsString('Synthetic full product name', $html);
            $this->assertStringContainsString('PKR 580.00', $html);
            $this->assertStringContainsString($paper === '58mm' ? 'PRA QR' : 'PRA Verification QR', $html);
            $this->assertStringNotContainsString('SYNTHETIC-COUNTER', $html);
            $this->assertStringNotContainsString('SYNTHETIC-CASHIER', $html);
            $this->assertStringNotContainsString('Synthetic item comment', $html);
        }
    }

    public function test_options_use_existing_fiscal_local_exempt_and_pending_stream_rules(): void
    {
        $company = Company::findOrFail($this->companyId);
        $company->invoice_display_prefs = ['receipt_options' => ['pra' => ['show_terminal' => 'hide'], 'local' => ['show_rate' => 'hide']]];
        foreach ([null => 'local', 'pending' => 'pra', 'offline' => 'pra', 'submitted' => 'pra', 'exempt_internal' => 'local'] as $status => $stream) {
            $bill = $this->bill($company, $status === '' ? null : $status);
            $this->assertSame(\App\Support\PosReceiptOptions::saved($company, $stream), \App\Support\PosReceiptOptions::forBill($company, $bill));
        }
        $prefs = $company->invoice_display_prefs;
        $prefs['receipt_options']['local']['show_business_name'] = 'hide';
        $company->invoice_display_prefs = $prefs;
        $this->assertFalse($company->posReceiptPrefsFor($this->bill($company))['show_business_name'], 'Public bill and receipt resolve the same business-name visibility');
        $this->assertTrue($company->posReceiptPrefsFor($this->bill($company, 'submitted'))['show_business_name']);
        $bill = $this->bill($company, 'pending');
        $bill->invoice_mode = 'local';
        $this->assertSame(['show_rate' => 'hide', 'show_business_name' => 'hide'], \App\Support\PosReceiptOptions::forBill($company, $bill));
    }

    public function test_explicit_logo_choice_overrides_legacy_gate_but_inherit_keeps_it(): void
    {
        $original = Company::findOrFail($this->companyId);
        $company = \Mockery::mock(Company::class)->makePartial();
        $company->setRawAttributes($original->getAttributes());
        $logo = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aG2kAAAAASUVORK5CYII=';
        $company->shouldReceive('receiptLogoDataUri')->andReturn($logo);
        foreach (['80mm', '58mm'] as $paper) {
            $company->invoice_display_prefs = ['pos_style' => ['show_logo' => true, 'logo_finals_only' => true, 'show_menu_qr' => false]];
            $bill = $this->bill($company);
            $bill->invoice_mode = 'local';
            $html = view('pos.receipts.receipt_'.$paper, ['company' => $company, 'transaction' => $bill])->render();
            $this->assertStringNotContainsString($logo, $html);
            $prefs = $company->invoice_display_prefs;
            $prefs['receipt_options']['local']['show_logo'] = 'show';
            $company->invoice_display_prefs = $prefs;
            $html = view('pos.receipts.receipt_'.$paper, ['company' => $company, 'transaction' => $bill])->render();
            $this->assertStringContainsString($logo, $html);
        }
    }

    public function test_settings_get_is_read_only_and_exposes_both_option_sets(): void
    {
        $before = Company::findOrFail($this->companyId)->getAttributes();
        $this->actingAs(\App\Models\User::findOrFail($this->adminUserId), 'pos')->get('/pos/receipt-settings')
            ->assertSee('/js/pos-print-design-preview.js?v=20261031-b03', false)
            ->assertOk()->assertSee('receipt_options_local[show_terminal]', false)->assertSee('receipt_options_pra[show_waiter]', false);
        $this->assertSame($before, Company::findOrFail($this->companyId)->getAttributes());
    }
    public function test_all_nine_designs_render_both_receipt_streams_and_widths_without_changing_financial_snapshots(): void
    {
        $company=Company::findOrFail($this->companyId);
        foreach (array_keys(\App\Support\PosPrintDesigns::RECEIPTS) as $design) {
            foreach ([null,'submitted'] as $status) {
                $stream=$status ? 'pra':'local';
                $company->invoice_display_prefs=['pos_style'=>['bold'=>false,'font_family'=>'mono','item_text'=>'compact'], 'receipt_options'=>[$stream=>['design'=>$design]]];
                $prefs=$company->invoice_display_prefs;
                foreach (['58mm','80mm'] as $paper) {
                    $bill=$this->bill($company,$status);$before=$bill->getAttributes();
                    $html=view('pos.receipts.receipt_'.$paper,['company'=>$company,'transaction'=>$bill])->render();
                    if ($design!=='inherit') $this->assertStringContainsString('b03-receipt-design:'.$design,$html);
                    else $this->assertStringNotContainsString('b03-receipt-design:',$html);
                    $this->assertStringContainsString('Synthetic full product name',$html);
                    $this->assertStringContainsString('580.00',$html);
                    if ($status) $this->assertStringContainsString('SYNTHETIC-FISCAL-2240',$html);
                    else $this->assertStringNotContainsString('SYNTHETIC-FISCAL-2240',$html);
                    $this->assertSame($before,$bill->getAttributes());
                    $this->assertSame($prefs,$company->invoice_display_prefs);
                }
            }
        }
    }

    public function test_design_previews_use_actual_renderers_without_saving_or_printing_and_reject_invalid_choices(): void
    {
        $company=Company::findOrFail($this->companyId);
        $before=$company->invoice_display_prefs;
        $this->actingAs(\App\Models\User::findOrFail($this->adminUserId),'pos');
        foreach (['local','pra'] as $stream) foreach (['receipt','kot'] as $kind) {
            $choices=$kind==='receipt'?\App\Support\PosPrintDesigns::RECEIPTS:\App\Support\PosPrintDesigns::KOTS;
            foreach (array_keys($choices) as $design) {
                $key=$kind==='receipt'?'design':'kot_design';
                $url='/pos/receipt-design-preview?'.http_build_query(['kind'=>$kind,'stream'=>$stream,'receipt_options_'.$stream=>[$key=>$design]]);
                $response=$this->get($url)->assertOk()->assertSee('DESIGN PREVIEW');
                $this->assertStringContainsString('sandbox',$response->headers->get('Content-Security-Policy'));
                $this->assertStringNotContainsString('<script',$response->getContent());
                if ($design!=='inherit') $response->assertSee('b03-'.$kind.'-design:'.$design,false);
                $this->assertSame($before,$company->fresh()->invoice_display_prefs);
            }
        }
        $this->getJson('/pos/receipt-design-preview?'.http_build_query(['kind'=>'receipt','stream'=>'local','receipt_options_local'=>['design'=>'javascript:bad']]))->assertUnprocessable();
        $this->actingAs(\App\Models\User::findOrFail($this->cashierUserId),'pos')->get('/pos/receipt-design-preview?kind=receipt&stream=local')->assertRedirect(route('pos.dashboard'));
        $this->assertSame($before,$company->fresh()->invoice_display_prefs);
    }

    public function test_kot_omits_repeated_whole_order_pack_labels_but_keeps_all_real_mixed_portion_instructions(): void
    {
        $company=Company::findOrFail($this->companyId);
        $order=new \App\Models\RestaurantOrder(['company_id'=>$company->id,'order_number'=>'SYNTHETIC-KOT','order_type'=>'takeaway','kot_print_count'=>1]);
        $order->id=0;$order->created_at=now();$order->setRelation('table',null);$order->setRelation('creator',(object)['name'=>'Synthetic Cashier']);
        $items=collect([new \App\Models\RestaurantOrderItem(['item_name'=>'Synthetic Meal','quantity'=>2,'fulfillment'=>'takeaway','special_notes'=>'Only this meal has this note'])]);
        $render=function () use ($company,$order,$items) { $order->setRelation('items',$items);return view('pos.restaurant.kitchen-ticket',['company'=>$company,'order'=>$order,'ticketItems'=>$items,'grouped'=>collect(['ALL'=>$items]),'delta'=>false,'newItemIds'=>collect()])->render(); };
        $html=$render();
        $this->assertStringNotContainsString('PACK / TAKEAWAY',$html);
        $this->assertSame(1,substr_count($html,'Only this meal has this note'));
        $order->order_type='dine_in';
        $items->push(new \App\Models\RestaurantOrderItem(['item_name'=>'Synthetic Here Portion','quantity'=>1,'fulfillment'=>'dine_in']));
        $html=$render();
        $this->assertStringContainsString('PACK / TAKEAWAY',$html);
        $this->assertStringContainsString('SERVE HERE',$html);
        $this->assertStringContainsString('Synthetic Here Portion',$html);
        $this->assertSame(1,substr_count($html,'Only this meal has this note'));
    }

}
