<?php
namespace Tests\Feature;

use App\Models\Company;
use App\Services\PosFeatureService;
use Tests\TestCase;

class LocalCollectionCategoryEntitlementsTest extends TestCase
{
    protected function setUp(): void { parent::setUp(); PosFeatureService::assumeExtrasColumn(true); PosFeatureService::flushGateCaches(); }
    protected function tearDown(): void { PosFeatureService::assumeExtrasColumn(null); PosFeatureService::flushGateCaches(); parent::tearDown(); }

    public function test_local_category_mapping_does_not_change_purchased_or_grandfathered_configuration(): void
    {
        foreach (['education', 'clinic'] as $index => $category) {
            $company = new Company(['product_type' => 'fbrpos', 'business_category' => $category,
                'pos_type' => $category, 'feature_flags' => ['service_jobs' => true, 'inventory' => true],
                'pos_module_extras' => ['inventory' => ['source' => 'grandfathered', 'reason' => 'Existing purchase']],
                'is_internal_account' => true]);
            $company->id = 800 + $index;
            $before = $company->getAttributes();
            $profile = PosFeatureService::profile($company);
            $this->assertSame('local_administrative_collections', $profile['workflow']);
            $this->assertNotSame('service_work_orders', $profile['landing']);
            $this->assertFalse(PosFeatureService::moduleRelevant($company, 'service_jobs'));
            $this->assertTrue(PosFeatureService::moduleRelevant($company, 'inventory'), 'Grandfathered grant survives category mapping');
            $this->assertTrue(PosFeatureService::moduleRelevant($company, 'reports_enabled'));
            $this->assertSame($before, $company->getAttributes(), 'No commercial or stored settings mutation');
        }
    }

    public function test_pra_school_and_clinic_service_mapping_and_explicit_admin_grants_are_preserved(): void
    {
        foreach (['education', 'clinic'] as $index => $category) {
            $pra = new Company(['product_type' => 'pos', 'business_category' => $category]); $pra->id = 900 + $index;
            $this->assertSame('typed_work_order', PosFeatureService::profile($pra)['workflow']);
            $this->assertTrue(PosFeatureService::moduleRelevant($pra, 'service_jobs'));
            $fbr = new Company(['product_type' => 'fbrpos', 'business_category' => $category,
                'pos_module_extras' => ['service_jobs' => ['source' => 'admin', 'reason' => 'Retained legacy entitlement']]]);
            $fbr->id = 950 + $index;
            $this->assertTrue(PosFeatureService::moduleRelevant($fbr, 'service_jobs'));
            // Explicit grants are retained as entitlement data, but the local workspace
            // still refuses retail mutating routes (SchoolWorkspaceSecurityTest).
        }
    }
    public function test_legacy_standalone_hospital_alias_does_not_fall_back_to_general_retail(): void
    {
        $company = new Company(['product_type' => 'fbrpos', 'business_category' => 'hospital', 'pos_type' => 'hospital']);
        $company->id = 999;
        $this->assertSame('clinic', PosFeatureService::profileCategory($company));
        $this->assertSame('hospital_collection_desk', PosFeatureService::profile($company)['landing']);
        $this->assertFalse(PosFeatureService::moduleRelevant($company, 'service_jobs'));
        $this->assertFalse(PosFeatureService::moduleRelevant($company, 'kot'));
    }

}
