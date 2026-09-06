<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Healthcare accounting & settlements schema (Task 1552).
 *
 * Twelve healthcare-owned tables that turn the billing, pharmacy, HR and
 * supplier records the panel already keeps into real double-entry books.
 *
 * FOUR structural promises are encoded here and must never be softened:
 *
 *  1. EVERY JOURNAL BALANCES. A journal is written with its own debit and
 *     credit totals and the posting service refuses to save one where they
 *     differ. There is no "unbalanced but saved" state to clean up later,
 *     because a ledger that can hold one is not a ledger.
 *  2. A POSTING NAMES ITS SOURCE, AND ONLY ONCE. source_type/source_id and the
 *     unique dedupe_key mean the bill, receipt, purchase or expense behind any
 *     ledger line is reachable forever, and re-running the posting sweep can
 *     never double-count. Idempotency lives in the database, not in a caller's
 *     good intentions.
 *  3. A POSTED JOURNAL IS NEVER EDITED OR DELETED. It is reversed by a second
 *     journal that points back at it. The mistake and its correction both stay
 *     readable, which is the entire reason a hospital keeps books.
 *  4. A CLOSED PERIOD IS FROZEN. Nothing may be dated into a closed period; a
 *     later correction posts as an adjustment in an open period carrying the
 *     closed period it corrects. Re-opening the past would silently rewrite
 *     every statement already handed out.
 *
 * Every add is individually hasTable/hasColumn guarded, the same as the
 * foundation and HR migrations: the owner's PROD box has a history of
 * migrations marked "Ran" whose columns never landed, so this file must be safe
 * to re-run.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ─────────────────────────────────────────────────────────────────
        // CHART OF ACCOUNTS
        //
        // `system_key` is what the posting engine resolves against — never an
        // id and never a name. An owner may rename "Cash in Hand" to anything
        // they like and the receipts still land in the same account; a renamed
        // account that broke posting would be discovered a month later by an
        // out-of-balance trial balance.
        // ─────────────────────────────────────────────────────────────────
        if (!Schema::hasTable('health_accounts')) {
            Schema::create('health_accounts', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('company_id');
                $table->unsignedBigInteger('parent_id')->nullable();

                $table->string('code', 20);
                $table->string('name', 190);

                // asset | liability | equity | income | expense
                $table->string('type', 12);
                // Presentation grouping inside a type: current_asset, bank,
                // receivable, payable, direct_income, cost_of_sales, operating…
                $table->string('subtype', 32)->nullable();

                // Cash-flow classification for the statement: operating |
                // investing | financing. NULL on balance-sheet-only accounts.
                $table->string('cash_flow', 12)->nullable();

                // Set on the accounts the engine itself needs. A system account
                // may be renamed and re-coded but never deleted or retyped.
                $table->string('system_key', 40)->nullable();
                $table->boolean('is_system')->default(false);

                // Money the day-close and the bank reconciliation care about.
                $table->boolean('is_cash')->default(false);
                $table->boolean('is_bank')->default(false);

                // Opening balance is stored on the account AND posted as an
                // opening journal, so the trial balance is complete on day one
                // without the two ever being able to disagree.
                $table->decimal('opening_balance', 16, 2)->default(0);
                $table->date('opening_balance_date')->nullable();

                $table->boolean('is_active')->default(true);
                $table->string('notes', 500)->nullable();
                $table->unsignedBigInteger('created_by')->nullable();
                $table->timestamps();

                $table->unique(['company_id', 'code'], 'health_acct_code_unique');
                $table->unique(['company_id', 'system_key'], 'health_acct_syskey_unique');
                $table->index(['company_id', 'type', 'is_active'], 'health_acct_type_idx');
            });
        }

        // ─────────────────────────────────────────────────────────────────
        // FISCAL PERIODS
        //
        // One row per accounting month (the service creates them lazily). The
        // period is the thing that gets closed, not the year, because a
        // hospital reconciles and reports monthly and cannot wait until March
        // to stop people back-dating into January.
        // ─────────────────────────────────────────────────────────────────
        if (!Schema::hasTable('health_fiscal_periods')) {
            Schema::create('health_fiscal_periods', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('company_id');
                $table->string('name', 40);          // 2026-11
                $table->date('starts_on');
                $table->date('ends_on');

                // open | closed
                $table->string('status', 12)->default('open');
                $table->timestamp('closed_at')->nullable();
                $table->unsignedBigInteger('closed_by')->nullable();
                $table->string('close_note', 500)->nullable();

                // Frozen at close: the trial balance as it stood. A statement
                // printed today and the same statement printed next year must
                // agree, and the only way to guarantee that for a period that
                // can still receive adjustment journals is to keep the snapshot.
                $table->text('closing_snapshot')->nullable();

                $table->timestamps();

                $table->unique(['company_id', 'name'], 'health_fp_name_unique');
                $table->index(['company_id', 'starts_on', 'ends_on'], 'health_fp_range_idx');
            });
        }

        // ─────────────────────────────────────────────────────────────────
        // JOURNALS (entry header)
        // ─────────────────────────────────────────────────────────────────
        if (!Schema::hasTable('health_journals')) {
            Schema::create('health_journals', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('company_id');
                $table->unsignedBigInteger('branch_id')->nullable();
                $table->unsignedBigInteger('health_fiscal_period_id')->nullable();

                $table->string('journal_no', 32);
                $table->date('journal_date');

                // auto      posted by the source sweep from a bill/receipt/…
                // manual    typed by the accountant
                // opening   an account's opening balance
                // adjustment a correction to an already-closed period
                // closing   the period-close entry
                $table->string('type', 16)->default('auto');

                // WHAT PRODUCED THIS ENTRY. bill | payment | advance_applied |
                // purchase | supplier_payment | pharmacy_sale | pharmacy_cogs |
                // pharmacy_return | expense | transfer | doctor_settlement |
                // manual | opening
                $table->string('source_type', 32)->nullable();
                $table->unsignedBigInteger('source_id')->nullable();
                // The human identifier of that source (B000123, R000412, PO-9…)
                $table->string('source_reference', 120)->nullable();

                $table->string('memo', 500)->nullable();

                $table->decimal('total_debit', 16, 2)->default(0);
                $table->decimal('total_credit', 16, 2)->default(0);

                // posted | reversed
                $table->string('status', 12)->default('posted');
                $table->timestamp('posted_at')->nullable();
                $table->unsignedBigInteger('posted_by')->nullable();

                // The reversal pair. Both sides are recorded so a journal can be
                // read forwards ("this was undone by…") and backwards.
                $table->unsignedBigInteger('reversed_by_journal_id')->nullable();
                $table->unsignedBigInteger('reverses_journal_id')->nullable();
                $table->timestamp('reversed_at')->nullable();
                $table->unsignedBigInteger('reversed_by')->nullable();
                $table->string('reversal_reason', 300)->nullable();

                // An adjustment names the closed period it corrects, so the
                // month's own report can show "and this arrived afterwards".
                $table->unsignedBigInteger('adjusts_period_id')->nullable();

                // Idempotency. One source event = one journal, forever.
                $table->string('dedupe_key', 120)->nullable();

                $table->timestamps();

                $table->unique(['company_id', 'journal_no'], 'health_jrn_no_unique');
                $table->unique(['company_id', 'dedupe_key'], 'health_jrn_dedupe_unique');
                $table->index(['company_id', 'journal_date'], 'health_jrn_date_idx');
                $table->index(['company_id', 'source_type', 'source_id'], 'health_jrn_source_idx');
                $table->index(['company_id', 'status'], 'health_jrn_status_idx');
            });
        }

        // ─────────────────────────────────────────────────────────────────
        // JOURNAL LINES
        //
        // The dimensions ride on the LINE, not the header: one bill can serve
        // three departments and one settlement can cover two doctors, and a
        // header-level dimension would force either a lie or five journals.
        // ─────────────────────────────────────────────────────────────────
        if (!Schema::hasTable('health_journal_lines')) {
            Schema::create('health_journal_lines', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('company_id');
                $table->unsignedBigInteger('health_journal_id');
                $table->unsignedBigInteger('health_account_id');

                $table->unsignedSmallInteger('line_no')->default(1);

                // Exactly one of these is non-zero on any line.
                $table->decimal('debit', 16, 2)->default(0);
                $table->decimal('credit', 16, 2)->default(0);

                // ── Dimensions ──
                $table->unsignedBigInteger('branch_id')->nullable();
                $table->unsignedBigInteger('health_department_id')->nullable();
                $table->unsignedBigInteger('health_doctor_id')->nullable();
                $table->unsignedBigInteger('health_patient_id')->nullable();
                $table->unsignedBigInteger('supplier_id')->nullable();

                // Drill-down target for the report screens: the row this line
                // came from, even when the journal groups several of them.
                $table->string('source_type', 32)->nullable();
                $table->unsignedBigInteger('source_id')->nullable();

                $table->date('entry_date')->nullable();
                $table->string('memo', 300)->nullable();
                $table->timestamps();

                $table->index(['company_id', 'health_account_id', 'entry_date'], 'health_jl_acct_idx');
                $table->index(['health_journal_id'], 'health_jl_journal_idx');
                $table->index(['company_id', 'health_doctor_id'], 'health_jl_doctor_idx');
                $table->index(['company_id', 'supplier_id'], 'health_jl_supplier_idx');
                $table->index(['company_id', 'health_department_id'], 'health_jl_dept_idx');
            });
        }

        // ─────────────────────────────────────────────────────────────────
        // BANK ACCOUNTS
        //
        // A named bank account the hospital actually holds, mapped onto its own
        // ledger account. Deposits and transfers move money between these.
        // ─────────────────────────────────────────────────────────────────
        if (!Schema::hasTable('health_bank_accounts')) {
            Schema::create('health_bank_accounts', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('company_id');
                $table->unsignedBigInteger('branch_id')->nullable();
                $table->unsignedBigInteger('health_account_id')->nullable();

                $table->string('title', 190);
                $table->string('bank_name', 190)->nullable();
                // Stored as the shop typed it; nothing here authorises a payment,
                // so it is a reference, not a credential.
                $table->string('account_no', 64)->nullable();
                $table->string('iban', 40)->nullable();
                $table->string('branch_name', 190)->nullable();

                $table->decimal('opening_balance', 16, 2)->default(0);
                $table->date('opening_balance_date')->nullable();

                $table->boolean('is_active')->default(true);
                $table->string('notes', 500)->nullable();
                $table->timestamps();

                $table->index(['company_id', 'is_active'], 'health_bank_active_idx');
            });
        }

        // ─────────────────────────────────────────────────────────────────
        // EXPENSE CATEGORIES + EXPENSES
        // ─────────────────────────────────────────────────────────────────
        if (!Schema::hasTable('health_expense_categories')) {
            Schema::create('health_expense_categories', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('company_id');
                $table->string('name', 190);
                // Which ledger account this category books into. Required in
                // practice — a category with no account cannot post.
                $table->unsignedBigInteger('health_account_id')->nullable();
                $table->boolean('is_active')->default(true);
                $table->timestamps();

                $table->unique(['company_id', 'name'], 'health_expcat_name_unique');
            });
        }

        if (!Schema::hasTable('health_expenses')) {
            Schema::create('health_expenses', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('company_id');
                $table->unsignedBigInteger('branch_id')->nullable();
                $table->unsignedBigInteger('health_department_id')->nullable();
                $table->unsignedBigInteger('health_expense_category_id')->nullable();

                $table->string('expense_no', 32);
                $table->date('expense_date');

                $table->string('payee', 190)->nullable();
                $table->unsignedBigInteger('supplier_id')->nullable();

                $table->decimal('amount', 16, 2)->default(0);
                $table->decimal('tax_amount', 16, 2)->default(0);
                $table->decimal('total_amount', 16, 2)->default(0);

                // cash | bank | credit  — credit raises a payable instead of
                // paying anything, which is how an unpaid utility bill becomes
                // a liability rather than a missing expense.
                $table->string('pay_mode', 12)->default('cash');
                // The cash/bank account it left. NULL for credit.
                $table->unsignedBigInteger('paid_from_account_id')->nullable();

                $table->string('reference', 120)->nullable();
                $table->string('description', 500)->nullable();

                // posted | reversed
                $table->string('status', 12)->default('posted');
                $table->timestamp('reversed_at')->nullable();
                $table->unsignedBigInteger('reversed_by')->nullable();
                $table->string('reversal_reason', 300)->nullable();

                $table->unsignedBigInteger('created_by')->nullable();
                $table->timestamps();

                $table->unique(['company_id', 'expense_no'], 'health_exp_no_unique');
                $table->index(['company_id', 'expense_date'], 'health_exp_date_idx');
                $table->index(['company_id', 'health_expense_category_id'], 'health_exp_cat_idx');
            });
        }

        // ─────────────────────────────────────────────────────────────────
        // FUND TRANSFERS — cash to bank, bank to cash, bank to bank.
        //
        // A bank deposit is a transfer, not its own document type: the money
        // leaves the drawer and arrives in the bank, and modelling it twice is
        // how the two sides stop agreeing.
        // ─────────────────────────────────────────────────────────────────
        if (!Schema::hasTable('health_fund_transfers')) {
            Schema::create('health_fund_transfers', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('company_id');
                $table->unsignedBigInteger('branch_id')->nullable();

                $table->string('transfer_no', 32);
                $table->date('transfer_date');

                // deposit | withdrawal | transfer
                $table->string('kind', 12)->default('deposit');

                $table->unsignedBigInteger('from_account_id');
                $table->unsignedBigInteger('to_account_id');
                $table->decimal('amount', 16, 2)->default(0);

                $table->string('reference', 120)->nullable();
                $table->string('notes', 500)->nullable();

                $table->string('status', 12)->default('posted');
                $table->timestamp('reversed_at')->nullable();
                $table->unsignedBigInteger('reversed_by')->nullable();
                $table->string('reversal_reason', 300)->nullable();

                $table->unsignedBigInteger('created_by')->nullable();
                $table->timestamps();

                $table->unique(['company_id', 'transfer_no'], 'health_xfer_no_unique');
                $table->index(['company_id', 'transfer_date'], 'health_xfer_date_idx');
            });
        }

        // ─────────────────────────────────────────────────────────────────
        // DOCTOR SHARE RULES
        //
        // Resolution is most-specific-wins: a rule naming this doctor AND this
        // category beats one naming only the category, which beats the
        // organisation default. Priority breaks a genuine tie so an owner can
        // always force an answer rather than discovering the system picked one.
        // ─────────────────────────────────────────────────────────────────
        if (!Schema::hasTable('health_doctor_share_rules')) {
            Schema::create('health_doctor_share_rules', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('company_id');
                // NULL = applies to every doctor (the organisation default).
                $table->unsignedBigInteger('health_doctor_id')->nullable();
                $table->unsignedBigInteger('health_department_id')->nullable();
                $table->unsignedBigInteger('branch_id')->nullable();

                $table->string('name', 190)->nullable();

                // A health_charges.category, or 'all'.
                $table->string('charge_category', 20)->default('all');

                // percent | fixed
                $table->string('basis', 10)->default('percent');
                $table->decimal('value', 12, 2)->default(0);

                // Which number the percentage bites on:
                // net (after concession, before tax) | gross | total (incl tax)
                //
                // Default is NET on purpose. Paying a share of tax hands the
                // doctor money the hospital owes the regulator, and paying a
                // share of gross makes the hospital fund a concession it granted
                // on the doctor's behalf.
                $table->string('base', 10)->default('net');

                // Floor / ceiling per accrual, both optional.
                $table->decimal('min_amount', 14, 2)->nullable();
                $table->decimal('max_amount', 14, 2)->nullable();

                $table->date('effective_from')->nullable();
                $table->date('effective_to')->nullable();

                $table->unsignedSmallInteger('priority')->default(0);
                $table->boolean('is_active')->default(true);
                $table->string('notes', 500)->nullable();

                $table->unsignedBigInteger('created_by')->nullable();
                $table->timestamps();

                $table->index(['company_id', 'health_doctor_id', 'is_active'], 'health_dsr_doctor_idx');
                $table->index(['company_id', 'charge_category'], 'health_dsr_cat_idx');
            });
        }

        // ─────────────────────────────────────────────────────────────────
        // DOCTOR SHARE ACCRUALS
        //
        // One row per charge per doctor. Keyed by the charge so the accrual
        // sweep can run as often as it likes without paying anybody twice.
        // ─────────────────────────────────────────────────────────────────
        if (!Schema::hasTable('health_doctor_shares')) {
            Schema::create('health_doctor_shares', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('company_id');
                $table->unsignedBigInteger('branch_id')->nullable();
                $table->unsignedBigInteger('health_department_id')->nullable();
                $table->unsignedBigInteger('health_doctor_id');

                $table->unsignedBigInteger('health_charge_id')->nullable();
                $table->unsignedBigInteger('health_bill_id')->nullable();
                $table->unsignedBigInteger('health_patient_id')->nullable();
                $table->unsignedBigInteger('health_doctor_share_rule_id')->nullable();

                $table->date('accrual_date');
                $table->string('charge_category', 20)->nullable();
                $table->string('description', 300)->nullable();

                // The rule as it was applied, frozen. A rule edited next month
                // must not silently restate what was already paid.
                $table->string('basis', 10)->default('percent');
                $table->decimal('rate', 12, 2)->default(0);
                $table->string('base', 10)->default('net');
                $table->decimal('base_amount', 14, 2)->default(0);
                $table->decimal('share_amount', 14, 2)->default(0);

                // accrued | excluded | approved | settled | reversed
                $table->string('status', 12)->default('accrued');

                $table->unsignedBigInteger('health_doctor_settlement_id')->nullable();

                $table->string('exclusion_reason', 300)->nullable();
                $table->unsignedBigInteger('excluded_by')->nullable();
                $table->timestamp('excluded_at')->nullable();

                $table->timestamp('reversed_at')->nullable();
                $table->unsignedBigInteger('reversed_by')->nullable();
                $table->string('reversal_reason', 300)->nullable();

                // charge:{id}:doctor:{id}
                $table->string('dedupe_key', 120)->nullable();

                $table->timestamps();

                $table->unique(['company_id', 'dedupe_key'], 'health_dsh_dedupe_unique');
                $table->index(['company_id', 'health_doctor_id', 'status'], 'health_dsh_doctor_idx');
                $table->index(['company_id', 'accrual_date'], 'health_dsh_date_idx');
                $table->index(['company_id', 'health_doctor_settlement_id'], 'health_dsh_settlement_idx');
            });
        }

        // ─────────────────────────────────────────────────────────────────
        // DOCTOR SETTLEMENTS
        // ─────────────────────────────────────────────────────────────────
        if (!Schema::hasTable('health_doctor_settlements')) {
            Schema::create('health_doctor_settlements', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('company_id');
                $table->unsignedBigInteger('branch_id')->nullable();
                $table->unsignedBigInteger('health_doctor_id');

                $table->string('settlement_no', 32);
                $table->date('period_from');
                $table->date('period_to');

                $table->unsignedInteger('share_count')->default(0);
                $table->decimal('gross_amount', 16, 2)->default(0);
                $table->decimal('deduction_amount', 16, 2)->default(0);
                $table->string('deduction_reason', 300)->nullable();
                $table->decimal('net_amount', 16, 2)->default(0);
                $table->decimal('paid_amount', 16, 2)->default(0);

                // draft | approved | paid | reversed
                $table->string('status', 12)->default('draft');

                $table->timestamp('approved_at')->nullable();
                $table->unsignedBigInteger('approved_by')->nullable();
                $table->timestamp('paid_at')->nullable();
                $table->unsignedBigInteger('paid_by')->nullable();
                $table->string('pay_method', 16)->nullable();
                $table->unsignedBigInteger('paid_from_account_id')->nullable();
                $table->string('pay_reference', 120)->nullable();

                $table->timestamp('reversed_at')->nullable();
                $table->unsignedBigInteger('reversed_by')->nullable();
                $table->string('reversal_reason', 300)->nullable();

                $table->string('notes', 500)->nullable();
                $table->unsignedBigInteger('created_by')->nullable();
                $table->timestamps();

                $table->unique(['company_id', 'settlement_no'], 'health_dset_no_unique');
                $table->index(['company_id', 'health_doctor_id', 'status'], 'health_dset_doctor_idx');
            });
        }

        // ─────────────────────────────────────────────────────────────────
        // ACCOUNT RECONCILIATIONS
        //
        // A signed-off statement of "what the books say vs what the bank /
        // drawer says on this date". The difference is stored rather than
        // forced to zero: an unexplained 300 rupees is information, and a
        // reconciliation that cannot record one gets abandoned instead of used.
        // ─────────────────────────────────────────────────────────────────
        if (!Schema::hasTable('health_account_reconciliations')) {
            Schema::create('health_account_reconciliations', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('company_id');
                $table->unsignedBigInteger('branch_id')->nullable();
                $table->unsignedBigInteger('health_account_id');

                $table->date('statement_date');
                $table->date('period_from')->nullable();

                $table->decimal('book_balance', 16, 2)->default(0);
                $table->decimal('statement_balance', 16, 2)->default(0);
                $table->decimal('difference', 16, 2)->default(0);

                // open | closed
                $table->string('status', 12)->default('open');
                // The adjustment journal raised to clear the difference, if any.
                $table->unsignedBigInteger('adjustment_journal_id')->nullable();

                $table->string('notes', 500)->nullable();
                $table->timestamp('closed_at')->nullable();
                $table->unsignedBigInteger('closed_by')->nullable();
                $table->unsignedBigInteger('created_by')->nullable();
                $table->timestamps();

                $table->index(['company_id', 'health_account_id', 'statement_date'], 'health_recon_acct_idx');
            });
        }

        // ─────────────────────────────────────────────────────────────────
        // Accounts-module settings. One row per company.
        // ─────────────────────────────────────────────────────────────────
        if (!Schema::hasTable('health_accounting_settings')) {
            Schema::create('health_accounting_settings', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('company_id');

                // Month the financial year starts in (1–12). Pakistan's tax year
                // runs July–June, so that is the default rather than January.
                $table->unsignedTinyInteger('fiscal_year_start_month')->default(7);

                // Whether the source sweep may post automatically. Off means the
                // books exist but nothing lands in them without a human press —
                // which is what a hospital mid-migration actually wants.
                $table->boolean('auto_post_enabled')->default(true);

                // Doctor shares accrue on: billed | collected.
                //
                // 'collected' pays the consultant only for money that actually
                // arrived. A clinic whose receivables never age chooses 'billed';
                // one that carries panel patients for ninety days does not.
                $table->string('doctor_share_basis', 12)->default('billed');

                $table->boolean('doctor_shares_enabled')->default(true);

                $table->date('books_start_date')->nullable();
                $table->timestamp('last_posted_at')->nullable();

                $table->timestamps();

                $table->unique('company_id', 'health_acctset_company_unique');
            });
        }

        // ─────────────────────────────────────────────────────────────────
        // One column onto an existing table.
        //
        // Applying a patient's advance to a bill SPLITS the deposit when the
        // advance is bigger than the bill: part becomes a receipt against the
        // bill, the rest stays as credit. Both rows then look like cash that
        // arrived at the counter, and the books would count the same money
        // twice.
        //
        // This records that the child came out of a parent rather than out of a
        // patient's pocket, so the ledger can credit the cash once and treat the
        // split purely as an allocation. Deriving it from notes or references
        // would guess; a column knows.
        // ─────────────────────────────────────────────────────────────────
        if (Schema::hasTable('health_payments') && !Schema::hasColumn('health_payments', 'split_from_payment_id')) {
            Schema::table('health_payments', function (Blueprint $table) {
                $table->unsignedBigInteger('split_from_payment_id')->nullable()->after('health_bill_id');
                $table->index(['company_id', 'split_from_payment_id'], 'health_pay_split_idx');
            });
        }
    }

    public function down(): void
    {
        foreach ([
            'health_accounting_settings',
            'health_account_reconciliations',
            'health_doctor_settlements',
            'health_doctor_shares',
            'health_doctor_share_rules',
            'health_fund_transfers',
            'health_expenses',
            'health_expense_categories',
            'health_bank_accounts',
            'health_journal_lines',
            'health_journals',
            'health_fiscal_periods',
            'health_accounts',
        ] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
