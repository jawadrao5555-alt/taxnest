<?php

namespace Tests\Feature;

use App\Models\AdminAnnouncement;
use App\Models\AdminUser;
use App\Models\Company;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class AdminAnnouncementAudienceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Schema::dropAllTables();
        Schema::create('companies', function (Blueprint $t) {
            $t->id(); $t->string('name'); $t->string('product_type')->nullable();
            $t->string('business_category')->nullable(); $t->string('pos_type')->nullable();
            $t->softDeletes(); $t->timestamps();
        });
        Schema::create('admin_announcements', function (Blueprint $t) {
            $t->id(); $t->string('title'); $t->text('message'); $t->string('type');
            $t->string('target'); $t->unsignedBigInteger('target_company_id')->nullable();
            $t->string('audience_panel')->default('all'); $t->json('target_categories')->nullable();
            $t->boolean('is_active')->default(true); $t->timestamp('expires_at')->nullable();
            $t->unsignedBigInteger('created_by'); $t->timestamps();
        });
        Schema::create('admin_users', function (Blueprint $t) {
            $t->id(); $t->string('name'); $t->string('email'); $t->string('password');
            $t->string('role')->default('super_admin'); $t->rememberToken(); $t->timestamps();
        });
        Schema::create('app_updates', function (Blueprint $t) {
            $t->id(); $t->string('title'); $t->text('points');
            $t->string('audience'); $t->string('audience_family')->default('all');
            $t->json('target_categories')->nullable(); $t->timestamps();
        });
        Schema::create('sessions', function (Blueprint $t) {
            $t->string('id')->primary(); $t->foreignId('user_id')->nullable();
            $t->string('ip_address', 45)->nullable(); $t->text('user_agent')->nullable();
            $t->longText('payload'); $t->integer('last_activity');
        });
    }

    private function company(string $name, string $panel, ?string $category, ?string $legacy = null): Company
    {
        $id = DB::table('companies')->insertGetId([
            'name' => $name, 'product_type' => $panel, 'business_category' => $category,
            'pos_type' => $legacy, 'created_at' => now(), 'updated_at' => now(),
        ]);
        return Company::findOrFail($id);
    }

    private function notice(string $target, ?int $companyId = null, string $panel = 'all', ?array $categories = null): AdminAnnouncement
    {
        return AdminAnnouncement::create([
            'title' => 'Audience test', 'message' => 'A notice', 'type' => 'info',
            'target' => $target, 'target_company_id' => $companyId,
            'audience_panel' => $panel, 'target_categories' => $categories,
            'created_by' => 1, 'is_active' => true,
        ]);
    }

    public function test_existing_all_and_specific_notices_keep_their_reach(): void
    {
        $pra = $this->company('PRA shop', 'pos', 'restaurant');
        $fbr = $this->company('FBR shop', 'fbrpos', 'grocery');
        $all = $this->notice('all');
        $specific = $this->notice('specific', $pra->id);

        $this->assertTrue($all->reachesCompany($pra));
        $this->assertTrue($all->reachesCompany($fbr));
        $this->assertTrue($specific->reachesCompany($pra));
        $this->assertFalse($specific->reachesCompany($fbr));
        $this->assertEqualsCanonicalizing([$all->id, $specific->id], AdminAnnouncement::forCompany($pra->id)->pluck('id')->all());
        $this->assertSame([$all->id], AdminAnnouncement::forCompany($fbr->id)->pluck('id')->all());
    }

    public function test_guest_house_notice_reaches_only_matching_pra_company_including_legacy_resolution(): void
    {
        $hotel = $this->company('Hotel', 'pos', 'hotel');
        $legacy = $this->company('Legacy hotel', 'pos', null, 'hotel');
        $restaurant = $this->company('Restaurant', 'pos', 'restaurant');
        $fbr = $this->company('FBR hotel', 'fbrpos', 'hotel');
        $notice = $this->notice('all', null, 'pra', ['hotel']);

        foreach ([$hotel, $legacy, $restaurant, $fbr] as $company) {
            $this->assertSame($notice->reachesCompany($company),
                AdminAnnouncement::forCompany($company->id)->whereKey($notice->id)->exists());
        }
        $this->assertTrue($notice->reachesCompany($hotel));
        $this->assertTrue($notice->reachesCompany($legacy));
        $this->assertFalse($notice->reachesCompany($restaurant));
        $this->assertFalse($notice->reachesCompany($fbr));
    }

    public function test_preview_counts_exact_recipients_and_invalid_empty_categories_cannot_publish(): void
    {
        $this->company('Hotel', 'pos', 'hotel');
        $this->company('Other', 'pos', 'restaurant');
        $this->company('FBR hotel', 'fbrpos', 'hotel');
        DB::table('admin_users')->insert([
            'name' => 'Owner', 'email' => 'owner@example.test', 'password' => bcrypt('secret'),
            'role' => 'super_admin', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->actingAs(AdminUser::first(), 'admin');
        $audience = ['target' => 'all', 'audience_panel' => 'pra',
            'audience_scope' => 'categories', 'target_categories' => ['hotel']];

        $this->postJson('/admin/announcements/audience-preview', $audience)
            ->assertOk()->assertJsonPath('count', 1)->assertJsonPath('examples.0', 'Hotel');
        $this->postJson('/admin/announcements/audience-preview', array_merge($audience, ['target_categories' => []]))
            ->assertUnprocessable()->assertJsonValidationErrors('target_categories');
        $this->postJson('/admin/announcements/audience-preview', array_merge($audience, ['target_categories' => ['grocery']]))
            ->assertUnprocessable()->assertJsonValidationErrors('target_categories');
    }

    public function test_pos_update_preview_respects_panel_family_and_category(): void
    {
        $this->company('Hotel PRA', 'pos', 'hotel');
        $this->company('Restaurant PRA', 'pos', 'restaurant');
        $this->company('Hotel FBR', 'fbrpos', 'hotel');
        DB::table('admin_users')->insert([
            'name' => 'Owner', 'email' => 'owner@example.test', 'password' => bcrypt('secret'),
            'role' => 'super_admin', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->actingAs(AdminUser::first(), 'admin');
        $data = ['audience' => 'pos', 'audience_family' => 'accommodation',
            'audience_scope' => 'cats', 'target_categories' => ['hotel']];

        $this->postJson('/admin/app-updates/audience-preview', $data)
            ->assertOk()->assertJsonPath('count', 1)->assertJsonPath('examples.0', 'Hotel PRA');
        $this->postJson('/admin/app-updates/audience-preview', array_merge($data, ['target_categories' => []]))
            ->assertUnprocessable()->assertJsonValidationErrors('target_categories');
    }
}
