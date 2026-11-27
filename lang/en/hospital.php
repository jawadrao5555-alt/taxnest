<?php
return [
    'unassigned_scope' => 'Older invoices without a branch (owner only)',
    'current_scope' => 'Current branch invoices',
    'unassigned_notice' => 'Owner-only unassigned scope. Existing debt remains in its original ledger; settlements and closing stay separate from the active branch. New invoices are not created in this scope.',

 'title'=>'Hospital / Clinic collections', 'collect'=>'Record payment', 'history'=>'Invoices / Reprint', 'day_close'=>'Day close',
 'blocked'=>'LOCAL COLLECTION ONLY. Hospital fiscal submission is disabled until an official reporting route and accepted sandbox response are verified.',
 'scope'=>'Administrative patient reference only. Do not enter diagnoses or clinical notes. Recording a payment does not charge a card or transfer funds.',
 'patient'=>'Patient reference', 'service'=>'Service head', 'consultation'=>'Consultation', 'lab'=>'Lab', 'procedure'=>'Procedure',
 'total'=>'Invoice total', 'amount'=>'Payment received', 'method'=>'Payment method', 'cash'=>'Cash', 'card'=>'Card', 'bank'=>'Bank',
 'confirm'=>'I confirm this payment has already been received. Do not charge or collect it again.',
 'positive_amount'=>'Enter a positive payment and invoice total.', 'confirm_required'=>'Confirm the payment was already received.',
 'overpayment'=>'Payment exceeds the remaining invoice balance.', 'retry_conflict'=>'This payment ID is already saved with different details. Open the original receipt; do not collect again.',
 'erp_cutover_new_collection'=>'Create new bills in Hospital ERP. This account can still collect outstanding balances on existing bills.',
 'campus_changed'=>'The active branch changed. Return to the original branch and reload before retrying. No payment was recorded by this request.',
 'closed'=>'This branch/date is closed. New payments are disabled; an identical saved payment can still be recovered.',
 'recovery'=>'A previous payment may already be saved. Do not collect again. Re-enter the original details to recover the same receipt. Keep this tab open; recovery is scoped to this tab, account and branch.',
 'storage'=>'Recovery storage is unavailable. No new request was sent. Check saved receipts before recording any payment again.',
 'invoice'=>'Invoice', 'receipt'=>'Payment receipt', 'balance'=>'Remaining balance', 'receipt_balance'=>'Balance after this payment', 'paid'=>'Paid to date',
 'campus'=>'Branch', 'cashier'=>'Cashier', 'date'=>'Date', 'print'=>'Print / Reprint', 'more'=>'Record another payment', 'search'=>'Search invoice ID or patient reference', 'empty'=>'No matching invoices.',
 'count'=>'Receipt count', 'counted'=>'Counted cash', 'variance'=>'Cash variance', 'note'=>'Variance explanation', 'close'=>'Confirm day close',
 'close_confirm'=>'I confirm the counted cash and permanent closing snapshot.', 'close_changed'=>'Collections changed. Refresh the totals and confirm closing again.',
 'close_conflict'=>'This day is already closed with different counted cash or explanation. The original closing snapshot is unchanged.',
 'variance_required'=>'Explain any difference between counted and expected cash.', 'open'=>'Open', 'closed_label'=>'Closed',
 'close_policy'=>'Calendar-date closing freezes cash, card and bank totals separately. Only cash is compared with the drawer count. No reopening or new same-date payments after close.',
];

