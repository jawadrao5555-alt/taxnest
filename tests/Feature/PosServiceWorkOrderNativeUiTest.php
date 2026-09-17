<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\PosServiceWorkOrder;
use App\Models\User;
use App\Services\PosFeatureService;
use App\Services\PosServiceWorkflowProfiles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Full route/form coverage for the service profiles completed in Phase H.
 * Uses SQLite only; the invoice path runs with PRA reporting disabled.
 */
class PosServiceWorkOrderNativeUiTest extends TestCase
{
    use RefreshDatabase;

    protected function beforeRefreshingDatabase()
    {
        if (!in_array('sqlite', \PDO::getAvailableDrivers(), true)) {
            $this->markTestSkipped('pdo_sqlite is required for these tests (phpunit.xml uses sqlite :memory:).');
        }
    }

    protected function setUp(): void
    {
        parent::setUp();
        PosFeatureService::flushGateCaches();
        PosFeatureService::assumeExtrasColumn(true);
    }

    protected function tearDown(): void
    {
        PosFeatureService::assumeExtrasColumn(null);
        PosFeatureService::flushGateCaches();
        parent::tearDown();
    }

    #[DataProvider('remainingServiceProfileProvider')]
    public function test_every_completed_service_profile_has_a_role_aware_mobile_safe_form_lifecycle_and_bill(string $category): void
    {
        $company = $this->company($category);
        $owner = $this->owner($company, 'owner-'.$category);
        $profile = PosServiceWorkflowProfiles::forCompany($company);

        $index = $this->actingAs($owner, 'pos')->get('/pos/work-orders')->assertOk();
        $index->assertSee('data-service-workflow="'.$category.'"', false);
        $index->assertSee($profile['noun'].' Board', false);

        $form = $this->actingAs($owner, 'pos')->get('/pos/work-orders/create')->assertOk();
        $form->assertSee('data-service-create="'.$category.'"', false);
        $form->assertSee('min-w-0', false);
        foreach (PosServiceWorkflowProfiles::requiredFields($profile) as $field) {
            $form->assertSee($profile['fields'][$field].' *', false);
        }

        $details = [];
        foreach ($profile['fields'] as $field => $label) {
            $details[$field] = 'Fictional '.$field;
        }
        $payload = [
            'customer_name' => 'Fictional Customer',
            'customer_phone' => '03000000000',
            'title' => 'Fictional '.$profile['noun'],
            'quantity' => 1,
            'unit_price' => 500,
            'details' => $details,
        ];
        if ($profile['schedule']) {
            $payload['scheduled_at'] = '2030-01-01T10:00';
        }

        $this->actingAs($owner, 'pos')->post('/pos/work-orders', $payload)->assertRedirect();
        $order = PosServiceWorkOrder::where('company_id', $company->id)
            ->where('category', $category)
            ->sole();
        $this->assertSame($profile['stages'][0], $order->status);
        $this->assertSame($details, $order->details);

        while (!in_array($order->status, $profile['terminal'], true)) {
            $next = PosServiceWorkflowProfiles::nextStatuses($profile, $order->status);
            $this->assertNotEmpty($next, "{$category} must provide a path to closure");
            $this->actingAs($owner, 'pos')->post('/pos/work-orders/'.$order->id.'/transition', [
                'to_status' => $next[0],
            ])->assertRedirect();
            $order->refresh();
        }

        $this->actingAs($owner, 'pos')->post('/pos/work-orders/'.$order->id.'/invoice', [
            'payment_method' => 'cash',
        ])->assertRedirect();
        $this->assertNotNull($order->fresh()->pos_transaction_id);

        $allowed = $this->owner($company, 'allowed-'.$category, 'pos_cashier', ['dashboard', 'service_jobs']);
        $allowedHtml = $this->actingAs($allowed, 'pos')->get('/pos/work-orders')->assertOk()->getContent();
        $this->assertStringContainsString($profile['noun'].' Board', $allowedHtml);
        $this->actingAs($allowed, 'pos')->get('/pos/work-orders/report.csv')->assertOk();

        $denied = $this->owner($company, 'denied-'.$category, 'pos_cashier', ['dashboard']);
        $this->actingAs($denied, 'pos')->get('/pos/work-orders')->assertRedirect('/pos/dashboard');
        $this->actingAs($denied, 'pos')->get('/pos/work-orders/report.csv')->assertRedirect('/pos/dashboard');
    }

    public static function remainingServiceProfileProvider(): array
    {
        return array_map(
            fn (string $category) => [$category],
            [
                'gym', 'event_management', 'travel_agent', 'property_dealer', 'advertising', 'it_services',
                'security_services', 'clinic', 'education', 'consultant', 'architect', 'construction',
                'manpower', 'warehouse', 'media_production', 'entertainment', 'financial_services', 'other_service',
            ]
        );
    }

    private function company(string $category): Company
    {
        $flags = PosFeatureService::defaultsForCategory($category);

        return Company::create([
            'name' => 'Native '.$category,
            'ntn' => (string) random_int(100000000, 999999999),
            'email' => uniqid('native-work-order-', true).'@test.pk',
            'status' => 'approved',
            'company_status' => 'active',
            'product_type' => 'pos',
            'pos_integration_mode' => 'pra',
            'business_category' => $category,
            'feature_flags' => $flags,
            'restaurant_mode' => PosFeatureService::restaurantModeFrom($flags),
            'is_internal_account' => true,
            'pos_setup_completed' => true,
            'pos_tax_rate_cash' => 0,
            'pos_tax_rate_card' => 0,
        ]);
    }

    private function owner(Company $company, string $emailPrefix, string $posRole = 'pos_admin', ?array $access = null): User
    {
        $user = User::create([
            'name' => 'Native Workflow User',
            'email' => $emailPrefix.'-'.uniqid().'@test.pk',
            'password' => Hash::make('Secret@12345'),
            'company_id' => $company->id,
            'role' => $posRole === 'pos_admin' ? 'company_admin' : 'staff',
            'pos_role' => $posRole,
            'is_active' => true,
            'pra_reporting_enabled' => false,
        ]);
        if ($access !== null) {
            $user->forceFill(['pos_custom_access' => json_encode($access)])->save();
        }

        return $user->fresh();
    }
}