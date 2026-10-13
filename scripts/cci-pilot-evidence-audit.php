<?php
// Invoke through scripts/rc-safe-run; strictly offline, no application bootstrap / credentials / DB.
if(PHP_SAPI!=='cli' || getenv('RC_SAFE_RUN')!=='1') {fwrite(STDERR,"Use scripts/rc-safe-run for this local evidence audit.\n");exit(2);}
require dirname(__DIR__).'/vendor/autoload.php';
$options=getopt('',['source:','evidence:']);
try {
 if(empty($options['source']) || empty($options['evidence'])) throw new InvalidArgumentException('Supply --source and --evidence local XLSX paths.');
 $audit=(new \App\Services\CciPilotEvidenceAudit)->audit($options['source'],$options['evidence']);
 echo json_encode($audit,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)."\n";
} catch(Throwable $e) {fwrite(STDERR,"Pilot remains HOLD: ".$e->getMessage()."\n");exit(2);}
