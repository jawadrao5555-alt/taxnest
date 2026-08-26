<?php

namespace Tests\Feature;

use App\Support\PosPaymentBuckets;
use App\Support\PosPaymentLabels;
use Tests\TestCase;

/**
 * ONE wording for a payment method (owner, 26 Aug 2026).
 *
 * A prepaid delivery bill used to print "Qr Payment" on its info line and
 * "ONLINE / QR" in its boxed banner while reports called an ordinary card sale
 * "Debit Card" — three names for two things. These tests pin the vocabulary so
 * a future screen cannot quietly invent a fourth.
 */
class PosPaymentLabelTest extends TestCase
{
    public function test_card_aliases_all_read_as_one_word(): void
    {
        foreach (PosPaymentLabels::CARD_ALIASES as $alias) {
            $this->assertSame(__('pos.pm_card'), PosPaymentLabels::label($alias), $alias . ' must read as Card');
        }
    }

    /**
     * A credit card is offered as its OWN report filter, so it must keep its own
     * wording — otherwise the filter and the rows it returns disagree. It still
     * aggregates into the card bucket.
     */
    public function test_credit_card_keeps_its_own_wording(): void
    {
        $this->assertSame(__('pos.pm_credit_card'), PosPaymentLabels::label('credit_card'));
        $this->assertNotSame(__('pos.pm_card'), PosPaymentLabels::label('credit_card'));
        $this->assertSame('card', PosPaymentBuckets::bucket('credit_card'));
        $this->assertNotContains('credit_card', PosPaymentLabels::CARD_ALIASES);
        $this->assertContains('credit_card', PosPaymentBuckets::CARD_ALIASES);
    }

    public function test_online_and_qr_read_as_one_word(): void
    {
        foreach (PosPaymentLabels::ONLINE_ALIASES as $alias) {
            $this->assertSame(__('pos.pm_online'), PosPaymentLabels::label($alias), $alias . ' must read as the online wording');
        }

        // The rider "mark prepaid" flow stores exactly this value — the receipt
        // banner, the info line and every report must agree on it.
        $this->assertSame(__('pos.pm_online'), PosPaymentLabels::label('qr_payment'));
    }

    public function test_cash_khata_and_bank_have_their_own_wording(): void
    {
        $this->assertSame(__('pos.pm_cash'), PosPaymentLabels::label('cash'));
        $this->assertSame(__('pos.pm_khata'), PosPaymentLabels::label('credit'));
        $this->assertSame(__('pos.pm_khata'), PosPaymentLabels::label('khata'));
        $this->assertSame(__('pos.pm_bank'), PosPaymentLabels::label('bank_transfer'));
    }

    public function test_unknown_and_empty_values_are_never_hidden(): void
    {
        $this->assertSame('Some New Wallet', PosPaymentLabels::label('some_new_wallet'));
        $this->assertSame('—', PosPaymentLabels::label(null));
        $this->assertSame('—', PosPaymentLabels::label('   '));
    }

    public function test_case_and_padding_do_not_change_the_label(): void
    {
        $this->assertSame(__('pos.pm_online'), PosPaymentLabels::label(' QR_Payment '));
        $this->assertSame(__('pos.pm_card'), PosPaymentLabels::label('DEBIT_CARD'));
    }

    public function test_upper_is_the_same_label_upper_cased(): void
    {
        $this->assertSame(mb_strtoupper(__('pos.pm_online'), 'UTF-8'), PosPaymentLabels::upper('qr_payment'));
    }

    /**
     * Labels group loosely; AGGREGATION must not. 'qr_payment' stays out of the
     * card bucket (owner rule) even though it now shares wording nowhere else.
     */
    public function test_labels_do_not_leak_into_the_card_bucket(): void
    {
        $this->assertSame('other', PosPaymentBuckets::bucket('qr_payment'));
        $this->assertSame('card', PosPaymentBuckets::bucket('debit_card'));
        $this->assertSame('cash', PosPaymentBuckets::bucket('cash'));
    }
}
