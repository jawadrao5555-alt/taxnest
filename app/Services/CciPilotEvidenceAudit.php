<?php
namespace App\Services;
use PhpOffice\PhpSpreadsheet\IOFactory;
/** Offline evidence inventory. Never authorizes an import or sends fiscal data. */
final class CciPilotEvidenceAudit {
 public function audit(string $source,string $evidence): array {
  foreach([$source,$evidence] as $path) if(!is_file($path) || filesize($path)>20*1024*1024 || strtolower(pathinfo($path,PATHINFO_EXTENSION))!=='xlsx') throw new \InvalidArgumentException('Two local XLSX files under 20 MiB are required.');
  $parsed=(new CciVoyageInvoiceParser)->parse($source);
  if(!$parsed || isset($parsed['error'])) throw new \InvalidArgumentException($parsed['error']??'Source is not the recognized detailed CCI Voyage format.');
  $reader=IOFactory::createReaderForFile($evidence);$reader->setReadDataOnly(true);
  if(array_sum(array_column($reader->listWorksheetInfo($evidence),'totalRows'))>InvoiceImportService::MAX_ROWS) throw new \InvalidArgumentException('Evidence workbook exceeds row limit.');
  $book=$reader->load($evidence);$grids=[];
  try {foreach($book->getAllSheets() as $sheet) $grids[$sheet->getTitle()]=$sheet->toArray(null,true,false,false);} finally {$book->disconnectWorksheets();}
  return $this->auditRows($parsed['rows'],$grids)+['source_sha256'=>hash_file('sha256',$source),'evidence_sha256'=>hash_file('sha256',$evidence)];
 }
 public function auditRows(array $rows,array $grids): array {
  $invoices=[];$skus=[];$buyers=[];$dates=[];$negativeQuantity=0;$lineVatMissing=0;$lines=[];
  foreach($rows as $entry) {
   $d=$entry['data'];$id=$d['source_invoice_number'];$skus[$d['source_sku']]=true;$buyers[$d['source_outlet']]=true;$dates[$d['invoice_date']]=true;
   if((float)$d['quantity']<0) $negativeQuantity++;
   if(trim((string)($d['source_vat']??''))==='') $lineVatMissing++;
   $footer=[];foreach(['gross','discount','vat','net'] as $field) $footer[$field]=InvoiceImportMoney::cents($d['invoice_'.$field]);
   if(isset($invoices[$id]) && $invoices[$id]!==$footer) throw new \InvalidArgumentException('Source invoice footer conflicts; pilot stays held.');
   $invoices[$id]=$footer;$lines[$id.'|'.$d['source_sku'].'|'.($d['_source_row']??$entry['row'])]=$d;
  }
  $totals=['gross'=>0,'discount'=>0,'vat'=>0,'net'=>0];$footerMismatch=0;
  foreach($invoices as $footer){foreach($totals as $field=>$_) $totals[$field]+=$footer[$field];if($footer['gross']-$footer['discount']+$footer['vat']!==$footer['net']) $footerMismatch++;}
  $unmatched=0;$changed=0;$seen=[];$missingMrp=0;$pendingHs=0;$missingConversion=0;$missingBuyer=0;$salesCount=0;$partition=[];$gateHold=0;$gateRows=0;$pendingVat=0;$requests=[];
  foreach(['TAXNEST SALES LINES','EXCLUDED PACKAGING','DAMAGE RETURNS REVIEW'] as $sheet) {
   $table=$this->table($grids,$sheet,['CCI Invoice No','Actual Supply Date','CCI SKU','Original Cases Qty','CCI Gross Rs','CCI Line Net Rs','Source row']);
   $partition[$sheet]=count($table);
   foreach($table as $index=>$e) {
    $key=$e['CCI Invoice No'].'|'.$e['CCI SKU'].'|'.$e['Source row'];
    if(!isset($lines[$key]) || isset($seen[$key])) {$unmatched++;continue;} $seen[$key]=true;$d=$lines[$key];
    if($d['invoice_date']!==$e['Actual Supply Date'] || abs((float)$d['quantity']-(float)$e['Original Cases Qty'])>0.0000001
     || InvoiceImportMoney::cents($d['source_gross'])!==InvoiceImportMoney::cents($e['CCI Gross Rs'])
     || InvoiceImportMoney::cents($d['source_reported_net'])!==InvoiceImportMoney::cents($e['CCI Line Net Rs'])) $changed++;
    if($sheet==='TAXNEST SALES LINES') {
     $salesCount++;$missing=[];
     if(!is_numeric($e['Printed Retail Price']??null) || (float)$e['Printed Retail Price']<=0) {$missingMrp++;$missing[]='Printed Retail Price';}
     if(strtoupper(trim((string)($e['HS and Tax Verified']??'')))!=='VERIFIED') {$pendingHs++;$missing[]='HS and Tax Verified';}
     if(!is_numeric($e['Litres per Case (if known)']??null) || (float)$e['Litres per Case (if known)']<=0) {$missingConversion++;$missing[]='Litres per Case (if known)';}
     if(!in_array(strtoupper(trim((string)($e['Buyer Status']??''))),['REGISTERED VERIFIED','UNREGISTERED VERIFIED'],true)) {$missingBuyer++;$missing[]='Buyer Status';}
     if($missing) $requests[]=['sheet'=>$sheet,'evidence_row'=>$index+2,'source_row'=>(int)$e['Source row'],'required_fields'=>$missing];
    }
   }
  }
  foreach($this->table($grids,'TAXNEST INVOICE GATE',['CCI Invoice No','Buyer Registration','MRP Confirmed','HS/Tax Confirmed','Source VAT Reviewed','Ready for TaxNest']) as $index=>$gate) {
   $gateRows++;if(str_starts_with(strtoupper((string)$gate['Ready for TaxNest']),'HOLD')) $gateHold++;
   if(strtoupper(trim((string)$gate['Source VAT Reviewed']))!=='VERIFIED') {$pendingVat++;$requests[]=['sheet'=>'TAXNEST INVOICE GATE','evidence_row'=>$index+2,'required_fields'=>['Source VAT Reviewed']];}
  }
  $skuRows=$this->table($grids,'SKU MASTER',['CCI Product Code','HS/Tax Approval']);$skuPending=0;
  foreach($skuRows as $index=>$sku) if(strtoupper(trim((string)$sku['HS/Tax Approval']))!=='VERIFIED') {$skuPending++;$requests[]=['sheet'=>'SKU MASTER','evidence_row'=>$index+2,'required_fields'=>['HS/Tax Approval']];}
  return ['status'=>'HOLD','authorization'=>'Evidence inventory only; verified company mappings and reviewed batch approval remain mandatory.',
   'source_invoices'=>count($invoices),'source_lines'=>count($rows),'source_skus'=>count($skus),'source_outlets'=>count($buyers),'supply_dates'=>array_keys($dates),
   'staging_partition_counts'=>$partition,'source_negative_quantity_lines'=>$negativeQuantity,'footer_totals_cents'=>$totals,'footer_mismatch_count'=>$footerMismatch,
   'covered_source_lines'=>count($seen),'uncovered_source_lines'=>count($lines)-count($seen),'unmatched_or_duplicate_evidence_lines'=>$unmatched,'changed_source_values'=>$changed,
   'beverage_sales_lines'=>$salesCount,'sales_lines_missing_printed_mrp'=>$missingMrp,'sales_lines_without_verified_hs_tax'=>$pendingHs,
   'sales_lines_missing_case_conversion'=>$missingConversion,'sales_lines_without_verified_buyer'=>$missingBuyer,'source_lines_without_item_vat_amount'=>$lineVatMissing,
   'sku_mapping_rows'=>count($skuRows),'sku_mappings_without_verified_hs_tax'=>$skuPending,'invoice_gate_rows'=>$gateRows,'invoice_gate_hold_count'=>$gateHold,'invoice_gates_without_verified_vat'=>$pendingVat,'evidence_requests'=>$requests];
 }
 private function table(array $grids,string $name,array $required): array {
  if(empty($grids[$name])) throw new \InvalidArgumentException('Missing evidence sheet: '.$name);
  $grid=$grids[$name];$header=array_map(fn($v)=>trim((string)$v),array_shift($grid));
  if(count($header)!==count(array_unique($header))) throw new \InvalidArgumentException('Duplicate evidence headers require review: '.$name);
  foreach($required as $field) if(!in_array($field,$header,true)) throw new \InvalidArgumentException('Evidence column requires review: '.$name.' / '.$field);
  return array_map(fn($row)=>array_combine($header,array_slice(array_pad($row,count($header),null),0,count($header))),array_filter($grid,fn($row)=>count(array_filter($row,fn($v)=>$v!==null&&$v!==''))>0));
 }
}
