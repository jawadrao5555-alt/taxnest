<?php
$base = dirname(__DIR__);
echo "<pre>";
echo shell_exec("cd {$base} && php artisan cache:clear 2>&1") . "\n";
echo shell_exec("cd {$base} && php artisan config:clear 2>&1") . "\n";
echo shell_exec("cd {$base} && php artisan view:clear 2>&1") . "\n";
echo shell_exec("cd {$base} && php artisan route:clear 2>&1") . "\n";
echo "DONE - Cache cleared! Now delete this file.";
echo "</pre>";
