<?php
namespace Tests\Feature;
use App\Models\{Company,FbrPosTransaction,FbrPosTransactionItem};
use App\Support\QrImage;
use Tests\TestCase;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{DB,Schema};
class FbrPosFiscalReceiptIdentityTest extends TestCase {
 protected function tearDown(): void {QrImage::resetFake();parent::tearDown();}
 public function test_new_bill_keeps_seller_and_mapped_pos_snapshots_together_across_profile_changes(): void {
  Schema::dropAllTables();
  Schema::create('companies',function(Blueprint $t){$t->id();$t->string('name');$t->string('address');$t->string('ntn');$t->string('fbr_pos_id');$t->timestamps();$t->softDeletes();});
  Schema::create('fbr_pos_transactions',function(Blueprint $t){$t->id();$t->integer('company_id');$t->integer('branch_id')->nullable();$t->integer('terminal_id')->nullable();$t->string('invoice_mode')->nullable();$t->string('fbr_invoice_number')->nullable();$t->timestamps();});
  Schema::create('fbr_pos_terminals',function(Blueprint $t){$t->id();$t->integer('company_id');$t->integer('branch_id')->nullable();});
  (require database_path('migrations/2026_10_13_150001_add_fbr_pos_identity_mapping.php'))->up();
  (require database_path('migrations/2026_10_13_160001_add_fbr_pos_receipt_identity.php'))->up();
  foreach([1,2] as $id) DB::table('companies')->insert(['id'=>$id,'name'=>'Original seller '.$id,'address'=>'Original outlet '.$id,'ntn'=>'NTN'.$id,'fbr_pos_id'=>'1234','fbr_sales_tax_registration_number'=>'STRN'.$id,'fbr_tax_office'=>'Office '.$id]);
  DB::table('fbr_pos_identities')->insert(['company_id'=>1,'branch_id'=>10,'terminal_id'=>0,'pos_id'=>'1010','device_uid'=>'PC-A']);
  $bill=FbrPosTransaction::create(['company_id'=>1,'branch_id'=>10,'invoice_mode'=>'fbr']);
  $foreign=FbrPosTransaction::create(['company_id'=>2,'invoice_mode'=>'fbr']);
  $local=FbrPosTransaction::create(['company_id'=>1,'invoice_mode'=>'local']);
  DB::table('companies')->where('id',1)->update(['name'=>'New name','address'=>'New address','ntn'=>'NEW','fbr_sales_tax_registration_number'=>'NEW','fbr_tax_office'=>'New office']);
  DB::table('fbr_pos_identities')->where('company_id',1)->update(['pos_id'=>'9999','device_uid'=>'PC-NEW']);
  $bill->refresh();
  $this->assertSame('Original seller 1',$bill->fbr_receipt_profile_snapshot['name']);
  $this->assertSame('Original outlet 1',$bill->fbr_receipt_profile_snapshot['address']);
  $this->assertSame('NTN1',$bill->fbr_receipt_profile_snapshot['ntn']);
  $this->assertSame('STRN1',$bill->fbr_receipt_profile_snapshot['fbr_sales_tax_registration_number']);
  $this->assertSame('Office 1',$bill->fbr_receipt_profile_snapshot['fbr_tax_office']);
  $this->assertSame(['pos_id'=>'1010','device_uid'=>'PC-A'],app(\App\Services\FbrPosIdentity::class)->capture($bill));
  $this->assertSame('Original seller 2',$foreign->fresh()->fbr_receipt_profile_snapshot['name']);
  $this->assertSame('1234',$foreign->fresh()->fbr_pos_id_snapshot);
  $this->assertNull($local->fresh()->fbr_receipt_profile_snapshot);
  $this->assertNull($local->fresh()->fbr_pos_id_snapshot);
 }
 public function test_fiscal_fields_survive_all_display_toggles_and_use_original_identity(): void {
  QrImage::fake();
  foreach(['thermal','thermal58','a4'] as $paper) {
   $company=(new Company)->forceFill(['id'=>14,'name'=>'Changed profile','address'=>'New address','ntn'=>'NEWNTN','fbr_pos_id'=>'9999','print_paper_size'=>$paper,
    'invoice_display_prefs'=>['fbrpos'=>['show_business_name'=>false,'show_address'=>false,'show_ntn'=>false,'show_tax'=>false,'show_verify_line'=>false],'pos_style'=>['bold'=>false]]]);
   $bill=(new FbrPosTransaction)->forceFill(['id'=>90,'company_id'=>14,'invoice_number'=>'FPOS-2026-00001','invoice_mode'=>'fbr','fbr_status'=>'submitted','fbr_invoice_number'=>'9000000000001',
    'fbr_pos_id_snapshot'=>'1010','fbr_receipt_profile_snapshot'=>['name'=>'Original seller','address'=>'Original outlet','ntn'=>'OLDNTN','fbr_sales_tax_registration_number'=>'1234567890123','fbr_tax_office'=>'Fictional tax office'],
    'created_at'=>now(),'payment_method'=>'card','subtotal'=>1000,'total_amount'=>1180,'tax_rate'=>18,'tax_amount'=>180]);
   $bill->setRelation('company',$company)->setRelation('creator',null)->setRelation('items',collect([(new FbrPosTransactionItem)->forceFill(['item_name'=>'Fixture goods','quantity'=>1,'unit_price'=>1000,'subtotal'=>1000,'tax_rate'=>18,'tax_amount'=>180])]));
   $html=view('fbr-pos.receipt',compact('company')+['transaction'=>$bill])->render();$html=substr($html,strpos($html,'</head>'));
   foreach(['Original seller','Original outlet','OLDNTN','1234567890123','Fictional tax office','FBR POS ID: 1010','FBR: 9000000000001','Tax 18.00%: PKR 180.00','9966','data-fbr-fiscal-receipt="1"'] as $value) $this->assertStringContainsString($value,$html);
   $this->assertStringNotContainsString('FBR POS ID: 9999',$html);
   $this->assertStringNotContainsString('Changed profile',$html);
   $this->assertStringNotContainsString('New address',$html);
   $this->assertStringNotContainsString('NEWNTN',$html);
   $this->assertSame('9000000000001',array_slice(QrImage::recorded(),-1)[0]);
   foreach(['verification_pending','pending','offline','local'] as $state) {
    $bill->fbr_status=$state;$bill->fbr_invoice_number=null;
    $body=view('fbr-pos.receipt',compact('company')+['transaction'=>$bill])->render();
    $this->assertStringNotContainsString('data-fbr-fiscal-receipt="1"',$body);
    $this->assertStringNotContainsString('FBR POS ID: 1010',$body);
   }
  }
 }
}
