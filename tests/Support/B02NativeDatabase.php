<?php
namespace Tests\Support;

use Illuminate\Support\Facades\DB;

/** Only the disposable supplemental lab may opt these fixtures into native MariaDB. */
class B02NativeDatabase
{
    public static function configure(): bool
    {
        if (getenv('TAXNEST_REQUIRE_B02_NATIVE') !== '1') return false;
        $db = getenv('TAXNEST_MARIADB_TEST_DATABASE') ?: '';
        $host = getenv('TAXNEST_MARIADB_TEST_HOST') ?: '';
        if (!preg_match('/^taxnest_rc_[a-z0-9_]+_probe$/', $db) || $host !== '127.0.0.1'
            || !str_contains(getenv('LD_PRELOAD') ?: '', 'guard') || file_exists(base_path('.env'))) {
            throw new \RuntimeException('Unsafe B02 native target.');
        }
        config(['database.default'=>'b02_native','database.connections.b02_native'=>[
            'driver'=>'mysql','host'=>$host,'port'=>getenv('TAXNEST_MARIADB_TEST_PORT'),
            'database'=>$db,'username'=>getenv('TAXNEST_MARIADB_TEST_USERNAME'),
            'password'=>getenv('TAXNEST_MARIADB_TEST_PASSWORD'),'charset'=>'utf8mb4','collation'=>'utf8mb4_unicode_ci',
            'prefix'=>'','strict'=>true,'engine'=>'InnoDB',
        ],'cache.default'=>'array','session.driver'=>'array']);
        DB::purge('b02_native');
        return true;
    }

    /** Both independent application workers arrive before the tenant lock is released. */
    public static function race(int $companyId, array $commands): array
    {
        if (DB::getDriverName() !== 'mysql') throw new \RuntimeException('Native database required.');
        $directory = sys_get_temp_dir().'/taxnest-b02-race-'.bin2hex(random_bytes(8));
        mkdir($directory,0700);$workers=[];
        DB::beginTransaction();
        DB::table('companies')->where('id',$companyId)->lockForUpdate()->first();
        try {
            foreach ($commands as $i=>$command) {
                $ready=$directory.'/'.$i.'.ready';$out=$directory.'/'.$i.'.out';$err=$directory.'/'.$i.'.err';
                $process=proc_open([PHP_BINARY,base_path('tests/native/b02-worker.php'),json_encode($command),$ready],
                    [0=>['pipe','r'],1=>['file',$out,'w'],2=>['file',$err,'w']],$pipes,base_path());
                if(!is_resource($process)) throw new \RuntimeException('Worker did not start.');
                fclose($pipes[0]);$workers[]=[$process,$ready,$out,$err];
            }
            $deadline=microtime(true)+15;
            do { $arrived=count(array_filter($workers,fn($w)=>file_exists($w[1])));if($arrived===count($workers))break;usleep(10000); } while(microtime(true)<$deadline);
            if($arrived!==count($workers))throw new \RuntimeException('Workers did not reach the barrier.');
            DB::commit();
            $results=[];
            foreach($workers as [$process,$ready,$out,$err]) {
                $deadline=microtime(true)+20;
                do {$status=proc_get_status($process);if(!$status['running'])break;usleep(10000);}while(microtime(true)<$deadline);
                if($status['running']) {proc_terminate($process);throw new \RuntimeException('Native race timed out.');}
                $exit=$status['exitcode'];proc_close($process);
                if($exit!==0)throw new \RuntimeException('Native worker failed: '.file_get_contents($err));
                $results[]=json_decode(file_get_contents($out),true,512,JSON_THROW_ON_ERROR);
            }
            return $results;
        } finally {
            if(DB::transactionLevel())DB::rollBack();
            foreach($workers as [$process]) if(is_resource($process)){proc_terminate($process);proc_close($process);}
            foreach(glob($directory.'/*') as $file)unlink($file);rmdir($directory);
        }
    }
}
