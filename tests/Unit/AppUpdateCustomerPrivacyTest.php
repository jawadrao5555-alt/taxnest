<?php

namespace Tests\Unit;

use App\Models\AppUpdate;
use App\Support\CustomerFacingUpdateTitle;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AppUpdateCustomerPrivacyTest extends TestCase
{
    public static function unsafeContent(): array
    {
        return [
            ['Production deploy completed', ['Customers can continue billing']],
            ['New stock receiving', ['Deploy SHA: 7b4c545dea16330bf844d41e41f23beb9e40fa37']],
            ['Printer improvement', ['Live Ops callback succeeded']],
            ['Faster billing', ['Database migration completed']],
            ['System update', ['Workflow and server cache version changed']],
        ];
    }

    #[DataProvider('unsafeContent')]
    public function test_operational_release_details_are_rejected(string $title, array $points): void
    {
        $this->assertTrue(AppUpdate::containsOperationalDetails($title, $points));
    }

    public function test_customer_benefit_copy_is_allowed(): void
    {
        $this->assertFalse(AppUpdate::containsOperationalDetails(
            'Kitchen printing is more reliable',
            [
                'Shared kitchen printers now work safely across multiple counters.',
                'Your existing printer settings remain unchanged.',
            ]
        ));
    }

    public function test_customer_title_strips_deploy_suffix_and_keeps_the_stored_title(): void
    {
        $sha = 'aac4820f29cfa0432ff39e0fb1b4e51f305edb36';
        $stored = 'Kitchen printing is more reliable [deploy '.$sha.']';
        $update = new AppUpdate(['title' => $stored]);

        $this->assertTrue(CustomerFacingUpdateTitle::containsDeploySuffix($stored));
        $this->assertSame('Kitchen printing is more reliable', $update->customerTitle());
        $this->assertSame($stored, $update->title);
        $this->assertSame(
            'Kitchen printing is more reliable',
            CustomerFacingUpdateTitle::display($stored)
        );
        $this->assertSame(
            'Already customer safe',
            CustomerFacingUpdateTitle::display('Already customer safe')
        );
        $this->assertSame('', CustomerFacingUpdateTitle::display(null));
    }
}
