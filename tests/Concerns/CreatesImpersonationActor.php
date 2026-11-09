<?php

namespace Tests\Concerns;

use App\Models\AdminUser;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

trait CreatesImpersonationActor
{
    protected function loginImpersonatingAdmin(): AdminUser
    {
        if (!Schema::hasTable('admin_users')) {
            Schema::create('admin_users', function (Blueprint $table) {
                $table->id(); $table->string('name'); $table->string('email')->unique();
                $table->string('password'); $table->string('role');
                $table->rememberToken(); $table->timestamps();
            });
        }
        $admin = AdminUser::firstOrCreate(['id' => 1], ['name' => 'Synthetic operator',
            'email' => 'operator@impersonation.invalid', 'password' => 'SyntheticOnly123!', 'role' => 'super_admin']);
        auth('admin')->setUser($admin);
        return $admin;
    }
}
