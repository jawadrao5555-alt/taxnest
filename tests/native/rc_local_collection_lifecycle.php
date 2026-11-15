<?php
declare(strict_types=1);
$db=getenv('DB_DATABASE')?:''; $socket=getenv('DB_SOCKET')?:'';
if (!preg_match('/^taxnest_rc_[a-z0-9_]+_full$/',$db) || !preg_match('#^/tmp/taxnest-rc-mariadb-[a-z0-9_]+/run/mariadb.sock$#',$socket) || getenv('APP_ENV')!=='rc-mariadb' || !getenv('LD_PRELOAD')) { fwrite(STDERR,"FAIL unsafe local lifecycle target\n");exit(2); }
require dirname(__DIR__,2).'/vendor/autoload.php';$app=require dirname(__DIR__,2).'/bootstrap/app.php';$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use App\Models\{Company,AdminUser};
use App\Services\{CompanyDeletionLifecycle,LocalCollectionLifecycle};
use Illuminate\Support\Facades\{DB,Http};
use Illuminate\Support\Str;
Http::preventStrayRequests();
$suffix=bin2hex(random_bytes(6));
$admin=AdminUser::create(['name'=>'Synthetic local lifecycle admin','email'=>'local-life-'.$suffix.'@native.invalid','password'=>bcrypt('SyntheticOnly123!'),'role'=>'super_admin']);
$target=Company::create(['name'=>'Synthetic local lifecycle target','ntn'=>'LC'.$suffix,'product_type'=>'fbrpos','pos_type'=>'education','fbr_reporting_enabled'=>false]);
$other=Company::create(['name'=>'Synthetic local lifecycle control','ntn'=>'LO'.$suffix,'product_type'=>'fbrpos','pos_type'=>'clinic','fbr_reporting_enabled'=>false]);
$seed=function(Company $company):void {
 $base=['company_id'=>$company->id,'created_at'=>now(),'updated_at'=>now()];
 // Seed the exact tenant-bound retained facts too: purge assertions must cover
 // real evidence rows, not silently accept zero for newly retained tables.
 DB::table('prospective_pos_quota_retention')->insert(['company_id'=>$company->id,'transaction_id'=>1,'sold_at'=>now(),'business_date'=>today()->toDateString(),'quota_counted'=>true,'aggregate_final'=>true,'source'=>'single-delete','source_id'=>1,'captured_at'=>now()]);
 DB::table('prospective_fbr_quota_retention')->insert(['company_id'=>$company->id,'transaction_id'=>1,'sold_at'=>now(),'quota_counted'=>true,'source'=>'single-delete','captured_at'=>now()]);
 $account=DB::table('school_fee_accounts')->insertGetId($base+['branch_id'=>null,'student_reference'=>'SYN-LIFE','fee_period'=>now()->format('Y-m'),'fee_lines'=>'[]','payable_paisa'=>20000,'paid_paisa'=>10000]);
 DB::table('school_fee_receipts')->insert($base+['branch_id'=>null,'account_id'=>$account,'collected_by'=>1,'request_key'=>(string)Str::uuid(),'student_reference'=>'SYN-LIFE','fee_head'=>'tuition','amount_paisa'=>10000,'payment_method'=>'cash','balance_paisa'=>10000,'collected_at'=>now()]);
 DB::table('school_fee_heads')->insert($base+['code'=>'life','label'=>'Synthetic fee','active'=>true]);
 DB::table('school_fee_day_closes')->insert($base+['branch_key'=>0,'report_date'=>today()->toDateString(),'receipt_count'=>1,'cash_amount_paisa'=>10000,'counted_cash_paisa'=>10000,'variance_paisa'=>0,'closed_by'=>1,'closed_at'=>now()]);
 $invoice=DB::table('hospital_invoices')->insertGetId($base+['branch_id'=>null,'request_key'=>(string)Str::uuid(),'patient_reference'=>'SYN-LIFE','service_head'=>'consultation','total_paisa'=>20000,'created_by'=>1,'campus_name'=>'Synthetic']);
 DB::table('hospital_payments')->insert($base+['branch_id'=>null,'invoice_id'=>$invoice,'request_key'=>(string)Str::uuid(),'amount_paisa'=>10000,'balance_paisa'=>10000,'payment_method'=>'bank','collected_by'=>1,'cashier_name'=>'Synthetic','campus_name'=>'Synthetic','collected_at'=>now()]);
 DB::table('hospital_services')->insert($base+['code'=>'life','label'=>'Synthetic service','service_head'=>'consultation','rate_paisa'=>20000,'active'=>true]);
 DB::table('hospital_day_closes')->insert($base+['branch_key'=>0,'report_date'=>today()->toDateString(),'receipt_count'=>1,'cash_paisa'=>0,'card_paisa'=>0,'bank_paisa'=>10000,'counted_cash_paisa'=>0,'variance_paisa'=>0,'closed_by'=>1,'cashier_name'=>'Synthetic','campus_name'=>'Synthetic','closed_at'=>now()]);
};
$seed($target);$seed($other);
$snapshot=function(int $id):array{$rows=[];foreach(LocalCollectionLifecycle::TABLES as $table)$rows[$table]=DB::table($table)->where('company_id',$id)->orderBy('id')->get()->toJson();return $rows;};
$before=$snapshot($target->id);$control=$snapshot($other->id);$lifecycle=app(CompanyDeletionLifecycle::class);
DB::transaction(function()use($lifecycle,$target,$admin){$lifecycle->beginHold($target,$admin,'Synthetic lifecycle acceptance');$target->delete();});
if($before!==$snapshot($target->id))throw new RuntimeException('Bin changed local financial snapshots');
DB::transaction(function()use($lifecycle,$target,$admin){$target->restore();$lifecycle->restored($target,$admin);});
if($before!==$snapshot($target->id))throw new RuntimeException('Restore changed local financial snapshots');
// Probe the audited purge hook inside a deliberately rolled-back transaction.
try { DB::transaction(function()use($lifecycle,$target,$admin){$lifecycle->purged($target,$admin);throw new RuntimeException('SYNTHETIC_ROLLBACK');}); }
catch(RuntimeException $e){if($e->getMessage()!=='SYNTHETIC_ROLLBACK')throw $e;}
if($before!==$snapshot($target->id)||$control!==$snapshot($other->id))throw new RuntimeException('Rollback failed to preserve both tenants');
$requestId=DB::transaction(fn()=>$lifecycle->purged($target,$admin));
foreach(LocalCollectionLifecycle::TABLES as $table)if(DB::table($table)->where('company_id',$target->id)->exists())throw new RuntimeException('Purge left local orphan '.$table);
if($control!==$snapshot($other->id))throw new RuntimeException('Purge affected control tenant');
$event=DB::table('company_deletion_request_events')->where('request_id',$requestId)->where('event','database_purge_committed')->first();
$counts=json_decode($event->metadata??'{}',true)['local_collection_rows_purged']??[];
foreach(LocalCollectionLifecycle::TABLES as $table)if(($counts[$table]??null)!==1)throw new RuntimeException('Missing exact purge evidence '.$table);
echo "PASS: native local School/Hospital bin and restore preserve exact snapshots; purge rollback restores all rows and audited purge removes target only\n";
