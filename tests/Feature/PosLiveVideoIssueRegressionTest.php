<?php

namespace Tests\Feature;

use Tests\TestCase;

class PosLiveVideoIssueRegressionTest extends TestCase
{
    public function test_domain_notice_sits_outside_the_scrollable_main_in_both_pos_layouts(): void
    {
        foreach (['pos-app.blade.php', 'fbr-pos-app.blade.php'] as $file) {
            $blade = file_get_contents(resource_path('views/layouts/'.$file));
            $notice = strpos($blade, '<x-domain-move-notice />');
            $main = strpos($blade, '<main class=');

            $this->assertNotFalse($notice);
            $this->assertNotFalse($main);
            $this->assertLessThan($main, $notice, $file.' must render the fixed notice before scrollable main');
            $this->assertSame(1, substr_count($blade, '<x-domain-move-notice />'));
        }
    }

    public function test_restaurant_payment_retries_once_with_the_same_idempotency_key(): void
    {
        $blade = file_get_contents(resource_path('views/pos/universal.blade.php'));
        $start = strpos($blade, 'async processPayment(method)');
        $end = strpos($blade, 'async processPaymentManual(', $start);
        $payment = substr($blade, $start, $end - $start);

        $this->assertStringContainsString('if (!this.payAttemptUuid) this.payAttemptUuid = this._newOfflineUuid()', $payment);
        $this->assertStringContainsString('const holdRequest = () => this.fetchWithTimeout', $payment);
        $this->assertSame(2, substr_count($payment, 'holdRes = await holdRequest()'));
        $this->assertStringContainsString('window.TXT.pay_network_retry', $payment);
        $this->assertStringContainsString('this.showPayModal = true', $payment);
        $this->assertStringNotContainsString('check console (F12)', $payment);
    }

    public function test_customer_search_keeps_selection_until_an_explicit_replacement_or_clear(): void
    {
        $blade = file_get_contents(resource_path('views/pos/universal.blade.php'));
        $start = strpos($blade, 'onCustomerPhoneInput()');
        $end = strpos($blade, '// Item #2', $start);
        $input = substr($blade, $start, $end - $start);

        $this->assertStringContainsString('this.selectedCustomer && q.length === 0', $input);
        $this->assertSame(1, substr_count($input, 'this.selectedCustomer = null'));
        $this->assertStringContainsString('Number(this.selectedCustomer?.id) !== selectedId', $blade);
    }
}