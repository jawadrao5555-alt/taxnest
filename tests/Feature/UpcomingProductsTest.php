<?php

namespace Tests\Feature;

use App\Mail\UpcomingProductConsentMail;
use App\Models\AdminUser;
use App\Models\PricingPlan;
use App\Models\Subscription;
use App\Models\UpcomingProduct;
use App\Models\UpcomingProductInterest;
use App\Services\MadadgarAnswerScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class UpcomingProductsTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_catalogue_lists_approved_roadmap_content_with_existing_lab_link(): void
    {
        $this->get(route('upcoming-products.index'))
            ->assertOk()
            ->assertSeeInOrder([
                'TaxNest HR & Attendance', 'TaxNest Distribution ERP', 'TaxNest Switch',
                'TaxNest Enterprise 360', 'TaxNest Laboratory ERP expansion',
            ])
            ->assertSee('Concept illustration')
            ->assertSee('Proposed features')
            ->assertSee('Register Your Interest')
            ->assertSee('Request Early Access')
            ->assertSee('Notify Me When Available')
            ->assertSee('href="/nest-erps"', false)
            ->assertSee('View the existing product');
        $this->get('/')->assertOk()->assertSee(route('upcoming-products.index'), false);
        $this->get('/sitemap.xml')->assertOk()->assertSee(route('upcoming-products.index'), false);
    }

    public function test_super_admin_manages_lifecycle_without_assigning_plans_or_exposing_drafts(): void
    {
        $product = $this->product();
        $attrs = [
            'slug' => 'fleet-planning',
            'name' => 'Private fleet preview',
            'introduction' => 'Plan the delivery fleet.',
            'benefits' => "Track schedules\nSee delays",
            'features' => 'Vehicle summary',
            'industries' => 'Distribution',
            'status' => 'beta',
            'concept' => 'general',
            'sort_order' => 100,
            'published' => 0,
        ];
        $planCount = PricingPlan::count();
        $subscriptionCount = Subscription::count();

        $this->get(route('saas.admin.upcoming-products.index'))->assertRedirect('/admin/login');
        $this->actingAs($this->admin('admin'), 'admin');
        $this->get(route('saas.admin.upcoming-products.index'))->assertForbidden();
        $this->post(route('saas.admin.upcoming-products.store'), $attrs)->assertForbidden();

        $this->actingAs($this->admin(), 'admin')
            ->get(route('saas.admin.upcoming-products.edit', $product))->assertOk()->assertSee('Edit roadmap product');
        $this->post(route('saas.admin.upcoming-products.store'), $attrs)->assertRedirect(route('saas.admin.upcoming-products.index'));
        $draft = UpcomingProduct::where('slug', 'fleet-planning')->firstOrFail();
        $this->assertSame(['Track schedules', 'See delays'], $draft->benefits);
        $this->get(route('upcoming-products.index'))->assertDontSee($draft->name);
        $this->postJson(route('upcoming-products.interest', $draft->slug))->assertNotFound();
        unset($attrs['slug']);
        $attrs['published'] = 1;
        $this->put(route('saas.admin.upcoming-products.update', $draft), $attrs)->assertRedirect();
        $this->get(route('upcoming-products.index'))->assertSee($draft->name)->assertSee('Beta');
        $this->assertDatabaseHas('admin_audit_logs', ['action' => 'upcoming_product_saved', 'target_id' => $draft->id]);
        $attrs['status'] = 'temporarily_unavailable';
        $this->put(route('saas.admin.upcoming-products.update', $draft), $attrs)->assertRedirect();
        $interestUrl = route('upcoming-products.interest', $draft->slug);
        $interest = [
            'email' => 'fictional@example.test', 'purpose' => 'interest', 'consent' => '1',
        ];
        $this->postJson($interestUrl, $interest)->assertNotFound();

        $attrs['status'] = UpcomingProduct::RELEASED;
        $this->put(route('saas.admin.upcoming-products.update', $draft), $attrs)->assertRedirect();
        $this->get(route('upcoming-products.index'))->assertDontSee($draft->name)
            ->assertSee('TaxNest Distribution ERP');
        $this->postJson($interestUrl, $interest)->assertNotFound();
        $this->assertDatabaseCount('upcoming_product_interests', 0);
        $this->get(route('saas.admin.upcoming-products.index'))->assertOk()
            ->assertSee($draft->name)->assertSee('Off public roadmap');
        $this->assertNotNull($draft->fresh()->published_at);

        $this->assertSame($planCount, PricingPlan::count());
        $this->assertSame($subscriptionCount, Subscription::count());
    }

    public function test_per_purpose_consent_requires_confirmation_and_can_be_withdrawn(): void
    {
        Mail::fake();
        $product = $this->product();
        $url = route('upcoming-products.interest', $product->slug);
        $data = ['email' => 'Roadmap@example.test', 'purpose' => 'early_access'];
        $this->post($url, $data)->assertSessionHasErrors('consent');
        $this->assertDatabaseCount('upcoming_product_interests', 0);

        $data['consent'] = '1';
        $this->post($url, $data)->assertRedirect(route('upcoming-products.index').'#'.$product->slug);
        $this->post($url, array_replace($data, ['email' => 'roadmap@example.test']))->assertRedirect();
        Mail::assertSent(UpcomingProductConsentMail::class, 1);
        $pending = UpcomingProductInterest::sole();
        $this->assertNull($pending->confirmed_at);
        $this->assertSame('roadmap@example.test', $pending->email);
        $this->assertSame('upcoming-v1', $pending->consent_version);
        $this->assertStringNotContainsString($pending->email, DB::table('upcoming_product_interests')->where('id', $pending->id)->value('email'));

        $email = Mail::sent(UpcomingProductConsentMail::class)->sole();
        $this->assertTrue($email->hasTo('roadmap@example.test'));
        parse_str(parse_url($email->manageUrl, PHP_URL_QUERY), $query);
        $token = $query['token'];
        $this->assertSame($pending->token_hash, hash('sha256', $token));

        $manage = route('upcoming-products.manage', ['token' => $token]);
        $action = route('upcoming-products.change-interest');
        $this->get($manage)->assertOk()->assertSee('Confirm this request')->assertDontSee($pending->email);
        $this->assertNull($pending->fresh()->confirmed_at);
        $this->postJson($action, ['token' => str_repeat('a', 64), 'action' => 'confirm'])->assertNotFound();
        $this->post($action, ['token' => $token, 'action' => 'confirm'])->assertRedirect($manage);
        $this->get($manage)->assertOk()->assertSee('Withdraw this request');

        $this->post($url, array_replace($data, ['purpose' => 'notification']))->assertRedirect();
        Mail::assertSent(UpcomingProductConsentMail::class, 2);
        $this->assertDatabaseCount('upcoming_product_interests', 2);
        $this->actingAs($this->admin(), 'admin')->get(route('saas.admin.upcoming-products.interests', $product))
            ->assertOk()->assertSee('roadmap@example.test')->assertSee('Request Early Access')->assertDontSee('Notify Me When Available · Confirmed');
        $product->update(['status' => UpcomingProduct::RELEASED]);
        $this->get($manage)->assertOk()->assertSee('Withdraw this request');
        $this->get(route('saas.admin.upcoming-products.interests', $product))->assertOk()->assertSee('roadmap@example.test');
        $this->post($action, ['token' => $token, 'action' => 'withdraw'])->assertRedirect($manage);
        $this->get($manage)->assertOk()->assertSee('Your request has been withdrawn.');
        $this->assertNotNull($pending->fresh()->withdrawn_at);
        $this->assertNull($pending->fresh()->email);
        $this->get(route('saas.admin.upcoming-products.interests', $product))->assertOk()->assertDontSee('roadmap@example.test');
    }

    public function test_pending_links_expire_and_public_interest_requests_are_rate_limited(): void
    {
        Mail::fake();
        $product = $this->product();
        $url = route('upcoming-products.interest', $product->slug);
        $input = ['email' => 'example@example.test', 'purpose' => 'interest', 'consent' => '1'];
        foreach (range(1, 8) as $attempt) {
            $this->post($url, $input)->assertRedirect();
        }
        $this->post($url, $input)->assertTooManyRequests();
        $message = Mail::sent(UpcomingProductConsentMail::class)->sole();
        parse_str(parse_url($message->manageUrl, PHP_URL_QUERY), $query);

        $this->travel(25)->hours();
        $this->get($message->manageUrl)->assertOk()->assertSee('This confirmation expired.');
        $this->post(route('upcoming-products.change-interest'), ['token' => $query['token'], 'action' => 'confirm'])->assertGone();
        $this->post($url, $input)->assertRedirect();
        Mail::assertSent(UpcomingProductConsentMail::class, 2);
        $this->assertDatabaseCount('upcoming_product_interests', 1);
        $this->getJson($message->manageUrl)->assertNotFound();
    }

    public function test_madadgar_uses_published_lifecycle_and_never_shares_interest_records(): void
    {
        $product = $this->product();
        $result = MadadgarAnswerScope::answer('Is TaxNest Distribution ERP available?', 'pos', null);
        $this->assertSame('upcoming_products_guide', $result['scope_reason']);
        $this->assertStringContainsString('TaxNest Distribution ERP — Coming Soon', $result['text']);
        $this->assertSame('public_product_roadmap', MadadgarAnswerScope::metadata($result, 'pos', null, null)['answer_source']);
        $product->update(['status' => 'beta']);
        $this->assertStringContainsString('TaxNest Distribution ERP — Beta', MadadgarAnswerScope::answer('Upcoming products', 'pos', null)['text']);
        $product->update(['status' => UpcomingProduct::RELEASED]);
        $this->assertStringNotContainsString('TaxNest Distribution ERP', MadadgarAnswerScope::answer('Upcoming products', 'pos', null)['text']);
        $product->update(['status' => 'beta', 'published_at' => null]);
        $this->assertStringNotContainsString('TaxNest Distribution ERP', MadadgarAnswerScope::answer('Upcoming products', 'pos', null)['text']);
    }

    private function product(): UpcomingProduct
    {
        return UpcomingProduct::where('slug', 'distribution-erp')->firstOrFail();
    }

    private function admin(string $role = 'super_admin'): AdminUser
    {
        return AdminUser::create([
            'name' => 'Fictional Roadmap Admin',
            'email' => $role.'@example.test',
            'password' => 'fixture-password',
            'role' => $role,
        ]);
    }
}
