<?php

namespace Tests\Feature;

use App\Models\AdminUser;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class AdminAccountRecoveryTest extends TestCase
{
    private const ORIGINAL = 'Original-test-password-97';

    private AdminUser $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropAllTables();
        Schema::create('admin_users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password');
            $table->string('role');
            $table->rememberToken();
            $table->timestamps();
        });
        $migration = require database_path('migrations/2026_12_10_000000_create_admin_recovery_contacts.php');
        $migration->up();
        $this->admin = AdminUser::create([
            'name' => 'Demo owner',
            'email' => 'owner@example.test',
            'password' => self::ORIGINAL,
            'role' => 'super_admin',
        ]);
    }

    public function test_either_verified_email_resets_the_same_account_without_a_code(): void
    {
        $this->contact(1, 'one@example.test');
        $this->contact(2, 'two@example.test');

        $messages = [];
        Mail::shouldReceive('raw')->andReturnUsing(function ($body, $send) use (&$messages) {
            $message = \Mockery::mock(\Illuminate\Mail\Message::class);
            $message->shouldReceive('to')->andReturnUsing(function ($email) use (&$messages, $message, $body) {
                $messages[$email][] = $body;
                return $message;
            });
            $message->shouldReceive('subject')->andReturnSelf();
            $send($message);
        });

        foreach (['one@example.test', 'two@example.test'] as $index => $email) {
            $this->from('/admin/recovery/forgot')->post('/admin/recovery/forgot', ['email' => $email])
                ->assertSessionHas('status');
            preg_match('~/admin/recovery/reset/([a-f0-9]{64})~', end($messages[$email]), $match);
            $this->assertArrayHasKey(1, $match);

            $password = 'Replacement-demo-password-' . $index;
            $payload = ['token' => $match[1], 'password' => $password, 'password_confirmation' => $password];
            $this->post('/admin/recovery/reset', $payload)->assertRedirect('/admin/login');
            $this->assertTrue(Hash::check($password, $this->admin->fresh()->password));
            $this->from('/admin/recovery/forgot')->post('/admin/recovery/reset', $payload)
                ->assertSessionHasErrors('recovery');
        }
        $this->assertCount(3, $messages['one@example.test']);
        $this->assertCount(3, $messages['two@example.test']);
    }

    public function test_backup_code_is_one_time_and_revokes_old_admin_sessions(): void
    {
        Mail::shouldReceive('raw')->twice();
        $one = $this->contact(1, 'one@example.test');
        $two = $this->contact(2, 'two@example.test');
        $password = 'Replacement-demo-password-33';
        $payload = ['code' => $one, 'password' => $password, 'password_confirmation' => $password];

        $this->post('/admin/recovery/reset', $payload)->assertRedirect('/admin/login');
        $this->assertDatabaseHas('admin_recovery_contacts', ['slot' => 1, 'code_hash' => null]);
        $this->assertDatabaseHas('admin_recovery_contacts', ['slot' => 2, 'code_hash' => hash('sha256', $two)]);
        $this->from('/admin/recovery/backup')->post('/admin/recovery/reset', $payload)
            ->assertSessionHasErrors('recovery');

        $this->actingAs($this->admin->fresh(), 'admin')->get('/admin/recovery/settings')
            ->assertRedirect('/admin/login');
    }

    public function test_recovery_email_is_not_active_until_signed_verification(): void
    {
        $mail = [];
        Mail::shouldReceive('raw')->twice()->andReturnUsing(function ($body) use (&$mail) {
            $mail[] = $body;
        });
        $this->actingAs($this->admin, 'admin');
        $this->from('/admin/recovery/settings')->post('/admin/recovery/settings', [
            'slot' => 1, 'email' => 'one@example.test', 'password' => self::ORIGINAL,
        ])->assertSessionHas('status');
        $this->assertDatabaseCount('admin_recovery_contacts', 0);
        $url = substr($mail[0], strrpos($mail[0], "\n") + 1);
        $this->get($url)->assertOk();
        $this->assertDatabaseCount('admin_recovery_contacts', 0);
        $response = $this->post($url, ['password' => self::ORIGINAL])->assertOk();
        $code = $response->viewData('backup');
        $this->assertMatchesRegularExpression('/^[a-f0-9]{48}$/', $code);
        $this->assertDatabaseHas('admin_recovery_contacts', [
            'slot' => 1, 'email' => 'one@example.test', 'code_hash' => hash('sha256', $code),
        ]);
        $this->post($url, ['password' => self::ORIGINAL])->assertForbidden();
    }

    private function contact(int $slot, string $email): string
    {
        $code = bin2hex(random_bytes(24));
        DB::table('admin_recovery_contacts')->insert([
            'admin_user_id' => $this->admin->id,
            'slot' => $slot,
            'email' => $email,
            'code_hash' => hash('sha256', $code),
        ]);

        return $code;
    }
}
