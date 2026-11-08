<?php

namespace Tests\Feature;

use App\Models\AdminUser;
use App\Services\RestoreSessionEpoch;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class RestoreSessionBoundaryTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Schema::dropAllTables();
        Schema::create('admin_users', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->string('email');
            $t->string('password');
            $t->string('role');
            $t->rememberToken();
            $t->timestamps();
        });
        Schema::create('security_logs', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('user_id')->nullable();
            $t->string('action');
            $t->string('ip_address')->nullable();
            $t->text('user_agent')->nullable();
            $t->text('metadata')->nullable();
            $t->dateTime('created_at');
        });
        Schema::create('restore_security_quarantines', function (Blueprint $t) {
            $t->id();
            $t->string('restore_reference_hash');
            $t->string('status');
        });
        Route::middleware(['web', 'auth:admin'])->get('/admin/restore-boundary-fixture', fn () => response('CURRENT ADMIN'));
    }

    private function admin(): AdminUser
    {
        return AdminUser::create(['name' => 'Synthetic restored administrator', 'email' => 'restore-admin@example.invalid',
            'password' => Hash::make('SyntheticRestore123!'), 'role' => 'super_admin']);
    }

    public function test_non_database_cached_admin_session_is_refused_after_quarantine_but_explicit_password_login_works(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin, 'admin')->get('/admin/restore-boundary-fixture')->assertOk();
        DB::table('restore_security_quarantines')->insert(['restore_reference_hash' => hash('sha256', 'synthetic-restore'), 'status' => 'completed']);
        $this->get('/admin/restore-boundary-fixture')->assertRedirect('/admin/login');
        $this->assertGuest('admin');
        // Real SessionGuard login emits Login and binds the fresh restore epoch.
        auth('admin')->login($admin);
        $this->get('/admin/restore-boundary-fixture')->assertOk()->assertSee('CURRENT ADMIN');
        $this->assertSame(RestoreSessionEpoch::current(), session(RestoreSessionEpoch::KEY));
        DB::table('restore_security_quarantines')->insert(['restore_reference_hash' => hash('sha256', 'second-restore'), 'status' => 'completed']);
        $this->getJson('/admin/restore-boundary-fixture')->assertUnauthorized();
        $this->assertGuest('admin');
    }

    public function test_missing_legacy_stamp_cannot_bypass_restore_and_failed_or_pending_restore_does_not_revoke_legacy_access(): void
    {
        $admin = $this->admin();
        DB::table('restore_security_quarantines')->insert(['restore_reference_hash' => hash('sha256', 'incomplete'), 'status' => 'running']);
        $this->actingAs($admin, 'admin')->get('/admin/restore-boundary-fixture')->assertOk();
        DB::table('restore_security_quarantines')->update(['status' => 'completed']);
        $this->getJson('/admin/restore-boundary-fixture')->assertUnauthorized();
        $this->assertGuest('admin');
    }

    public function test_stateless_requests_do_not_require_or_create_a_browser_session(): void
    {
        $request = \Illuminate\Http\Request::create('/api/reporting/mcp', 'POST');
        $this->actingAs($this->admin(), 'admin');
        DB::table('restore_security_quarantines')->insert(['restore_reference_hash' => hash('sha256', 'stateless'), 'status' => 'completed']);
        $result = app(\App\Http\Middleware\RestoreSessionBoundary::class)->handle($request, fn () => response('TOKEN AUTHENTICATION NEXT'));
        $this->assertSame(200, $result->getStatusCode());
        $this->assertFalse($request->hasSession());
    }
}
