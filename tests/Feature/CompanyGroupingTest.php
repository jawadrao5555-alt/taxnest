<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CompanyGroup;
use App\Models\CompanyGroupMember;
use App\Models\User;
use App\Services\CompanyGroupService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Business grouping (5 Sep 2026).
 *
 * One NTN can be registered with both FBR and PRA, so the same person may run
 * three separate accounts. Grouping shows US that they are one customer — and
 * being WRONG about that is the expensive failure: a shop merged into somebody
 * else's group means support answers from a false picture. These tests pin the
 * rules that decide when two accounts are the same person and when they are
 * merely sharing a phone number.
 */
class CompanyGroupingTest extends TestCase
{
    use RefreshDatabase;

    private function makeCompany(array $attributes = []): Company
    {
        static $n = 0;
        $n++;

        $company = Company::create(array_merge([
            'name'           => "Company {$n}",
            'owner_name'     => "Owner {$n}",
            'product_type'   => 'pos',
            'email'          => "shop{$n}@business.pk",
            // Unique, non-filler defaults: companies.(ntn|cnic, product_type)
            // are composite-unique, and a repeated value would group the
            // fixtures by accident.
            'ntn'            => '9' . str_pad((string) (4210000 + $n * 137), 7, '0', STR_PAD_LEFT),
            'cnic'           => '35202' . str_pad((string) (1370000 + $n * 813), 8, '0', STR_PAD_LEFT),
            'phone'          => '0300' . str_pad((string) (1000000 + $n), 7, '0', STR_PAD_LEFT),
            'status'         => 'approved',
            'company_status' => 'active',
        ], $attributes));

        User::create([
            'name'       => "Owner {$n}",
            'email'      => $attributes['owner_email'] ?? "owner{$n}@business.pk",
            'password'   => Hash::make('secret123'),
            'company_id' => $company->id,
            'role'       => 'company_admin',
        ]);

        return $company->fresh();
    }

    public function test_same_cnic_across_products_forms_one_group(): void
    {
        $pra = $this->makeCompany(['product_type' => 'pos', 'cnic' => '3620291786117']);
        $fbr = $this->makeCompany(['product_type' => 'fbrpos', 'cnic' => '3620291786117']);

        CompanyGroupService::rebuild();

        $praMember = CompanyGroupMember::where('company_id', $pra->id)->first();
        $fbrMember = CompanyGroupMember::where('company_id', $fbr->id)->first();

        $this->assertNotNull($praMember, 'PRA account should be grouped');
        $this->assertNotNull($fbrMember, 'FBR account should be grouped');
        $this->assertSame($praMember->company_group_id, $fbrMember->company_group_id);
        $this->assertSame('strong', $fbrMember->strength);
        $this->assertSame('cnic', $fbrMember->match_type);
    }

    public function test_each_account_keeps_its_own_identity_code(): void
    {
        $pra = $this->makeCompany(['product_type' => 'pos', 'ntn' => '7408263']);
        $di  = $this->makeCompany(['product_type' => 'di', 'ntn' => '7408263']);

        $this->assertStringStartsWith('PRA-', $pra->account_code);
        $this->assertStringStartsWith('DI-', $di->account_code);
        $this->assertNotSame($pra->account_code, $di->account_code);
    }

    public function test_filler_values_never_group_anyone(): void
    {
        // Seed rows and rushed sign-ups carry these; they identify nobody.
        $a = $this->makeCompany(['ntn' => '9999999999999', 'cnic' => null]);
        $b = $this->makeCompany(['ntn' => '9999999999999', 'cnic' => '', 'product_type' => 'di']);
        $c = $this->makeCompany(['ntn' => '1234567890', 'product_type' => 'fbrpos']);
        $d = $this->makeCompany(['ntn' => '1234567890', 'product_type' => 'di']);

        CompanyGroupService::rebuild();

        foreach ([$a, $b, $c, $d] as $company) {
            $this->assertNull(
                CompanyGroupMember::where('company_id', $company->id)->first(),
                "{$company->name} must not be grouped on a filler value"
            );
        }
    }

    public function test_demo_phone_numbers_are_not_evidence(): void
    {
        // 0300-1234567 keeps a real network code and fakes the rest.
        $a = $this->makeCompany(['phone' => '03001234567', 'mobile' => '03001234567']);
        $b = $this->makeCompany(['phone' => '0300-1234567', 'mobile' => '', 'product_type' => 'di']);
        $c = $this->makeCompany(['phone' => '03211111111', 'product_type' => 'fbrpos']);
        $d = $this->makeCompany(['phone' => '+92 321 1111111', 'product_type' => 'health']);

        CompanyGroupService::rebuild();

        foreach ([$a, $b, $c, $d] as $company) {
            $this->assertNull(
                CompanyGroupMember::where('company_id', $company->id)->first(),
                "{$company->name} must not be grouped on a demo phone number"
            );
        }
    }

    public function test_a_widely_shared_phone_stops_counting_as_evidence(): void
    {
        // An accountant's number on many unrelated shops is not a group.
        $shared = '03211234567';
        $companies = [];
        for ($i = 0; $i < 5; $i++) {
            $companies[] = $this->makeCompany(['phone' => $shared, 'mobile' => $shared]);
        }

        CompanyGroupService::rebuild();

        $grouped = CompanyGroupMember::whereIn('company_id', collect($companies)->pluck('id'))->count();
        $this->assertSame(0, $grouped, 'a phone shared by five businesses must not merge them');
    }

    public function test_two_accounts_on_one_phone_still_group(): void
    {
        $shared = '03005550123';
        $a = $this->makeCompany(['phone' => $shared, 'product_type' => 'pos']);
        $b = $this->makeCompany(['phone' => $shared, 'product_type' => 'di']);

        CompanyGroupService::rebuild();

        $memberA = CompanyGroupMember::where('company_id', $a->id)->first();
        $memberB = CompanyGroupMember::where('company_id', $b->id)->first();
        $this->assertNotNull($memberB);
        $this->assertSame($memberA->company_group_id, $memberB->company_group_id);
        $this->assertSame('weak', $memberB->strength);
    }

    public function test_detach_is_remembered_by_the_next_automatic_pass(): void
    {
        $a = $this->makeCompany(['cnic' => '3520112345678']);
        $b = $this->makeCompany(['cnic' => '3520112345678', 'product_type' => 'di']);

        CompanyGroupService::rebuild();
        $this->assertNotNull(CompanyGroupMember::where('company_id', $b->id)->first());

        CompanyGroupService::detach($b->fresh());
        CompanyGroupService::rebuild();

        $this->assertNull(
            CompanyGroupMember::where('company_id', $b->id)->first(),
            'a detached account must not be re-joined automatically'
        );
    }

    public function test_a_correction_prunes_a_membership_that_no_longer_holds(): void
    {
        $a = $this->makeCompany(['cnic' => '3520112345678']);
        $b = $this->makeCompany(['cnic' => '3520112345678', 'product_type' => 'di']);

        CompanyGroupService::rebuild();
        $this->assertNotNull(CompanyGroupMember::where('company_id', $b->id)->first());

        // The CNIC was a typo — once corrected, the tie must disappear.
        $b->forceFill(['cnic' => '3520199999999'])->save();
        CompanyGroupService::rebuild();

        $this->assertNull(CompanyGroupMember::where('company_id', $b->id)->first());
        $this->assertSame(0, CompanyGroup::count(), 'a group of one is noise and should dissolve');
    }

    public function test_a_manual_link_survives_the_automatic_pass(): void
    {
        $a = $this->makeCompany(['cnic' => '3520112345678']);
        $b = $this->makeCompany(['cnic' => '3520112345678', 'product_type' => 'di']);
        $unrelated = $this->makeCompany(['product_type' => 'fbrpos']);

        CompanyGroupService::rebuild();
        $group = CompanyGroupMember::where('company_id', $a->id)->first()->group;

        CompanyGroupService::attach($group, $unrelated, 'manual', null, true);
        CompanyGroupService::rebuild();

        $member = CompanyGroupMember::where('company_id', $unrelated->id)->first();
        $this->assertNotNull($member, 'an admin link outranks the automatic rules');
        $this->assertSame('manual', $member->strength);
    }
}
