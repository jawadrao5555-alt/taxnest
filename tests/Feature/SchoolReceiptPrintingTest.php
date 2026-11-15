<?php
namespace Tests\Feature;

use App\Http\Controllers\SchoolReceiptSettingsController;
use App\Models\Company;
use App\Models\User;
use App\Support\SchoolReceiptPreferences;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class SchoolReceiptPrintingTest extends TestCase
{
    private Company $school;
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
        $this->school = Company::withoutEvents(fn () => Company::create([
            'name'=>'Synthetic school', 'product_type'=>'fbrpos','pos_type'=>'education','business_category'=>'education',
            'print_paper_size'=>'thermal58','invoice_display_prefs'=>['fbrpos'=>['show_tax'=>true],'pos_style'=>['show_logo'=>false]],
        ]));
        $this->owner = User::withoutEvents(fn () => User::create([
            'company_id'=>$this->school->id,'name'=>'Owner','email'=>'owner@example.test','password'=>'synthetic',
            'role'=>'company_admin','is_active'=>true,
        ]));
        $this->actingAs($this->owner,'fbrpos');
        app()->instance('currentCompanyId',$this->school->id);
    }
    private function request(array $extra=[]): Request
    {
        $r=Request::create('/fbr-pos/school-fees/printing','POST',array_replace([
            'name'=>'Updated school','address'=>'Synthetic address','phone'=>'03000000000','email'=>'school@example.test',
            'paper'=>'a4','margin_mm'=>3,'printer_label'=>'Office printer','show_logo'=>1,'show_contact'=>1,'center'=>1,
        ],$extra));
        $r->setLaravelSession(app('session.store')); app()->instance('request',$r); return $r;
    }
    public function test_preferences_preserve_retail_and_other_tenant_configuration(): void
    {
        $other=$this->school->replicate();$other->name='Other school';$other->save();
        $before=$other->fresh()->getAttributes();
        app(SchoolReceiptSettingsController::class)->update($this->request());
        $this->school->refresh();
        $this->assertSame('thermal58',$this->school->print_paper_size);
        $this->assertSame(['show_tax'=>true],$this->school->invoice_display_prefs['fbrpos']);
        $this->assertSame(['show_logo'=>false],$this->school->invoice_display_prefs['pos_style']);
        $this->assertSame('a4',SchoolReceiptPreferences::for($this->school)['paper']);
        $this->assertSame($before,$other->fresh()->getAttributes());
    }
    public function test_logo_and_contact_are_saved_and_used_by_preview(): void
    {
        \Illuminate\Support\Facades\Storage::fake('public');
        $r=$this->request();
        $r->files->set('logo', \Illuminate\Http\UploadedFile::fake()->image('school.png', 60, 60));
        app(SchoolReceiptSettingsController::class)->update($r);
        $this->school->refresh();
        \Illuminate\Support\Facades\Storage::disk('public')->assertExists($this->school->logo_path);
        $html=app(SchoolReceiptSettingsController::class)->preview()->getContent();
        $this->assertStringContainsString('data-school-logo', $html);
        $this->assertStringContainsString('storage/'.$this->school->logo_path, $html);
        $this->assertStringContainsString('school@example.test', $html);
        $this->assertStringContainsString('Synthetic address', $html);
        $this->assertStringContainsString('Office printer', $html);
    }
    public function test_readonly_impersonation_cannot_save_profile_or_preferences(): void
    {
        $r=$this->request();$r->session()->put('impersonation.readonly',true);
        try { app(SchoolReceiptSettingsController::class)->update($r);$this->fail('Expected forbidden'); }
        catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) { $this->assertSame(403,$e->getStatusCode()); }
        $this->assertSame('Synthetic school',$this->school->fresh()->name);
    }
    public function test_cashier_cannot_modify_printing_preferences(): void
    {
        $this->owner->role='employee';$this->owner->pos_role='pos_cashier';$this->owner->save();
        try { app(SchoolReceiptSettingsController::class)->update($this->request());$this->fail('Expected forbidden'); }
        catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) { $this->assertSame(403,$e->getStatusCode()); }
    }
    public function test_preview_is_synthetic_and_legacy_receipts_fallback_to_cash_and_original_amount(): void
    {
        $preview=app(SchoolReceiptSettingsController::class)->preview();
        $html=$preview->getContent();
        $this->assertStringContainsString('SAMPLE PREVIEW',$html);
        $this->assertStringContainsString('SF-PREVIEW',$html);
        $this->assertStringContainsString('Bank (recorded)',$html);
        $this->assertStringContainsString('PKR 500.00',$html);
        $this->assertStringContainsString('data-paper="58mm"',$html);
        $this->assertStringNotContainsString('sessionStorage.removeItem',$html);
        $this->assertStringContainsString('no-store',$preview->headers->get('Cache-Control'));
        $receipt=(object)['id'=>42,'student_reference'=>'OLD-1','fee_head'=>'tuition','amount_paisa'=>125050,'collected_at'=>'2026-01-01 10:00:00','request_key'=>'synthetic','branch_id'=>null];
        $html=view('fbr-pos.school-fees.receipt',['company'=>$this->school,'receipt'=>$receipt])->render();
        $this->assertStringContainsString('PKR 1,250.50',$html);
        $this->assertStringContainsString('>Cash</dd>',$html);
        $this->assertStringNotContainsString('CERTIFIED',$html);
    }
}
