<?php
declare(strict_types=1);
// Disposable fixture attestation only. Never run against a configured application database.
require dirname(__DIR__).'/vendor/autoload.php';
$app=require dirname(__DIR__).'/bootstrap/app.php';$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$fail=static function():never{fwrite(STDERR,"Synthetic pixel marker refused outside guarded disposable fixture.\n");exit(2);};
$fixture=(string)getenv('RC_BROWSER_FIXTURE_OUT');$socket=(string)getenv('DB_SOCKET');
if(PHP_SAPI!=='cli'||!preg_match('#^/tmp/taxnest-rc-browser-[0-9]+(?:-[A-Za-z0-9_.-]+)?/safe-runtime/browser-state/fixture\.json$#',$fixture)
 || !is_file($fixture)||is_link($fixture)||realpath($fixture)!==$fixture||filesize($fixture)>4194304||(stat($fixture)['nlink']??0)!==1
 || !preg_match('#^/tmp/taxnest-rc-mariadb-browser-[0-9]+/run/mariadb\.sock$#',$socket)||@filetype($socket)!=='socket'||is_link($socket)
 || Illuminate\Support\Facades\DB::connection()->getDriverName()!=='mysql'||Illuminate\Support\Facades\DB::connection()->getDatabaseName()!=='taxnest_rc_browser'
 || config('database.connections.mysql.host')!=='127.0.0.1'||config('database.connections.mysql.unix_socket')!==$socket)$fail();
$f=json_decode(file_get_contents($fixture),true,512,JSON_THROW_ON_ERROR);
if(($f['synthetic']??null)!==true||!is_array($f['readOnlyJourneys']??null)||!is_array($f['transactionalJourneys']??null))$fail();
$f['fixture_kind']='standalone-browser';$f['database']='taxnest_rc_browser';$f['socket']=$socket;
$f['pixel_proof_nonce']=bin2hex(random_bytes(32));
$bytes=json_encode($f,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n";
$marker=public_path('.rc-synthetic-proof.json');
if(is_link($marker)||(file_exists($marker)&&(!is_file($marker)||(stat($marker)['nlink']??0)!==1))||realpath(dirname($marker))!==dirname($marker))$fail();
file_put_contents($fixture,$bytes,LOCK_EX)!==false||$fail();chmod($fixture,0600);
file_put_contents($marker,json_encode(['version'=>1,'synthetic'=>true,'fixture_kind'=>'standalone-browser','database'=>'taxnest_rc_browser','nonce'=>$f['pixel_proof_nonce'],'fixture_sha256'=>hash('sha256',$bytes)],JSON_THROW_ON_ERROR),LOCK_EX)!==false||$fail();chmod($marker,0600);
fwrite(STDOUT,"Guarded synthetic pixel marker ready.\n");
