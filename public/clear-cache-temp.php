<?php
$base = dirname(__DIR__);
echo "<pre>";
if (function_exists('opcache_reset')) {
    opcache_reset();
    echo "OPcache CLEARED (web server)\n\n";
} else {
    echo "OPcache not available\n\n";
}
echo shell_exec("cd {$base} && php artisan optimize:clear 2>&1") . "\n";
echo shell_exec("cd {$base} && php artisan cache:clear 2>&1") . "\n";
echo shell_exec("cd {$base} && php artisan config:clear 2>&1") . "\n";
echo shell_exec("cd {$base} && php artisan view:clear 2>&1") . "\n";
echo shell_exec("cd {$base} && php artisan route:clear 2>&1") . "\n";
echo "\n=== ALL CACHES CLEARED (including OPcache) ===\n";
echo "Now hard refresh your browser (Ctrl+Shift+R)\n";
echo "</pre>";
