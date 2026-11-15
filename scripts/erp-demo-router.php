<?php
declare(strict_types=1);
$fixture=(string)getenv('ERP_DEMO_FIXTURE');
if (getenv('RC_SAFE_RUN') !== '1' || !preg_match('#^/tmp/taxnest-erp-demo-[0-9]+-[A-Za-z0-9]+/safe-runtime/browser-state/fixture\.json$#',$fixture)
    || !is_file($fixture) || is_link($fixture) || realpath($fixture)!==$fixture) { http_response_code(503); exit; }
if (parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH)==='/.rc-synthetic-proof.json') {
    $bytes=file_get_contents($fixture);$data=json_decode($bytes,true);
    if (($data['fixture_kind']??null)!=='hospital-erp-demo'||($data['database']??null)!=='taxnest_erp_demo'||($data['synthetic']??false)!==true) {http_response_code(503);exit;}
    header('Content-Type: application/json');header('Cache-Control: no-store');
    echo json_encode(['version'=>1,'synthetic'=>true,'fixture_kind'=>'hospital-erp-demo','database'=>'taxnest_erp_demo','nonce'=>$data['pixel_proof_nonce'],'fixture_sha256'=>hash('sha256',$bytes)]);exit;
}
$public=realpath(dirname(__DIR__).'/public');
$path=realpath($public.'/'.ltrim(rawurldecode((string)parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH)),'/'));
if ($path && str_starts_with($path,$public.'/') && is_file($path)) return false;
require $public.'/index.php';
