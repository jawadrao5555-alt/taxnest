<?php
namespace Tests\Unit;
use App\Support\DiReadPresentation;
use PHPUnit\Framework\TestCase;
final class DiReadPresentationTest extends TestCase
{
 public function test_only_reviewed_aggregate_fields_are_presented_with_exact_money_precision():void {
  $rows=DiReadPresentation::rows(['values'=>['completed_documents'=>1,'sales_total'=>12.34,'exempt_net_item_tax'=>0,'unknown_tax_unknown_rows'=>null,'buyer_name'=>'Private buyer','secret'=>'value','<script>'=>1]]);
  $this->assertCount(4,$rows);$this->assertSame('12.34',$rows[1]['value']);$this->assertTrue($rows[1]['money']);$this->assertSame('0.00',$rows[2]['value']);$this->assertNull($rows[3]['value']);
  $this->assertSame('exempt',$rows[2]['classification']);$this->assertStringNotContainsString('Private buyer',json_encode($rows));
 }
 public function test_missing_or_non_numeric_values_are_unknown_not_zero():void {
  $this->assertSame([],DiReadPresentation::rows([]));$r=DiReadPresentation::rows(['values'=>['net_tax'=>'<script>','net_billing'=>null]]);$this->assertNull($r[0]['value']);$this->assertNull($r[1]['value']);
 }
 public function test_english_urdu_and_roman_urdu_cover_all_semantic_labels():void {
  $root=dirname(__DIR__,2);$en=require $root.'/lang/en/madadgar_di.php';
  foreach(['ur','rur'] as $locale){$labels=require $root.'/lang/'.$locale.'/madadgar_di.php';
   $this->assertSame([],array_diff(array_keys($en),array_keys($labels)),$locale.' missing labels');
   foreach($en as $key=>$value)$this->assertNotSame('',trim($labels[$key]),$locale.'.'.$key);
   foreach(['unknown_value','unavailable','source','date','range','read_at','sales_total','net_item_tax'] as $key)$this->assertNotSame($en[$key],$labels[$key],$locale.'.'.$key.' should be translated');
  }
 }
}
