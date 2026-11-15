<?php
namespace Tests\Feature;

use App\Http\Controllers\HospitalReceiptSettingsController;
use App\Models\Company;
use App\Models\User;
use App\Support\HospitalReceiptPreferences;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class HospitalReceiptPrintingTest extends TestCase
{
    private Company $hospital;
    private User $owner;
    protected function setUp(): void
    {
        parent::setUp();
        Schema::dropAllTables();
        app('view')->share('errors', new \Illuminate\Support\ViewErrorBag());
        Schema::create('companies', function (Blueprint $t) {
            $t->id(); $t->string('name'); $t->string('product_type'); $t->string('pos_type');
            $t->string('business_category'); $t->json('invoice_display_prefs')->nullable();
            foreach (['address','phone','email','logo_path','print_paper_size'] as $f) $t->string($f)->nullable();
            $t->boolean('fbr_reporting_enabled')->default(false); $t->softDeletes(); $t->timestamps();
        });
        Schema::create('users', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('company_id'); $t->string('name'); $t->string('email'); $t->string('password');
            $t->string('role'); $t->string('pos_role')->nullable(); $t->boolean('is_active')->default(true); $t->timestamps();
        });
        $this->hospital = Company::withoutEvents(fn () => Company::create([
            'name'=>'Synthetic hospital', 'product_type'=>'fbrpos','pos_type'=>'clinic','business_category'=>'clinic',
            'print_paper_size'=>'thermal58','invoice_display_prefs'=>['fbrpos'=>['show_tax'=>true],'pos_style'=>['show_logo'=>false]],
        ]));
        $this->owner = User::withoutEvents(fn () => User::create([
            'company_id'=>$this->hospital->id,'name'=>'Owner','email'=>'owner@example.test','password'=>'synthetic',
            'role'=>'company_admin','is_active'=>true,
        ]));
        $this->actingAs($this->owner,'fbrpos');
        app()->instance('currentCompanyId',$this->hospital->id);
    }
    private function request(array $extra=[]): Request
    {
        $r=Request::create('/fbr-pos/hospital/printing','POST',array_replace([
            'name'=>'Updated hospital','address'=>'Synthetic address','phone'=>'03000000000','email'=>'hospital@example.test',
            'paper'=>'a4','margin_mm'=>3,'printer_label'=>'Office printer','show_logo'=>1,'show_contact'=>1,'center'=>1,
        ],$extra));
        $r->setLaravelSession(app('session.store')); app()->instance('request',$r); return $r;
    }
    public function test_preferences_preserve_retail_and_other_tenant_configuration(): void
    {
        $other=$this->hospital->replicate();$other->name='Other hospital';$other->save();
        $before=$other->fresh()->getAttributes();
        app(HospitalReceiptSettingsController::class)->update($this->request());
        $this->hospital->refresh();
        $this->assertSame('thermal58',$this->hospital->print_paper_size);
        $this->assertSame(['show_tax'=>true],$this->hospital->invoice_display_prefs['fbrpos']);
        $this->assertSame(['show_logo'=>false],$this->hospital->invoice_display_prefs['pos_style']);
        $this->assertSame('a4',HospitalReceiptPreferences::for($this->hospital)['paper']);
        $this->assertSame($before,$other->fresh()->getAttributes());
    }
    public function test_logo_and_contact_are_saved_and_used_by_preview(): void
    {
        \Illuminate\Support\Facades\Storage::fake('public');
        $r=$this->request();
        $r->files->set('logo', \Illuminate\Http\UploadedFile::fake()->image('hospital.png', 60, 60));
        app(HospitalReceiptSettingsController::class)->update($r);
        $this->hospital->refresh();
        \Illuminate\Support\Facades\Storage::disk('public')->assertExists($this->hospital->logo_path);
        $html=app(HospitalReceiptSettingsController::class)->preview()->getContent();
        $this->assertStringContainsString('data-hospital-logo', $html);
        $this->assertStringContainsString('storage/'.$this->hospital->logo_path, $html);
        $this->assertStringContainsString('hospital@example.test', $html);
        $this->assertStringContainsString('Synthetic address', $html);
        $this->assertStringContainsString('Office printer', $html);
    }
    public function test_readonly_impersonation_cannot_save_profile_or_preferences(): void
    {
        $r=$this->request();$r->session()->put('impersonation.readonly',true);
        try { app(HospitalReceiptSettingsController::class)->update($r);$this->fail('Expected forbidden'); }
        catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) { $this->assertSame(403,$e->getStatusCode()); }
        $this->assertSame('Synthetic hospital',$this->hospital->fresh()->name);
    }
    public function test_cashier_cannot_modify_printing_preferences(): void
    {
        $this->owner->role='employee';$this->owner->pos_role='pos_cashier';$this->owner->save();
        try { app(HospitalReceiptSettingsController::class)->update($this->request());$this->fail('Expected forbidden'); }
        catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) { $this->assertSame(403,$e->getStatusCode()); }
    }
    public function test_preview_is_synthetic_and_legacy_receipts_fallback_to_cash_and_original_amount(): void
    {
        $preview=app(HospitalReceiptSettingsController::class)->preview();
        $html=$preview->getContent();
        $this->assertStringContainsString('SAMPLE PREVIEW',$html);
        $this->assertStringContainsString('HP-PREVIEW',$html);
        $this->assertStringContainsString('Bank (recorded)',$html);
        $this->assertStringContainsString('PKR 500.00',$html);
        $this->assertStringContainsString('data-paper="58mm"',$html);
        $this->assertStringNotContainsString('sessionStorage.removeItem',$html);
        $this->assertStringContainsString('no-store',$preview->headers->get('Cache-Control'));
        $invoice=(object)['id'=>7,'patient_reference'=>'OLD-1','service_head'=>'consultation','total_paisa'=>200000,'request_key'=>'old'];
        $payment=(object)['id'=>42,'amount_paisa'=>125050,'balance_paisa'=>74950,'collected_at'=>'2026-01-01 10:00:00','request_key'=>'old','branch_id'=>null,'payment_method'=>'cash','campus_name'=>'Old campus','cashier_name'=>'Old cashier'];
        $html=view('fbr-pos.hospital.receipt',['company'=>$this->hospital,'invoice'=>$invoice,'payment'=>$payment])->render();
        $this->assertStringContainsString('PKR 1,250.50',$html);
        $this->assertStringContainsString('PKR 749.50',$html);
        $this->assertStringContainsString('>Cash</dd>',$html);
        $this->assertStringNotContainsString('CERTIFIED',$html);
    }
}
