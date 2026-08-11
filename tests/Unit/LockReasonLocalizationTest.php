<?php

namespace Tests\Unit;

use App\Services\SubscriptionAccessService;
use Tests\TestCase;

/**
 * Every DENIED reason hasAccess() can produce must localize for rur/ur shops
 * (task 487 — English leaked through the lock modal via service strings).
 */
class LockReasonLocalizationTest extends TestCase
{
    /** Raw English denial strings exactly as hasAccess() builds them. */
    private const DENIED_REASONS = [
        'No active subscription. Please subscribe to a plan.',
        'Your subscription is inactive. Contact admin.',
        'Your plan has expired. Contact admin.',
        'Your free trial has expired. Please subscribe to a plan.',
        'Free invoice limit not configured.',
        'Free trial invoice limit reached (25/25). Please subscribe to a plan.',
        'Free invoice limit reached (10/10). Please upgrade your plan.',
        'Temporary invoice allowance reached (5/5). Please subscribe to a plan.',
        // PlanLimitService quota reasons (task 496 — quota errors on billing paths)
        'Monthly bill limit reached (500/500 this month). Please contact admin.',
        'Monthly bill limit reached (500/500 bills this month on the Basic plan). Please upgrade your plan to keep billing.',
        'Team account limit reached (3/3). Please contact admin.',
        'Team account limit reached (3/3 on the Basic plan). Please upgrade your plan to add more accounts.',
    ];

    public function test_every_denied_reason_is_translated_in_rur_and_ur(): void
    {
        foreach (['rur', 'ur'] as $locale) {
            app()->setLocale($locale);
            foreach (self::DENIED_REASONS as $raw) {
                $out = SubscriptionAccessService::localizedLockReason($raw);
                $this->assertNotSame($raw, $out, "[$locale] reason not localized: $raw");
                $this->assertStringNotContainsString('pos.tl_reason', $out, "[$locale] missing lang key for: $raw");
                if ($locale === 'ur') {
                    $this->assertMatchesRegularExpression('/[\x{0600}-\x{06FF}]/u', $out, "[ur] no Urdu script in: $out");
                }
            }
        }
    }

    public function test_counts_are_substituted_into_localized_limit_reasons(): void
    {
        foreach (['en', 'rur', 'ur'] as $locale) {
            app()->setLocale($locale);
            foreach ([
                'Free trial invoice limit reached (25/30). Please subscribe to a plan.',
                'Free invoice limit reached (10/12). Please upgrade your plan.',
                'Temporary invoice allowance reached (5/7). Please subscribe to a plan.',
                'Monthly bill limit reached (450/500 this month). Please contact admin.',
                'Monthly bill limit reached (450/500 bills this month on the Basic plan). Please upgrade your plan to keep billing.',
                'Team account limit reached (2/3). Please contact admin.',
                'Team account limit reached (2/3 on the Basic plan). Please upgrade your plan to add more accounts.',
            ] as $raw) {
                $out = SubscriptionAccessService::localizedLockReason($raw);
                preg_match('/\((\d+)\/(\d+)[ )]/', $raw, $m);
                $this->assertStringContainsString($m[1] . '/' . $m[2], $out, "[$locale] counts lost in: $out");
                $this->assertStringNotContainsString(':used', $out);
                $this->assertStringNotContainsString(':limit', $out);
            }
        }
    }

    public function test_per_resource_plan_cap_messages_are_localized(): void
    {
        // CheckPlanLimit middleware fallback keys (task 498 — products/counters/team caps)
        $keys = ['tl_reason_products_cap', 'tl_reason_users_cap', 'tl_reason_terminals_cap', 'tl_reason_inventory_cap'];
        foreach (['en', 'rur', 'ur'] as $locale) {
            app()->setLocale($locale);
            foreach ($keys as $key) {
                $val = __('pos.' . $key, ['max' => 500]);
                $this->assertNotSame('pos.' . $key, $val, "[$locale] missing $key");
                if ($key !== 'tl_reason_inventory_cap') {
                    $this->assertStringContainsString('500', $val, "[$locale] :max not substituted in $key");
                }
                $this->assertStringNotContainsString(':max', $val);
                if ($locale !== 'en') {
                    $this->assertNotSame(__('pos.' . $key, ['max' => 500], 'en'), $val, "[$locale] $key still English");
                }
                if ($locale === 'ur') {
                    $this->assertMatchesRegularExpression('/[\x{0600}-\x{06FF}]/u', $val, "[ur] $key not Urdu script");
                }
            }
        }
    }

    public function test_renewal_form_billing_cycle_labels_are_localized(): void
    {
        $keys = ['cycle_monthly', 'cycle_quarterly', 'cycle_semi_annual', 'cycle_annual'];
        foreach (['rur', 'ur'] as $locale) {
            app()->setLocale($locale);
            foreach ($keys as $key) {
                $val = __('pos.' . $key);
                $this->assertNotSame('pos.' . $key, $val, "[$locale] missing $key");
                $this->assertNotSame(__('pos.' . $key, [], 'en'), $val, "[$locale] $key still English");
                if ($locale === 'ur') {
                    $this->assertMatchesRegularExpression('/[\x{0600}-\x{06FF}]/u', $val, "[ur] $key not Urdu script");
                }
            }
        }
        // The popup blades must build cycle labels from the keys, never literals.
        foreach ([
            resource_path('views/components/subscription-expiry-popup.blade.php'),
            resource_path('views/components/trial-lock-modal.blade.php'),
        ] as $blade) {
            $src = file_get_contents($blade);
            $this->assertStringNotContainsString("'label' => 'Monthly'", $src, basename($blade) . ' hardcodes cycle labels');
            $this->assertStringNotContainsString("'label' => 'Annual'", $src, basename($blade) . ' hardcodes cycle labels');
            $this->assertStringContainsString("__('pos.cycle_annual')", $src, basename($blade) . ' missing localized cycle labels');
        }
    }

    public function test_english_stays_english_and_unknown_strings_fall_through(): void
    {
        app()->setLocale('en');
        $this->assertSame(
            'Your plan has expired. Contact admin.',
            SubscriptionAccessService::localizedLockReason('Your plan has expired. Contact admin.')
        );
        $this->assertSame(
            'Free invoice limit not configured. Contact support.',
            SubscriptionAccessService::localizedLockReason('Free invoice limit not configured.')
        );
        app()->setLocale('ur');
        $this->assertSame('Some brand new reason.', SubscriptionAccessService::localizedLockReason('Some brand new reason.'));
    }
}
