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

    public function test_legacy_build_date_notice_is_plain_without_rewriting_history_or_identity(): void
    {
        $old = 'Build date packaging se aye gi; metadata na ho to unavailable bataya jaye ga.';
        $update = new AppUpdate(['id' => 12, 'title' => 'Desktop Agent par actual version aur build date', 'points' => [$old, 'Saved company, device aur printer settings barqarar rahengi.']]);
        $update->syncOriginal();
        $identity = $update->contentKey();
        $display = $update->customerPoints();
        $this->assertStringNotContainsString('metadata', $display[0]);
        $this->assertStringNotContainsString('packaging', $display[0]);
        $this->assertStringContainsString('Settings', $display[0]);
        $this->assertStringContainsString('Build date available nahi', $display[0]);
        $this->assertSame($old, $update->points[0]);
        $this->assertSame($identity, $update->contentKey());
        $this->assertSame($update->points[1], $display[1]);
        $this->assertFalse($update->isDirty());
    }

    public function test_other_languages_and_unrelated_notice_points_are_not_rewritten(): void
    {
        $points = ['آپ کی پرنٹر سیٹنگ محفوظ ہے۔', 'Your saved printer stays selected.'];
        $this->assertSame($points, \App\Support\CustomerFacingUpdateText::points($points));
        $this->assertSame([], \App\Support\CustomerFacingUpdateText::points([]));
    }
}
