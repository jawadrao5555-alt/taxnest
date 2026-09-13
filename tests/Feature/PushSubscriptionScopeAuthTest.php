<?php

namespace Tests\Feature;

use App\Models\PushSubscription;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class PushSubscriptionScopeAuthTest extends TestCase
{
    private int $webUserId;
    private int $posUserId;
    private int $fbrUserId;

    protected function setUp(): void
    {
        parent::setUp();
        User::flushScopeColumnCache();
        Schema::dropAllTables();

        Schema::create('users', function (Blueprint $t) {
            $t->id();
            $t->string('name')->nullable();
            $t->string('email')->nullable();
            $t->string('password')->nullable();
            $t->unsignedBigInteger('company_id')->nullable();
            $t->string('role')->nullable();
            $t->string('pos_role')->nullable();
            $t->boolean('is_active')->default(true);
            $t->rememberToken();
            $t->timestamps();
        });
        Schema::create('push_subscriptions', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('user_id')->nullable();
            $t->unsignedBigInteger('company_id')->nullable();
            $t->string('scope', 32)->default('di');
            $t->string('endpoint', 500);
            $t->string('p256dh', 255)->nullable();
            $t->string('auth_key', 255)->nullable();
            $t->string('user_agent', 500)->nullable();
            $t->timestamp('last_used_at')->nullable();
            $t->timestamps();
            $t->unique(['user_id', 'endpoint'], 'push_sub_user_endpoint_unique');
        });

        $this->webUserId = $this->makeUser(1);
        $this->posUserId = $this->makeUser(2);
        $this->fbrUserId = $this->makeUser(3);
    }

    private function actingProduct(int $userId, string $guard)
    {
        foreach (['web', 'pos', 'fbrpos'] as $g) {
            Auth::guard($g)->logout();
        }

        return $this->actingAs(User::find($userId), $guard);
    }

    private function makeUser(int $companyId): int
    {
        return (int) DB::table('users')->insertGetId([
            'name' => 'Push User '.$companyId,
            'email' => "push{$companyId}@example.test",
            'password' => Hash::make('password'),
            'company_id' => $companyId,
            'role' => 'company_admin',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_web_guard_can_subscribe_di_or_web_scope(): void
    {
        $user = User::find($this->webUserId);
        $this->actingAs($user, 'web')
            ->postJson('/api/push/subscribe', [
                'endpoint' => 'https://push.example/di',
                'scope' => 'di',
                'keys' => ['p256dh' => 'p', 'auth' => 'a'],
            ])->assertOk()->assertJson(['ok' => true]);

        $this->assertSame('di', PushSubscription::where('endpoint', 'https://push.example/di')->value('scope'));

        $this->actingAs($user, 'web')
            ->postJson('/api/push/subscribe', [
                'endpoint' => 'https://push.example/web',
                'scope' => 'web',
            ])->assertOk();
        $this->assertSame('web', PushSubscription::where('endpoint', 'https://push.example/web')->value('scope'));
    }

    public function test_cross_product_subscription_is_forbidden(): void
    {
        $this->actingProduct($this->posUserId, 'pos')
            ->postJson('/api/push/subscribe', [
                'endpoint' => 'https://push.example/cross',
                'scope' => 'di',
            ])->assertStatus(403);

        $this->actingProduct($this->webUserId, 'web')
            ->postJson('/api/push/subscribe', [
                'endpoint' => 'https://push.example/cross2',
                'scope' => 'pos',
            ])->assertStatus(403);

        $this->actingProduct($this->fbrUserId, 'fbrpos')
            ->postJson('/api/push/subscribe', [
                'endpoint' => 'https://push.example/cross3',
                'scope' => 'pos',
            ])->assertStatus(403);

        foreach (['web', 'pos', 'fbrpos'] as $g) {
            Auth::guard($g)->logout();
        }
        $this->postJson('/api/push/subscribe', [
            'endpoint' => 'https://push.example/anon',
            'scope' => 'pos',
        ])->assertStatus(401);

        $this->assertSame(0, PushSubscription::count());
    }

    public function test_endpoint_over_500_chars_is_a_validation_error(): void
    {
        $this->actingAs(User::find($this->posUserId), 'pos')
            ->postJson('/api/push/subscribe', [
                'endpoint' => 'https://push.example/'.str_repeat('x', 500),
                'scope' => 'pos',
            ])->assertStatus(422)
            ->assertJsonValidationErrors('endpoint');

        $this->assertSame(0, PushSubscription::count());
    }

    public function test_legacy_unsubscribe_still_deletes_existing_row(): void
    {
        PushSubscription::create([
            'user_id' => $this->posUserId,
            'company_id' => 2,
            'scope' => 'di',
            'endpoint' => 'https://push.example/legacy',
        ]);

        $this->actingAs(User::find($this->posUserId), 'pos')
            ->postJson('/api/push/unsubscribe', [
                'endpoint' => 'https://push.example/legacy',
            ])->assertOk()->assertJson(['ok' => true]);

        $this->assertSame(0, PushSubscription::count());
    }
}
