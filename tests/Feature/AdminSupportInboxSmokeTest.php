<?php

namespace Tests\Feature;

use App\Models\AdminUser;
use App\Services\SupportMailService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * SMOKE TEST — Support Inbox (support@taxnest.pk inside admin panel).
 * The SupportMailService is mocked (no real IMAP/SMTP in tests).
 */
class AdminSupportInboxSmokeTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropAllTables();
        Schema::create('admin_users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password');
            $table->string('role')->default('super_admin');
            $table->rememberToken();
            $table->timestamps();
        });
        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->foreignId('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });

        AdminUser::create([
            'name' => 'Super', 'email' => 'super@test.pk',
            'password' => Hash::make('secret123'), 'role' => 'super_admin',
        ]);
        AdminUser::create([
            'name' => 'Viewer', 'email' => 'viewer@test.pk',
            'password' => Hash::make('secret123'), 'role' => 'viewer',
        ]);
    }

    protected function actingAsSuperAdmin(): static
    {
        return $this->actingAs(AdminUser::where('role', 'super_admin')->first(), 'admin');
    }

    protected function sampleList(): array
    {
        return [
            'messages' => [[
                'uid' => 7, 'subject' => 'Login masla', 'from_name' => 'Ali Khan',
                'from_email' => 'ali@example.com', 'to_email' => 'support@taxnest.pk',
                'date' => now(), 'seen' => false, 'has_attachments' => true,
            ]],
            'total' => 1, 'page' => 1, 'last_page' => 1,
        ];
    }

    public function test_inbox_page_renders_with_unread_bold_row(): void
    {
        $this->mock(SupportMailService::class, function ($m) {
            $m->shouldReceive('isConfigured')->andReturn(true);
            $m->shouldReceive('listMessages')->with('inbox', 1)->andReturn($this->sampleList());
        });

        $res = $this->actingAsSuperAdmin()->get('/admin/support-inbox');
        $res->assertOk()->assertSee('Support Inbox')->assertSee('Login masla')->assertSee('Ali Khan');
    }

    public function test_poll_returns_fingerprint_and_list_html(): void
    {
        $this->mock(SupportMailService::class, function ($m) {
            $m->shouldReceive('isConfigured')->andReturn(true);
            $m->shouldReceive('listMessagesCached')->with('inbox', 1)->andReturn($this->sampleList());
        });

        $res = $this->actingAsSuperAdmin()->getJson('/admin/support-inbox/poll');
        $res->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonStructure(['ok', 'fingerprint', 'unread', 'html']);
        $this->assertStringContainsString('Login masla', $res->json('html'));
    }

    public function test_poll_returns_503_when_imap_down(): void
    {
        $this->mock(SupportMailService::class, function ($m) {
            $m->shouldReceive('isConfigured')->andReturn(true);
            $m->shouldReceive('listMessagesCached')->andThrow(new \RuntimeException('IMAP down'));
        });

        $this->actingAsSuperAdmin()->getJson('/admin/support-inbox/poll')->assertStatus(503);
    }

    public function test_poll_forbidden_for_non_super_admin(): void
    {
        $this->actingAs(AdminUser::where('role', 'viewer')->first(), 'admin')
            ->getJson('/admin/support-inbox/poll')->assertStatus(403);
    }

    public function test_sent_tab_uses_sent_box(): void
    {
        $this->mock(SupportMailService::class, function ($m) {
            $m->shouldReceive('isConfigured')->andReturn(true);
            $m->shouldReceive('listMessages')->with('sent', 1)->andReturn($this->sampleList());
        });

        $this->actingAsSuperAdmin()->get('/admin/support-inbox?tab=sent')->assertOk();
    }

    public function test_unconfigured_mailbox_shows_friendly_error_not_500(): void
    {
        $this->mock(SupportMailService::class, function ($m) {
            $m->shouldReceive('isConfigured')->andReturn(false);
        });

        $this->actingAsSuperAdmin()->get('/admin/support-inbox')
            ->assertOk()->assertSee('SUPPORT_MAIL_PASSWORD');
    }

    public function test_show_page_renders_message_and_reply_form(): void
    {
        $this->mock(SupportMailService::class, function ($m) {
            $m->shouldReceive('getMessage')->with('inbox', 7)->andReturn([
                'uid' => 7, 'subject' => 'Login masla', 'from_name' => 'Ali Khan',
                'from_email' => 'ali@example.com', 'to_email' => 'support@taxnest.pk',
                'date' => now(), 'seen' => true, 'has_attachments' => false,
                'html' => null, 'text' => 'Mera login nahi chal raha.',
                'message_id' => '<abc@example.com>', 'references' => '',
                'attachments' => [],
            ]);
        });

        $res = $this->actingAsSuperAdmin()->get('/admin/support-inbox/inbox/7');
        $res->assertOk()->assertSee('Mera login nahi chal raha.')->assertSee('Re: Login masla');
    }

    public function test_reply_send_calls_service_and_redirects(): void
    {
        $this->mock(SupportMailService::class, function ($m) {
            $m->shouldReceive('send')->once()->withArgs(function ($payload) {
                return $payload['to'] === 'ali@example.com'
                    && $payload['subject'] === 'Re: Login masla'
                    && $payload['in_reply_to'] === '<abc@example.com>';
            });
        });

        $res = $this->actingAsSuperAdmin()->post('/admin/support-inbox/send', [
            'to' => 'ali@example.com',
            'subject' => 'Re: Login masla',
            'body' => 'Password reset link bhej di hai.',
            'in_reply_to' => '<abc@example.com>',
            'reply_box' => 'inbox',
            'reply_uid' => 7,
        ]);
        $res->assertRedirect('/admin/support-inbox/inbox/7');
        $res->assertSessionHas('success');
    }

    public function test_smtp_failure_shows_error_not_500(): void
    {
        $this->mock(SupportMailService::class, function ($m) {
            $m->shouldReceive('send')->andThrow(new \RuntimeException('SMTP down'));
        });

        $res = $this->actingAsSuperAdmin()->from('/admin/support-inbox')->post('/admin/support-inbox/send', [
            'to' => 'ali@example.com', 'subject' => 'Hi', 'body' => 'Test',
        ]);
        $res->assertRedirect('/admin/support-inbox');
        $res->assertSessionHas('error', 'SMTP down');
    }

    public function test_attachment_download_streams_content(): void
    {
        $this->mock(SupportMailService::class, function ($m) {
            $m->shouldReceive('getAttachment')->with('inbox', 7, 0)->andReturn([
                'name' => 'proof.pdf', 'mime' => 'application/pdf', 'content' => '%PDF-fake',
            ]);
        });

        $res = $this->actingAsSuperAdmin()->get('/admin/support-inbox/inbox/7/attachment/0');
        $res->assertOk();
        $this->assertSame('application/pdf', $res->headers->get('Content-Type'));
        $this->assertStringContainsString('proof.pdf', $res->headers->get('Content-Disposition'));
    }

    public function test_non_super_admin_gets_403(): void
    {
        $viewer = AdminUser::where('role', 'viewer')->first();
        $this->actingAs($viewer, 'admin')->get('/admin/support-inbox')->assertForbidden();
        $this->actingAs($viewer, 'admin')->post('/admin/support-inbox/send', [
            'to' => 'x@y.com', 'subject' => 'a', 'body' => 'b',
        ])->assertForbidden();
    }

    public function test_guest_redirected_to_admin_login(): void
    {
        $this->get('/admin/support-inbox')->assertRedirect('/admin/login');
    }

    public function test_invalid_box_rejected(): void
    {
        // Panel-wide 404 handling redirects within the admin panel (bootstrap/app.php).
        $this->actingAsSuperAdmin()->get('/admin/support-inbox/spam/1')
            ->assertRedirect('/admin/dashboard')
            ->assertSessionHas('error');
    }
}
