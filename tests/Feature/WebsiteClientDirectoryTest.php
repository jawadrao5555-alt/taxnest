<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\PricingPlan;
use App\Models\Subscription;
use App\Models\SubscriptionInvoice;
use App\Models\WebsiteClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Tests\TestCase;

class WebsiteClientDirectoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_directory_groups_public_clients_and_only_serves_approved_eligible_logos(): void
    {
        $digital = $this->card('Fictional North Invoicing', 'di');
        $pra = $this->card('Fictional Sunrise Cafe', 'pos');
        $standalone = $this->card('Fictional Harbour Market', 'standalone');
        $fbr = $this->card('Fictional Valley Store', 'fbrpos');
        $erp = $this->card('Fictional Rose School', 'erp');
        $draft = $this->card('Fictional Private Draft', 'pos', published: false);
        $expired = $this->card('Fictional Expired Client', 'pos');
        Subscription::where('company_id', $expired->company_id)->update(['end_date' => now()->subDay()->toDateString()]);
        $mismatch = $this->card('Fictional Product Mismatch', 'pos');
        $mismatch->update(['product' => 'di']);

        $logo = UploadedFile::fake()->image('logo.png', 90, 40);
        $logoFields = ['logo_mime' => 'image/png', 'logo_data' => base64_encode(file_get_contents($logo->getPathname()))];
        $digital->forceFill($logoFields)->save();
        $draft->forceFill($logoFields)->save();
        $expired->forceFill($logoFields)->save();

        $url = route('website-clients.index');
        $digitalLogo = route('website-clients.logo', [$digital->id, $digital->revision]);
        $draftLogo = route('website-clients.logo', [$draft->id, $draft->revision]);
        $expiredLogo = route('website-clients.logo', [$expired->id, $expired->revision]);
        $this->get($url)->assertOk()->assertSeeInOrder([
            'id="directory-group-di"', $digital->display_name,
            'id="directory-group-pos"',
            'id="directory-group-fbrpos"', $fbr->display_name,
            'id="directory-group-erp"', $erp->display_name,
        ], false)->assertSee($digitalLogo, false)
            ->assertDontSee($draft->display_name)->assertDontSee($draftLogo, false)
            ->assertDontSee($expired->display_name)->assertDontSee($expiredLogo, false)
            ->assertDontSee($mismatch->display_name);
        $this->get($url.'?product=pos')->assertOk()->assertSee($pra->display_name)
            ->assertSee($standalone->display_name)->assertSee('Standalone POS')
            ->assertDontSee($digital->display_name)->assertDontSee($digitalLogo, false)
            ->assertDontSee($fbr->display_name)->assertDontSee($erp->display_name);
        $this->get($digitalLogo)->assertOk()->assertHeader('Content-Type', 'image/png');
        $this->getJson($draftLogo)->assertNotFound();
        $this->getJson($expiredLogo)->assertNotFound();
    }

    public function test_only_eligible_cards_consume_slots_across_paginated_product_results(): void
    {
        $this->freezeTime();
        for ($number = 1; $number <= 14; $number++) {
            $this->card(sprintf('Fictional Counter %02d', $number), 'pos');
        }
        for ($number = 1; $number <= 16; $number++) {
            $card = $this->card(sprintf('Fictional Unpaid Counter %02d', $number), 'pos', paid: false);
            $card->forceFill(['published_at' => now()->addMinute()])->save();
        }

        $url = route('website-clients.index', ['product' => 'pos']);
        $firstPage = $this->get($url)->assertOk()->assertSee('Client directory pages')
            ->assertSee('Fictional Counter 01')->assertSee('Fictional Counter 12')
            ->assertDontSee('Fictional Counter 13')->assertDontSee('Fictional Unpaid Counter');
        $this->assertSame(12, substr_count($firstPage->getContent(), 'class="directory-card"'));

        $this->get($url.'&page=2')->assertOk()->assertSee('Fictional Counter 13')->assertSee('Fictional Counter 14')
            ->assertDontSee('Fictional Counter 12')->assertDontSee('Fictional Unpaid Counter')->assertSee('Previous');
        $this->get(route('website-clients.index', ['product' => 'di']))->assertOk()
            ->assertSee('No clients to show for Digital Invoice yet.')->assertDontSee('Fictional Counter');
    }

    private function card(string $name, string $product, bool $paid = true, bool $published = true): WebsiteClient
    {
        $type = match ($product) {
            'standalone' => 'pos',
            'erp' => 'erps',
            default => $product,
        };
        $vertical = $product === 'erp' ? 'school' : null;
        $company = Company::create([
            'name' => $name, 'ntn' => 'WC-DIR-'.Str::random(8), 'product_type' => $type,
            'erps_vertical' => $vertical, 'pos_integration_mode' => $product === 'standalone' ? 'standalone' : 'pra',
        ]);
        if ($paid) {
            $plan = PricingPlan::create([
                'name' => 'Fictional paid directory plan', 'product_type' => $type,
                'erps_vertical' => $vertical, 'price' => 1000, 'invoice_limit' => 100, 'is_trial' => false,
            ]);
            $term = Subscription::create([
                'company_id' => $company->id, 'pricing_plan_id' => $plan->id,
                'start_date' => today()->toDateString(), 'end_date' => today()->addMonth()->toDateString(),
                'active' => true, 'final_price' => 1000,
            ]);
            SubscriptionInvoice::create([
                'company_id' => $company->id, 'subscription_id' => $term->id,
                'amount' => 1000, 'due_date' => today()->toDateString(), 'paid_at' => now(), 'status' => 'paid',
            ]);
        }

        $card = new WebsiteClient(['display_name' => $name, 'product' => $product]);
        $card->forceFill(['company_id' => $company->id, 'published_at' => $published ? now() : null])->save();

        return $card->refresh();
    }
}
