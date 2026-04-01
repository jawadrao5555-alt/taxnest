<?php
echo "<pre style='font-family:monospace;font-size:14px;padding:20px;'>";
echo "=== TAXNEST DEPLOYMENT DEBUG ===\n\n";

$base = dirname(__DIR__);

echo "1. GIT COMMIT:\n";
echo shell_exec("cd {$base} && git log --oneline -3 2>&1");
echo "\n";

echo "2. PHP VERSION: " . PHP_VERSION . "\n\n";

echo "3. OPCACHE STATUS:\n";
if (function_exists('opcache_get_status')) {
    $status = opcache_get_status(false);
    echo "   Enabled: " . ($status['opcache_enabled'] ? 'YES' : 'NO') . "\n";
    echo "   Cached scripts: " . ($status['opcache_statistics']['num_cached_scripts'] ?? 'N/A') . "\n";
    echo "   Revalidate freq: " . ini_get('opcache.revalidate_freq') . " sec\n";
} else {
    echo "   OPcache not available\n";
}
echo "\n";

echo "4. PosAuthController LOGIN REDIRECT (line ~93-97):\n";
$file = $base . '/app/Http/Controllers/PosAuthController.php';
if (file_exists($file)) {
    $lines = file($file);
    for ($i = 85; $i < min(100, count($lines)); $i++) {
        echo "   L" . ($i+1) . ": " . $lines[$i];
    }
} else {
    echo "   FILE NOT FOUND!\n";
}
echo "\n";

echo "5. STYLE PICKER (first 5 lines):\n";
$file2 = $base . '/resources/views/pos/dashboard-styles/_style-picker.blade.php';
if (file_exists($file2)) {
    $lines2 = file($file2);
    for ($i = 0; $i < min(5, count($lines2)); $i++) {
        echo "   L" . ($i+1) . ": " . $lines2[$i];
    }
} else {
    echo "   FILE NOT FOUND!\n";
}
echo "\n";

echo "6. INDEX.PHP (first 15 lines):\n";
$file3 = $base . '/public/index.php';
if (file_exists($file3)) {
    $lines3 = file($file3);
    for ($i = 0; $i < min(15, count($lines3)); $i++) {
        echo "   L" . ($i+1) . ": " . $lines3[$i];
    }
} else {
    echo "   FILE NOT FOUND!\n";
}

echo "\n=== END DEBUG ===\n";
echo "</pre>";
