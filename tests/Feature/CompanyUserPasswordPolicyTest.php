<?php

namespace Tests\Feature;

use App\Http\Controllers\CompanyUserController;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Password policy alignment (security remediation, Sep 2026).
 *
 * CompanyUserController::store / resetPassword used to accept `min:6`; every
 * other path that sets a NEW password (self-registration, reset link,
 * profile) already used Rules\Password::defaults() (min 8). Existing hashes
 * are untouched — only newly set passwords are validated.
 */
class CompanyUserPasswordPolicyTest extends TestCase
{
    private int $companyId;

    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropAllTables();

        Schema::create('companies', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->boolean('is_internal_account')->default(false);
            $table->integer('user_limit_override')->nullable();
            $table->softDeletes();
            $table->timestamps();
        });

        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id')->nullable();
            $table->string('name');
            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            $table->string('password')->nullable();
            $table->string('role')->nullable();
            $table->string('username')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        foreach (['security_logs', 'audit_logs'] as $t) {
            Schema::create($t, function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('user_id')->nullable();
                $table->unsignedBigInteger('company_id')->nullable();
                $table->string('action')->nullable();
                $table->string('entity_type')->nullable();
                $table->unsignedBigInteger('entity_id')->nullable();
                $table->text('old_values')->nullable();
                $table->text('new_values')->nullable();
                $table->string('sha256_hash')->nullable();
                $table->string('ip_address')->nullable();
                $table->text('user_agent')->nullable();
                $table->text('metadata')->nullable();
                $table->timestamp('created_at')->nullable();
                $table->timestamp('updated_at')->nullable();
            });
        }

        // -1 = unlimited seats, so only the password rule can reject here.
        $this->companyId = DB::table('companies')->insertGetId([
            'name' => 'Policy Traders', 'user_limit_override' => -1,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function admin(): User
    {
        return User::create([
            'company_id' => $this->companyId, 'name' => 'Owner', 'email' => 'owner@policy.test',
            'password' => Hash::make('Owner@12345'), 'role' => 'company_admin', 'is_active' => true,
        ]);
    }

    private function storeWith(string $password): ?ValidationException
    {
        $this->actingAs($this->admin());
        $req = Request::create('/company/users', 'POST', [
            'name' => 'New User', 'email' => 'new-' . uniqid() . '@policy.test',
            'password' => $password, 'role' => 'employee',
        ]);
        $req->setLaravelSession(app('session.store'));
        app()->instance('request', $req);

        try {
            (new CompanyUserController())->store($req);
            return null;
        } catch (ValidationException $e) {
            return $e;
        }
    }

    private function resetWith(User $target, string $password): ?ValidationException
    {
        $this->actingAs($this->admin());
        $req = Request::create("/company/users/{$target->id}/reset-password", 'PATCH', ['password' => $password]);
        $req->setLaravelSession(app('session.store'));
        app()->instance('request', $req);

        try {
            (new CompanyUserController())->resetPassword($req, $target);
            return null;
        } catch (ValidationException $e) {
            return $e;
        }
    }

    public function test_store_rejects_six_char_password(): void
    {
        $e = $this->storeWith('abc123');

        $this->assertNotNull($e, 'a 6-character password must be rejected');
        $this->assertArrayHasKey('password', $e->errors());
        $this->assertSame(0, User::where('role', 'employee')->count());
    }

    public function test_store_accepts_eight_plus_char_password(): void
    {
        $this->assertNull($this->storeWith('abcd1234'));
        $this->assertSame(1, User::where('role', 'employee')->count());
    }

    public function test_reset_password_rejects_six_and_accepts_eight(): void
    {
        $target = User::create([
            'company_id' => $this->companyId, 'name' => 'Staff', 'email' => 'staff@policy.test',
            'password' => Hash::make('legacy6'), 'role' => 'employee', 'is_active' => true,
        ]);

        $e = $this->resetWith($target, 'short6');
        $this->assertNotNull($e);
        $this->assertArrayHasKey('password', $e->errors());
        $this->assertTrue(Hash::check('legacy6', $target->fresh()->password), 'rejected reset must not touch the hash');

        $this->assertNull($this->resetWith($target, 'longenough8'));
        $this->assertTrue(Hash::check('longenough8', $target->fresh()->password));
    }

    public function test_existing_short_password_hash_still_authenticates(): void
    {
        // Policy applies to NEW passwords only — no migration, no forced reset.
        $user = User::create([
            'company_id' => $this->companyId, 'name' => 'Old', 'email' => 'old@policy.test',
            'password' => Hash::make('old6pw'), 'role' => 'employee', 'is_active' => true,
        ]);

        $this->assertTrue(Hash::check('old6pw', $user->password));
    }
}
