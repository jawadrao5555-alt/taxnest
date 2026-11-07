<?php

namespace Tests\Feature;

use App\Models\AdminUser;
use App\Models\SupportCase;
use App\Services\SupportMailService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\DB;
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
        Schema::create('companies', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('account_code')->nullable();
            $table->string('product_type')->nullable();
            $table->softDeletes();
            $table->timestamps();
        });
        Schema::create('support_cases', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('assigned_admin_id')->nullable();
            $table->unsignedBigInteger('opened_by_admin_id')->nullable();
            $table->unsignedBigInteger('resolved_by_admin_id')->nullable();
            $table->unsignedBigInteger('reopened_by_admin_id')->nullable();
            $table->string('source_box', 16);
            $table->unsignedBigInteger('source_uid');
            $table->string('source_message_id', 512)->nullable();
            $table->string('subject');
            $table->string('status', 24)->default('open');
            $table->string('resolution_build', 100)->nullable();
            $table->timestamp('opened_at');
            $table->timestamp('resolved_at')->nullable();
            $table->timestamp('reopened_at')->nullable();
            $table->timestamps();
            $table->unique(['source_box', 'source_uid']);
        });
        Schema::create('support_case_events', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('support_case_id');
            $table->unsignedBigInteger('actor_admin_id')->nullable();
            $table->string('event_type', 24);
            $table->string('from_status', 24)->nullable();
            $table->string('to_status', 24)->nullable();
            $table->string('resolution_build', 100)->nullable();
            $table->text('note')->nullable();
            $table->timestamp('occurred_at');
            $table->timestamps();
        });

        AdminUser::create([
            'name' => 'Super', 'email' => 'super@test.pk',
            'password' => Hash::make('secret123'), 'role' => 'super_admin',
        ]);
        AdminUser::create([
            'name' => 'Viewer', 'email' => 'viewer@test.pk',
            'password' => Hash::make('secret123'), 'role' => 'viewer',
        ]);
        DB::table('companies')->insert([
            'id' => 17,
            'name' => 'Fictional Punjab Cafe',
            'account_code' => 'PRA-00017',
            'product_type' => 'pos',
            'created_at' => now(),
            'updated_at' => now(),
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

    protected function sampleMessage(): array
    {
        return [
            'uid' => 7, 'subject' => 'Login masla', 'from_name' => 'Ali Khan',
            'from_email' => 'ali@example.com', 'to_email' => 'support@taxnest.pk',
            'date' => now(), 'seen' => true, 'has_attachments' => false,
            'html' => null, 'text' => 'Mera login nahi chal raha.',
            'message_id' => '<abc@example.com>', 'references' => '',
            'attachments' => [],
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
            $m->shouldReceive('getMessage')->with('inbox', 7)->andReturn($this->sampleMessage());
        });

        $res = $this->actingAsSuperAdmin()->get('/admin/support-inbox/inbox/7');
        $res->assertOk()
            ->assertSee('Mera login nahi chal raha.')
            ->assertSee('Re: Login masla')
            ->assertSee('Company support case')
            ->assertSee('Fictional Punjab Cafe');
    }

    public function test_inbound_message_opens_one_company_linked_owned_case_with_timeline(): void
    {
        $this->mock(SupportMailService::class, function ($m) {
            $m->shouldReceive('getMessage')->times(3)->with('inbox', 7)->andReturn($this->sampleMessage());
        });
        DB::table('companies')->insert([
            'id' => 18, 'name' => 'Other Fictional Cafe', 'account_code' => 'PRA-00018',
            'product_type' => 'pos', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->actingAsSuperAdmin()->post('/admin/support-inbox/inbox/7/case', ['company_id' => 17])
            ->assertRedirect('/admin/support-inbox/inbox/7');
        $this->actingAsSuperAdmin()->post('/admin/support-inbox/inbox/7/case', ['company_id' => 17])
            ->assertRedirect('/admin/support-inbox/inbox/7');
        $this->actingAsSuperAdmin()->post('/admin/support-inbox/inbox/7/case', ['company_id' => 18])
            ->assertStatus(409);

        $this->assertDatabaseCount('support_cases', 1);
        $this->assertDatabaseHas('support_cases', [
            'company_id' => 17,
            'source_box' => 'inbox',
            'source_uid' => 7,
            'source_message_id' => '<abc@example.com>',
            'subject' => 'Login masla',
            'status' => SupportCase::STATUS_OPEN,
            'assigned_admin_id' => AdminUser::where('role', 'super_admin')->value('id'),
        ]);
        $this->assertDatabaseCount('support_case_events', 1);
        $this->assertDatabaseHas('support_case_events', ['event_type' => 'opened', 'to_status' => 'open']);
    }

    public function test_reply_does_not_resolve_case_and_resolution_requires_explicit_build_evidence(): void
    {
        $case = $this->createSupportCase();
        $this->mock(SupportMailService::class, function ($m) {
            $m->shouldReceive('send')->once();
        });

        $this->actingAsSuperAdmin()->post('/admin/support-inbox/send', [
            'to' => 'ali@example.com',
            'subject' => 'Re: Login masla',
            'body' => 'We are reviewing this.',
        ])->assertRedirect('/admin/support-inbox');
        $this->assertSame(SupportCase::STATUS_OPEN, $case->fresh()->status);

        $this->actingAsSuperAdmin()->post("/admin/support-inbox/cases/{$case->id}/resolve", [
            'resolution_build' => '1.13.35',
            'resolution_note' => 'Synthetic regression and exact build checks passed.',
        ])->assertRedirect();

        $resolved = $case->fresh();
        $this->assertSame(SupportCase::STATUS_RESOLVED, $resolved->status);
        $this->assertSame('1.13.35', $resolved->resolution_build);
        $this->assertNotNull($resolved->resolved_at);
        $this->assertDatabaseHas('support_case_events', [
            'support_case_id' => $case->id,
            'event_type' => 'resolved',
            'from_status' => 'open',
            'to_status' => 'resolved',
            'resolution_build' => '1.13.35',
        ]);
    }

    public function test_resolved_case_reopens_without_erasing_prior_resolution_or_timeline(): void
    {
        $case = $this->createSupportCase();
        $this->actingAsSuperAdmin()->post("/admin/support-inbox/cases/{$case->id}/resolve", [
            'resolution_build' => 'abc1234',
            'resolution_note' => 'Original evidence.',
        ])->assertRedirect();
        $originalResolvedAt = $case->fresh()->resolved_at;

        $this->actingAsSuperAdmin()->post("/admin/support-inbox/cases/{$case->id}/reopen", [
            'reopen_note' => 'Customer reproduced the same issue again.',
        ])->assertRedirect();

        $reopened = $case->fresh();
        $this->assertSame(SupportCase::STATUS_REOPENED, $reopened->status);
        $this->assertSame('abc1234', $reopened->resolution_build);
        $this->assertTrue($originalResolvedAt->equalTo($reopened->resolved_at));
        $this->assertNotNull($reopened->reopened_at);
        $this->assertDatabaseCount('support_case_events', 3);
        $this->assertDatabaseHas('support_case_events', [
            'support_case_id' => $case->id,
            'event_type' => 'reopened',
            'from_status' => 'resolved',
            'to_status' => 'reopened',
            'resolution_build' => 'abc1234',
        ]);
    }

    public function test_case_owner_is_explicit_and_only_current_persisted_super_admin_can_change_lifecycle(): void
    {
        $case = $this->createSupportCase();
        $owner = AdminUser::create([
            'name' => 'Billing Admin', 'email' => 'admin@test.pk',
            'password' => Hash::make('secret123'), 'role' => 'admin',
        ]);

        $this->actingAsSuperAdmin()->post("/admin/support-inbox/cases/{$case->id}/assign", [
            'assigned_admin_id' => $owner->id,
        ])->assertRedirect();
        $this->assertSame($owner->id, $case->fresh()->assigned_admin_id);

        $viewer = AdminUser::where('role', 'viewer')->first();
        $this->actingAs($viewer, 'admin')->post("/admin/support-inbox/cases/{$case->id}/resolve", [
            'resolution_build' => 'abc1234', 'resolution_note' => 'Not authorized.',
        ])->assertForbidden();

        $super = AdminUser::where('role', 'super_admin')->first();
        $this->actingAs($super, 'admin');
        DB::table('admin_users')->where('id', $super->id)->update(['role' => 'viewer']);
        $this->post("/admin/support-inbox/cases/{$case->id}/resolve", [
            'resolution_build' => 'abc1234', 'resolution_note' => 'Cached role must not work.',
        ])->assertForbidden();
        $this->assertSame(SupportCase::STATUS_OPEN, $case->fresh()->status);
    }

    public function test_durable_case_queue_and_timeline_remain_available_without_mailbox_calls(): void
    {
        $case = $this->createSupportCase();

        $this->actingAsSuperAdmin()->get('/admin/support-cases')
            ->assertOk()
            ->assertSee('Company Support Cases')
            ->assertSee('Fictional Punjab Cafe')
            ->assertSee('Login masla');
        $this->get("/admin/support-cases/{$case->id}")
            ->assertOk()
            ->assertSee("Case #{$case->id}")
            ->assertSee('Immutable timeline')
            ->assertSee('Resolve explicitly');
    }

    public function test_soft_deleted_company_cannot_receive_a_new_support_case(): void
    {
        DB::table('companies')->where('id', 17)->update(['deleted_at' => now()]);
        $this->actingAsSuperAdmin()->post('/admin/support-inbox/inbox/7/case', ['company_id' => 17])
            ->assertRedirect('/admin/dashboard')
            ->assertSessionHas('error');
        $this->assertDatabaseCount('support_cases', 0);
    }

    protected function createSupportCase(): SupportCase
    {
        $super = AdminUser::where('role', 'super_admin')->first();
        $case = SupportCase::query()->create([
            'company_id' => 17,
            'assigned_admin_id' => $super->id,
            'opened_by_admin_id' => $super->id,
            'source_box' => 'inbox',
            'source_uid' => 7,
            'source_message_id' => '<abc@example.com>',
            'subject' => 'Login masla',
            'status' => SupportCase::STATUS_OPEN,
            'opened_at' => now(),
        ]);
        DB::table('support_case_events')->insert([
            'support_case_id' => $case->id,
            'actor_admin_id' => $super->id,
            'event_type' => 'opened',
            'from_status' => null,
            'to_status' => 'open',
            'note' => 'Linked.',
            'occurred_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $case;
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
