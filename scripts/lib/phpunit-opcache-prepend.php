<?php
// Content-free runtime verification; no application data or test parameters logged.
$runtime = getenv('TAXNEST_PHPUNIT_OPCACHE_RUNTIME');
$check = static function (bool $ok, string $message): void {
    if (! $ok) {
        fwrite(STDERR, 'FAIL: PHPUnit OPcache runtime '.$message.PHP_EOL);
        exit(2);
    }
};
$check(is_string($runtime) && is_dir($runtime), 'missing disposable runtime');
$check(function_exists('opcache_get_status') && (bool) ini_get('opcache.enable_cli'), 'official CLI extension unavailable');
$check(ini_get('memory_limit') === '1G' && (int) ini_get('opcache.memory_consumption') === 128, 'memory budgets differ');
$check((bool) ini_get('opcache.validate_timestamps') && (int) ini_get('opcache.revalidate_freq') === 0 && (int) ini_get('opcache.jit_buffer_size') === 0, 'freshness/JIT configuration differs');
$cached = $runtime.'/cached.php';
$dynamic = $runtime.'/dynamic.php';
file_put_contents($cached, '<?php return static fn () => 1;');
touch($cached, time() - 10);
clearstatcache(true, $cached);
$check(opcache_compile_file($cached), 'cannot compile synthetic canary');
$before = memory_get_usage(false);
for ($i = 0; $i < 10000; $i++) {
    $closure = require $cached;
    $check($closure() === 1, 'cached canary value differs');
    unset($closure);
}
gc_collect_cycles();
$check(memory_get_usage(false) - $before < 262144, 'repeated source compilation retains memory');
// Cached source with a real modification timestamp must be revalidated.
file_put_contents($cached, '<?php return static fn () => 2;');
touch($cached, time() - 5);
clearstatcache(true, $cached);
$closure = require $cached;
$check($closure() === 2, 'cached source timestamp change was not observed');
unset($closure);
// Generated PHP can change within the SAME second: it must NEVER be cached.
$stamp = time() - 10;
foreach ([1, 2] as $value) {
    file_put_contents($dynamic, '<?php return '.$value.';');
    touch($dynamic, $stamp);
    clearstatcache(true, $dynamic);
    $check((require $dynamic) === $value && ! opcache_is_script_cached($dynamic), 'same-timestamp dynamic source is stale');
}
fwrite(STDERR, "PASS: PHPUnit runtime cache bounds and source freshness; heap 1GiB + opcode cache 128MiB; JIT off\n");
register_shutdown_function(static function (): void {
    $status = opcache_get_status(false);
    fwrite(STDERR, '[phpunit-runtime] '.json_encode([
        'heap_peak_bytes' => memory_get_peak_usage(true),
        'opcode_reserved_bytes' => 134217728,
        'opcode_used_bytes' => $status['memory_usage']['used_memory'] ?? null,
        'cache_full' => $status['cache_full'] ?? null,
    ], JSON_THROW_ON_ERROR).PHP_EOL);
});
unset($runtime, $check, $cached, $dynamic, $before, $stamp, $value, $i);
