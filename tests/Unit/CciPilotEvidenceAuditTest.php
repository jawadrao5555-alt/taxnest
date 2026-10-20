<?php
namespace Tests\Unit;
use App\Services\CciPilotEvidenceAudit;
use PHPUnit\Framework\TestCase;
class CciPilotEvidenceAuditTest extends TestCase {
 private function fixture(): array {
  $rows=[];foreach([['SALE','S',1,16,100,95],['SALE','EMPTY',-1,35,-10,-10],['DAMAGE','D',-1,230,-5,-5]] as [$inv,$sku,$qty,$row,$gross,$net]) {
   $footer=$inv==='SALE'?['gross'=>'90','discount'=>'5','vat'=>'2','net'=>'87']:['gross'=>'-5','discount'=>'0','vat'=>'0','net'=>'-5'];
   $data=['source_invoice_number'=>$inv,'source_sku'=>$sku,'source_outlet'=>'PRIVATE-ID','buyer_name'=>'PRIVATE-NAME','invoice_date'=>'2026-10-10','quantity'=>(string)$qty,'source_gross'=>(string)$gross,'source_reported_net'=>(string)$net,'_source_row'=>$row];
   foreach($footer as $key=>$value) $data['invoice_'.$key]=$value;$rows[]=['row'=>$row,'data'=>$data];
  }
  $header=['CCI Invoice No','Actual Supply Date','CCI SKU','Original Cases Qty','CCI Gross Rs','CCI Line Net Rs','Source row'];
  $extra=['Printed Retail Price','HS and Tax Verified','Litres per Case (if known)','Buyer Status'];
  $grids=['TAXNEST SALES LINES'=>[array_merge($header,$extra),['SALE','2026-10-10','S',1,100,95,16,null,'PENDING',null,'STATUS UNVERIFIED']],
   'EXCLUDED PACKAGING'=>[$header,['SALE','2026-10-10','EMPTY',-1,-10,-10,35]],'DAMAGE RETURNS REVIEW'=>[$header,['DAMAGE','2026-10-10','D',-1,-5,-5,230]],
   'SKU MASTER'=>[['CCI Product Code','HS/Tax Approval'],['S','REVIEW REQUIRED']],
   'TAXNEST INVOICE GATE'=>[['CCI Invoice No','Buyer Registration','MRP Confirmed','HS/Tax Confirmed','Source VAT Reviewed','Ready for TaxNest'],['SALE','PENDING','PENDING','PENDING','PENDING','HOLD']]];
  return [$rows,$grids];
 }
 public function test_coverage_money_and_missing_evidence_are_reported_without_private_identifiers(): void {
  [$rows,$grids]=$this->fixture();$a=(new CciPilotEvidenceAudit)->auditRows($rows,$grids);
  $this->assertSame('HOLD',$a['status']);$this->assertSame(3,$a['covered_source_lines']);$this->assertSame(0,$a['changed_source_values']);$this->assertSame(0,$a['footer_mismatch_count']);
  $this->assertSame(3,$a['source_lines_without_item_vat_amount']);$this->assertSame(1,$a['sales_lines_missing_printed_mrp']);$this->assertSame(8200,$a['footer_totals_cents']['net']);
  $this->assertStringNotContainsString('PRIVATE',json_encode($a));
 }
 public function test_changed_duplicate_and_missing_lines_are_detected(): void {
  [$rows,$grids]=$this->fixture();$grids['TAXNEST SALES LINES'][1][3]=9;$grids['EXCLUDED PACKAGING'][]=$grids['EXCLUDED PACKAGING'][1];array_pop($grids['DAMAGE RETURNS REVIEW']);
  $a=(new CciPilotEvidenceAudit)->auditRows($rows,$grids);$this->assertSame(1,$a['changed_source_values']);$this->assertSame(1,$a['unmatched_or_duplicate_evidence_lines']);$this->assertSame(1,$a['uncovered_source_lines']);$this->assertSame('HOLD',$a['status']);
 }
 public function test_changed_evidence_headers_and_conflicting_source_footers_are_refused(): void {
  [$rows,$grids]=$this->fixture();$rows[1]['data']['invoice_net']='89';$this->expectException(\InvalidArgumentException::class);(new CciPilotEvidenceAudit)->auditRows($rows,$grids);
 }
 public function test_checking_cells_never_authorizes_fiscal_submission(): void {
  [$rows,$grids]=$this->fixture();$grids['TAXNEST SALES LINES'][1][7]=125;$grids['TAXNEST SALES LINES'][1][8]='VERIFIED';$grids['TAXNEST SALES LINES'][1][9]=6;$grids['TAXNEST SALES LINES'][1][10]='REGISTERED VERIFIED';
  $a=(new CciPilotEvidenceAudit)->auditRows($rows,$grids);$this->assertSame(0,$a['sales_lines_missing_printed_mrp']);$this->assertSame('HOLD',$a['status']);
 }
 public function test_request_list_points_to_missing_cells_without_copying_buyer_or_invoice_identifiers(): void {
  [$rows,$grids]=$this->fixture();$audit=(new CciPilotEvidenceAudit)->auditRows($rows,$grids);
  $request=$audit['evidence_requests'][0];
  $this->assertSame('TAXNEST SALES LINES',$request['sheet']);$this->assertSame(2,$request['evidence_row']);$this->assertSame(16,$request['source_row']);
  $this->assertSame(['Printed Retail Price','HS and Tax Verified','Litres per Case (if known)','Buyer Status'],$request['required_fields']);
  $this->assertCount(3,$audit['evidence_requests']);
  $this->assertStringNotContainsString('PRIVATE',json_encode($audit['evidence_requests']));
  $this->assertStringNotContainsString('CCI Invoice No',json_encode($audit['evidence_requests']));
  $grids['TAXNEST SALES LINES'][1][7]=125;$grids['TAXNEST SALES LINES'][1][8]='VERIFIED';
  $grids['TAXNEST SALES LINES'][1][9]=6;$grids['TAXNEST SALES LINES'][1][10]='REGISTERED VERIFIED';
  $updated=(new CciPilotEvidenceAudit)->auditRows($rows,$grids);
  $this->assertCount(2,$updated['evidence_requests']);$this->assertSame('HOLD',$updated['status']);
  $this->assertSame($audit['footer_totals_cents'],$updated['footer_totals_cents']);
 }
}
