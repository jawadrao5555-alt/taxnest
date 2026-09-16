<?php
declare(strict_types=1);
$db=getenv('DB_DATABASE')?:''; $socket=getenv('DB_SOCKET')?:'';
if ($db !== 'taxnest_rc_migration' || !str_starts_with($socket,'/tmp/taxnest-rc-mariadb-')) { fwrite(STDERR,"FAIL unsafe target\n"); exit(2); }
$pdo=new PDO("mysql:unix_socket=$socket;dbname=$db",'root','',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$pdo->exec('CREATE TABLE IF NOT EXISTS rc_native_stock_lock (id BIGINT PRIMARY KEY, quantity INT NOT NULL) ENGINE=InnoDB');
$pdo->exec('DELETE FROM rc_native_stock_lock'); $pdo->exec('INSERT INTO rc_native_stock_lock VALUES (1,10)');
$worker=tempnam('/tmp','taxnest-rc-worker-').'.php';
file_put_contents($worker, <<<'PHP'
<?php $p=new PDO('mysql:unix_socket='.getenv('DB_SOCKET').';dbname='.getenv('DB_DATABASE'),'root','',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]); $p->beginTransaction(); $p->query('SELECT quantity FROM rc_native_stock_lock WHERE id=1 FOR UPDATE')->fetch(); usleep((int)$argv[1]); $p->exec('UPDATE rc_native_stock_lock SET quantity=quantity-1 WHERE id=1'); $p->commit();
PHP);
$env=['DB_SOCKET'=>$socket,'DB_DATABASE'=>$db,'PATH'=>getenv('PATH')?:'/usr/bin:/bin'];
$a=proc_open([PHP_BINARY,$worker,'900000'],[1=>['pipe','w'],2=>['pipe','w']],$pa,null,$env); usleep(200000);
$b=proc_open([PHP_BINARY,$worker,'0'],[1=>['pipe','w'],2=>['pipe','w']],$pb,null,$env);
foreach([$pa,$pb] as $pipes) { foreach($pipes as $pipe) fclose($pipe); }
if(proc_close($a)!==0 || proc_close($b)!==0) { @unlink($worker); fwrite(STDERR,"FAIL independent stock workers\n"); exit(1); }
@unlink($worker);
if((int)$pdo->query('SELECT quantity FROM rc_native_stock_lock WHERE id=1')->fetchColumn()!==8){fwrite(STDERR,"FAIL lock result\n");exit(1);}
$pdo->exec('CREATE TABLE IF NOT EXISTS rc_native_fiscal_claim (company_id BIGINT NOT NULL, fiscal_key VARCHAR(64) NOT NULL, PRIMARY KEY(company_id,fiscal_key)) ENGINE=InnoDB');
$pdo->exec("DELETE FROM rc_native_fiscal_claim WHERE company_id=1 AND fiscal_key='race'");
$claim=tempnam('/tmp','taxnest-rc-claim-').'.php'; file_put_contents($claim, <<<'PHP'
<?php $p=new PDO('mysql:unix_socket='.getenv('DB_SOCKET').';dbname='.getenv('DB_DATABASE'),'root','',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]); try{$p->exec("INSERT INTO rc_native_fiscal_claim VALUES(1,'race')");echo'claimed';}catch(PDOException){echo'duplicate';}
PHP);
$out=[]; foreach([1,2] as $_){$out[]=trim((string)shell_exec('DB_SOCKET='.escapeshellarg($socket).' DB_DATABASE='.escapeshellarg($db).' '.escapeshellarg(PHP_BINARY).' '.escapeshellarg($claim)));} @unlink($claim); sort($out);
if($out!==['claimed','duplicate']){fwrite(STDERR,"FAIL fiscal claim outcomes\n");exit(1);}
echo "PASS: independent InnoDB lock decremented 10->8; unique fiscal claim outcomes=claimed,duplicate\n";