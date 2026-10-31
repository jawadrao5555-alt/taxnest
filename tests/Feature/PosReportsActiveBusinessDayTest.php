<?php
namespace Tests\Feature;
use App\Http\Controllers\PosController;
use App\Models\Company;
use App\Services\PosBusinessDay;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
class PosReportsActiveBusinessDayTest extends TestCase
{
    use RefreshDatabase;
    protected function tearDown(): void { Carbon::setTestNow(); parent::tearDown(); }
    private function company(string $cutoff): Company {
        return Company::create(['name'=>'Synthetic report day','ntn'=>(string) random_int(100000000,999999999),'email'=>uniqid('report-day-',true).'@test.invalid','product_type'=>'pos','status'=>'approved','company_status'=>'active','pos_business_day_cutoff'=>$cutoff]);
    }
    private function range(Company $company, array $query=[]): array {
        app()->instance('currentCompanyId',$company->id);PosBusinessDay::forgetCutoff($company->id);
        $method=new \ReflectionMethod(PosController::class,'resolveReportRange');
        return $method->invoke(new PosController(),Request::create('/pos/reports','GET',$query));
    }
    public function test_after_midnight_default_uses_each_company_cutoff_and_rolls_after_cutoff(): void {
        config(['app.timezone'=>'Asia/Karachi']);Carbon::setTestNow(Carbon::parse('2026-10-22 00:30:00','Asia/Karachi'));
        $night=$this->company('06:00');$calendar=$this->company('00:00');
        foreach($this->range($night) as $date)$this->assertSame('2026-10-21',$date->toDateString());
        foreach($this->range($calendar) as $date)$this->assertSame('2026-10-22',$date->toDateString());
        foreach($this->range($night) as $date)$this->assertSame('2026-10-21',$date->toDateString());
        Carbon::setTestNow(Carbon::parse('2026-10-22 06:01:00','Asia/Karachi'));
        foreach($this->range($night) as $date)$this->assertSame('2026-10-22',$date->toDateString());
        $this->assertSame('06:00',$night->fresh()->pos_business_day_cutoff);
    }
    public function test_exact_midnight_and_cutoff_boundaries_keep_the_same_reporting_business_date(): void {
        config(['app.timezone'=>'Asia/Karachi']);$company=$this->company('06:00');
        foreach(['2026-10-21 23:59:00'=>'2026-10-21','2026-10-22 00:01:00'=>'2026-10-21','2026-10-22 05:59:00'=>'2026-10-21','2026-10-22 06:00:00'=>'2026-10-22'] as $stamp=>$expected) {
            Carbon::setTestNow(Carbon::parse($stamp,'Asia/Karachi'));
            foreach($this->range($company) as $date)$this->assertSame($expected,$date->toDateString(),$stamp);
        }
        $this->assertSame('06:00',$company->fresh()->pos_business_day_cutoff);
    }
    public function test_already_closed_day_advances_without_changing_saved_cutoff(): void {
        config(['app.timezone'=>'Asia/Karachi']);Carbon::setTestNow(Carbon::parse('2026-10-22 00:30:00','Asia/Karachi'));$c=$this->company('06:00');
        DB::table('pos_day_close_reports')->insert(['company_id'=>$c->id,'report_date'=>'2026-10-21','report_number'=>'SYNTHETIC-DAY-1']);
        foreach($this->range($c) as $date)$this->assertSame('2026-10-22',$date->toDateString());
    }
    public function test_explicit_partial_reversed_and_capped_calendar_ranges_remain_unchanged(): void {
        config(['app.timezone'=>'Asia/Karachi']);Carbon::setTestNow(Carbon::parse('2026-10-22 00:30:00','Asia/Karachi'));$c=$this->company('06:00');
        [$from,$to]=$this->range($c,['from'=>'2024-01-03','to'=>'2024-01-02']);$this->assertSame('2024-01-02',$from->toDateString());$this->assertSame('2024-01-03',$to->toDateString());
        [$from,$to]=$this->range($c,['from'=>'2026-10-01']);$this->assertSame('2026-10-01',$from->toDateString());$this->assertSame('2026-10-22',$to->toDateString());
        [$from,$to]=$this->range($c,['from'=>'2020-01-01','to'=>'2026-10-22']);$this->assertLessThanOrEqual(366,$from->diffInDays($to->copy()->startOfDay()));
    }
}
