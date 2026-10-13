<?php
namespace Tests\Feature;
use App\Models\{Company,FbrPosTransaction,FbrPosTransactionItem};
use App\Support\QrImage;
use Tests\TestCase;
class FbrPosFiscalReceiptIdentityTest extends TestCase {
 protected function tearDown(): void {QrImage::resetFake();parent::tearDown();}
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
