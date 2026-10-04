<?php

return [
    'fiscal_locked' => 'Fiscal bill locked. Cancellation, editing and credit-note reversal are unavailable here. The original bill and payments remain unchanged.',
    'title' => 'Correct / cancel entry',
    'help' => 'Cancel the entire erroneous stay. Source bills remain in history; local return records reverse local sales and folio entries reverse charges and recorded money. Fiscal bills cannot be corrected through this screen.',
    'charges' => 'Charges to reverse',
    'money' => 'Recorded money to refund / reverse',
    'reason' => 'Reason for correction',
    'method' => 'Refund / reversal payment method',
    'confirm' => 'I confirm this whole stay is erroneous. Any received money has been returned through the selected method, or the payment itself was an erroneous entry. I have reviewed the amounts and linked bills.',
    'submit' => 'Confirm correction',
    'done' => 'Stay corrected. Source records retained; charges and recorded money reversed. Review credit-note submission status where applicable.',
    'closed' => 'This stay is already cancelled or cannot be corrected in this state.',
    'review' => 'This entry needs review: previous adjustments/returns, a closed business day, incomplete invoice linkage or inconsistent recorded money prevent automatic correction. No data has been changed.',
    'stale' => 'The stay or its bill/payment changed. Review the refreshed amounts before confirming.',
    'cash' => 'Cash',
    'card' => 'Card',
    'back' => 'Back to stay',
];
