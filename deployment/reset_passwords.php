<?php
require __DIR__.'/../vendor/autoload.php';
$app = require_once __DIR__.'/../bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

$password = 'Admin@12345';
$hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);

// Reset all user passwords
$users = \App\Models\User::all();
foreach ($users as $user) {
    $user->password = $hash;
    $user->save();
    echo "Reset: {$user->email}" . PHP_EOL;
}

// Reset admin passwords
$admins = \App\Models\AdminUser::all();
foreach ($admins as $admin) {
    $admin->password = $hash;
    $admin->save();
    echo "Reset Admin: {$admin->email}" . PHP_EOL;
}

echo PHP_EOL . "All passwords reset to: Admin@12345" . PHP_EOL;
echo "Hash: {$hash}" . PHP_EOL;
