<?php

namespace Tests\Unit;

use App\Services\PosReportPresentation;
use PHPUnit\Framework\TestCase;

class PosReportPresentationTest extends TestCase
{
    private function group(string $stream, string $payment, float $amount): array
    {
        return ['stream'=>$stream,'stage'=>'final','payment_method'=>$payment,
            'totals'=>['sales'=>1,'net'=>$amount,'tax'=>0,'returned'=>0,'delivery'=>10,'order_types'=>['delivery'=>['net'=>$amount,'tax'=>0]]],
            'products'=>[['product_id'=>1,'name'=>'Frozen product','category'=>'Frozen category','size'=>'','channel'=>'standalone','basis'=>'frozen','qty'=>1,'base'=>$amount-10,'tax'=>0,'net'=>$amount-10]]];
    }

    public function test_frozen_products_consolidate_across_payments_and_delivery_reconciles_once(): void
    {
        $frozen=[$this->group('pra','cash',110),$this->group('pra','card',210),$this->group('local','cash',900)];
        $before=json_encode($frozen);
        $r=PosReportPresentation::fromFrozen($frozen,['pra']);
        $this->assertSame(320.0,$r['total']);
        $this->assertSame(20.0,$r['delivery']);
        $this->assertEquals(320,array_sum(array_column($r['payments'],'net')));
        $this->assertEquals(320,array_sum(array_column($r['order_types'],'net')));
        $this->assertCount(1,$r['products']);
        $this->assertSame(2.0,$r['products'][0]['qty']);
        $this->assertSame(300.0,$r['products'][0]['net']);
        $this->assertSame($before,json_encode($frozen));
        $this->assertArrayNotHasKey('local',$r['streams']);
    }

    public function test_old_delivery_unknown_is_not_invented_and_provisional_is_separate(): void
    {
        $legacy=$this->group('local','cash',110);unset($legacy['totals']['delivery'],$legacy['totals']['order_types']);
        $proof=$this->group('local','cash',250);$proof['stage']='provisional';
        $r=PosReportPresentation::fromFrozen([$legacy,$proof],['local']);
        $this->assertNull($r['delivery']);
        $this->assertSame(110.0,$r['total']);
        $this->assertSame(250.0,$r['provisional']);
        $this->assertEquals(110,$r['order_types']['unknown']['net']);
        $this->assertCount(1,$r['products']);
    }
}
