<?php

namespace Tests\Feature;

use App\Models\AdminUser;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class AdminLoginThrottleTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Schema::dropAllTables();
        Schema::create('admin_users', function (Blueprint $table) {
            $table->id(); $table->string('name'); $table->string('email')->unique();
            $table->string('password'); $table->string('role');
            $table->rememberToken(); $table->timestamps();
        });
        Schema::create('security_logs', function (Blueprint $table) {
            $table->id(); $table->unsignedBigInteger('user_id')->nullable();
            $table->string('action'); $table->string('ip_address')->nullable();
            $table->text('user_agent')->nullable(); $table->text('metadata')->nullable();
            $table->timestamp('created_at')->nullable();
        });
        AdminUser::create([
            'name' => 'Local Security Fixture', 'email' => 'admin@example.test',
            'password' => 'local-fixture-password', 'role' => 'super_admin',
        ]);
    }

    private function attempt(string $password, string $email = 'admin@example.test')
    {
        return $this->from('/admin/login')->post('/admin/login', compact('email', 'password'));
    }

    public function test_repeated_failed_admin_login_blocks_even_correct_password_until_decay(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->attempt('incorrect')->assertSessionHasErrors('email');
        }
        $this->attempt('local-fixture-password')->assertSessionHasErrors('email');
        $this->assertGuest('admin');

        $this->travel(61)->seconds();
        $this->attempt('local-fixture-password')->assertRedirect('/admin/dashboard');
        $this->assertAuthenticated('admin');
    }

    public function test_successful_login_clears_previous_failures(): void
    {
        for ($round = 0; $round < 2; $round++) {
            for ($i = 0; $i < 4; $i++) {
                $this->attempt('incorrect')->assertSessionHasErrors('email');
            }
            $this->attempt('local-fixture-password')->assertRedirect('/admin/dashboard');
            $this->assertAuthenticated('admin');
            $this->post('/admin/logout')->assertRedirect('/');
        }
    }

    public function test_email_case_cannot_reset_the_attempt_budget(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->attempt('incorrect', $i % 2 ? 'ADMIN@example.test' : 'admin@example.test');
        }
        $this->attempt('local-fixture-password')->assertSessionHasErrors('email');
        $this->assertGuest('admin');
    }

    public function test_unknown_identity_does_not_exhaust_another_identity_budget(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->attempt('incorrect', 'unknown@example.test');
        }
        $this->attempt('local-fixture-password')->assertRedirect('/admin/dashboard');
        $this->assertAuthenticated('admin');
    }

    public function test_failed_password_is_not_retained_in_old_form_input(): void
    {
        $this->attempt('incorrect')->assertSessionHasErrors('email')
            ->assertSessionHas('_old_input.email', 'admin@example.test')
            ->assertSessionMissing('_old_input.password');
    }

    public function test_rotating_email_addresses_cannot_bypass_the_ip_budget(): void
    {
        for ($i = 0; $i < 20; $i++) {
            $this->attempt('incorrect', "unknown{$i}@example.test");
        }
        $this->attempt('local-fixture-password')->assertSessionHasErrors('email');
        $this->assertGuest('admin');
        $this->assertSame(20, \App\Models\SecurityLog::where('action', 'failed_login')->count());

        $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.2']);
        $this->attempt('local-fixture-password')->assertRedirect('/admin/dashboard');
        $this->assertAuthenticated('admin');
    }
}
