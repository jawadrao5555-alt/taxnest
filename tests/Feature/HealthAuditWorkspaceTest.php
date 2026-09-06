<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\HealthAuditEvent;
use App\Models\HealthAuditFinding;
use App\Models\HealthAuditNote;
use App\Models\HealthAuditRun;
use App\Models\HealthBill;
use App\Models\HealthCharge;
use App\Models\HealthDepartment;
use App\Models\HealthDoctor;
use App\Models\HealthPatient;
use App\Models\HealthPayment;
use App\Models\HealthVisit;
use App\Models\User;
use App\Services\HealthAccessService;
use App\Services\HealthScopeService;
use App\Services\HealthAudit\HealthAuditContext;
use App\Services\HealthAudit\HealthAuditEngine;
use App\Services\HealthAudit\HealthAuditPackService;
use App\Services\HealthAudit\HealthAuditRecorder;
use App\Services\HealthAudit\HealthAuditRules;
use App\Support\HealthPanel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * OWNER ONE-CLICK AUDIT (Task 1554).
 *
 * Runs against the REAL migrations, so every check's SQL is under test
 * alongside its behaviour — a rule that reads a column the schema does not have
 * fails here rather than on the owner's screen.
 *
 * What an audit feature has to be right about, and nothing else counts:
 *
 *  1. EVERY CHECK EXECUTES — 40 rules, none of them silently skipped. A check
 *     that throws must be reported as a check that did not run, never absorbed
 *     into "nothing found".
 *  2. IT FINDS THE PLANTED PROBLEM — a reversal with no reason, a bill whose
 *     receipts disagree, a visit left open. If a deliberate hole does not
 *     surface, the green screen means nothing.
 *  3. IT IS REPRODUCIBLE — the same period, the same rules, the same answer.
 *     An audit that varies between presses cannot be evidence of anything.
 *  4. A DECISION SURVIVES A RERUN — an acknowledged finding comes back
 *     acknowledged next month instead of re-opening and drowning the owner.
 *  5. THE TRAIL IS TAMPER-EVIDENT — editing or deleting a recorded act is
 *     detectable, and the models refuse the edit in the first place.
 *  6. CLINICAL NARRATIVE NEVER ENTERS THE TRAIL — the audit records that a note
 *     changed, never what the note said. This is what lets an auditor without
 *     clinical access read the trail at all.
 *  7. THE ROLES SPLIT — an auditor reads and exports; only the owner records a
 *     decision. An auditor who can close their own findings is not a control.
 *  8. THE PACK IS SIGNED — and a pack that no longer matches its signature is
 *     refused rather than handed over with a warning.
 *
 * Run:
 *   php vendor/bin/phpunit tests/Feature/HealthAuditWorkspaceTest.php --testdox
 */
class HealthAuditWorkspaceTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;
    private User $owner;
    private User $auditor;
    private HealthDoctor $doctor;

    protected function setUp(): void
    {
        parent::setUp();

        // The recorder memoises each company's chain tip for the life of the
        // process. RefreshDatabase empties the table under it, so without this
        // the first event of every test but the first would point its prev_hash
        // at a row that no longer exists — a gap the verifier would rightly, and
        // uselessly, report.
        HealthAuditRecorder::forgetChainTip();

        // Same story for the per-process branch/department memo: user ids are
        // reused from test to test, so a stale entry would hand this test's
        // ward auditor the previous test's posting.
        HealthScopeService::forget();

        $this->company = Company::create([
            'name' => 'Shifa Audit Test',
            'ntn' => 'AUD-TEST-1',
            'product_type' => HealthPanel::PRODUCT_TYPE,
            'status' => 'approved',
            'company_status' => 'active',
            'health_org_type' => 'hospital',
            'health_modules' => json_encode(['opd', 'billing', 'accounts', 'pharmacy', 'ipd', 'hr']),
        ]);

        $this->owner = User::create([
            'name' => 'Audit Owner',
            'email' => 'auditowner@example.test',
            'password' => Hash::make('Passw0rd!2026'),
            'company_id' => $this->company->id,
            'role' => 'company_admin',
            'health_role' => HealthAccessService::ROLE_OWNER,
            'is_active' => true,
        ]);

        $this->auditor = User::create([
            'name' => 'External Auditor',
            'email' => 'auditor@example.test',
            'password' => Hash::make('Passw0rd!2026'),
            'company_id' => $this->company->id,
            'role' => 'user',
            'health_role' => 'health_auditor',
            'is_active' => true,
        ]);

        $this->doctor = HealthDoctor::create([
            'company_id' => $this->company->id,
            'name' => 'Dr Physician',
            'consultation_fee' => 1000,
            'is_active' => true,
        ]);
    }

    // ═══════════════════════════════════════════════════════════════════
    // HELPERS
    // ═══════════════════════════════════════════════════════════════════

    private int $seq = 0;

    private function companyId(): int
    {
        return (int) $this->company->id;
    }

    private function context(?string $from = null, ?string $to = null): HealthAuditContext
    {
        return new HealthAuditContext(
            companyId: $this->companyId(),
            from: $from ?: now()->subDays(30)->toDateString(),
            to: $to ?: now()->toDateString(),
            preset: 'last_30',
        );
    }

    private function actor(?User $user = null): array
    {
        $user = $user ?: $this->owner;

        return [
            'user_id' => $user->id,
            'actor_name' => $user->name,
            'actor_role' => HealthAccessService::roleFor($user),
        ];
    }

    private function patient(): HealthPatient
    {
        $this->seq++;

        return HealthPatient::create([
            'company_id' => $this->companyId(),
            'mrn' => 'MR' . str_pad((string) $this->seq, 6, '0', STR_PAD_LEFT),
            'name' => 'Patient ' . $this->seq,
            'gender' => 'male',
            'age_years' => 40,
            'is_active' => true,
        ]);
    }

    /** A charge that was reversed, optionally without anybody saying why. */
    private function reversedCharge(bool $withReason): HealthCharge
    {
        $patient = $this->patient();

        return HealthCharge::create([
            'company_id' => $this->companyId(),
            'health_patient_id' => $patient->id,
            'charge_no' => 'C' . str_pad((string) $this->seq, 6, '0', STR_PAD_LEFT),
            'charge_date' => now()->subDays(3)->toDateString(),
            'category' => 'opd',
            'description' => 'Consultation',
            'unit_price' => 1000,
            'quantity' => 1,
            'gross_amount' => 1000,
            'concession_amount' => 0,
            'net_amount' => 1000,
            'total_amount' => 1000,
            'status' => 'reversed',
            'reversed_at' => now()->subDays(2),
            'reversed_by' => $this->owner->id,
            'reversal_reason' => $withReason ? 'Billed to the wrong patient file' : null,
            'created_by' => $this->owner->id,
        ]);
    }

    // ═══════════════════════════════════════════════════════════════════
    // 1. EVERY CHECK EXECUTES
    // ═══════════════════════════════════════════════════════════════════

    public function test_every_registered_check_runs_against_the_real_schema(): void
    {
        $run = HealthAuditEngine::run($this->context(), $this->actor());

        $this->assertSame('ready', $run->status);
        $this->assertSame(
            0,
            (int) $run->rules_failed,
            'A check could not run against the real schema: ' . $run->error_message
        );
        $this->assertSame(count(HealthAuditRules::all()), (int) $run->rules_run);
        $this->assertGreaterThanOrEqual(40, (int) $run->rules_run);
    }

    public function test_a_clean_period_reports_how_much_was_examined(): void
    {
        // The distinction that matters: "we looked at nothing" must not read the
        // same as "we looked at everything and it was fine". A clean run has to
        // carry its own denominator.
        $run = HealthAuditEngine::run($this->context(), $this->actor());

        $this->assertSame(0, (int) $run->findings_total);
        $this->assertSame(100, (int) $run->risk_score);
        $this->assertSame(
            HealthAuditEvent::where('company_id', $this->companyId())->count(),
            (int) $run->events_scanned
        );
    }

    public function test_a_period_with_no_activity_at_all_says_nothing_was_examined(): void
    {
        // Two days that predate the hospital: nothing happened, and the run must
        // say so rather than report a clean bill of health.
        $run = HealthAuditEngine::run(
            $this->context(now()->subDays(400)->toDateString(), now()->subDays(399)->toDateString()),
            $this->actor()
        );

        $this->assertSame(0, (int) $run->findings_total);
        $this->assertSame(0, (int) $run->events_scanned);
    }

    // ═══════════════════════════════════════════════════════════════════
    // 2. IT FINDS THE PLANTED PROBLEM
    // ═══════════════════════════════════════════════════════════════════

    public function test_a_reversal_without_a_reason_is_raised_and_one_with_a_reason_is_separated(): void
    {
        $silent = $this->reversedCharge(false);
        $explained = $this->reversedCharge(true);

        $run = HealthAuditEngine::run($this->context(), $this->actor());

        $silentFinding = HealthAuditFinding::where('health_audit_run_id', $run->id)
            ->where('rule_key', 'charge_reversed_no_reason')->first();
        $explainedFinding = HealthAuditFinding::where('health_audit_run_id', $run->id)
            ->where('rule_key', 'charge_reversed')->first();

        $this->assertNotNull($silentFinding, 'A reversal with no reason must be raised');
        $this->assertSame((int) $silent->id, (int) $silentFinding->entity_id);

        // The explained one is still recorded — a reversal is worth seeing — but
        // it is a different rule, so the owner can read the unanswerable ones on
        // their own.
        $this->assertNotNull($explainedFinding);
        $this->assertSame((int) $explained->id, (int) $explainedFinding->entity_id);
        $this->assertNotSame($silentFinding->severity, 'info');
    }

    public function test_a_bill_that_disagrees_with_its_receipts_is_raised_with_the_variance(): void
    {
        $patient = $this->patient();

        $bill = HealthBill::create([
            'company_id' => $this->companyId(),
            'health_patient_id' => $patient->id,
            'bill_no' => 'B000900',
            'doc_type' => HealthBill::TYPE_INVOICE,
            'status' => HealthBill::STATUS_FINALIZED,
            'bill_date' => now()->subDays(2)->toDateString(),
            'business_date' => now()->subDays(2)->toDateString(),
            'gross_amount' => 5000,
            'net_amount' => 5000,
            'total_amount' => 5000,
            'patient_payable' => 5000,
            'paid_amount' => 5000,
            'outstanding_amount' => 0,
        ]);

        // The bill claims 5000 was collected; only 3000 of receipts exists.
        HealthPayment::create([
            'company_id' => $this->companyId(),
            'health_bill_id' => $bill->id,
            'health_patient_id' => $patient->id,
            'receipt_no' => 'R000900',
            'kind' => 'payment',
            'amount' => 3000,
            'method' => 'cash',
            'received_at' => now()->subDays(2),
            'received_by' => $this->owner->id,
            'business_date' => now()->subDays(2)->toDateString(),
        ]);

        $run = HealthAuditEngine::run($this->context(), $this->actor());

        $finding = HealthAuditFinding::where('health_audit_run_id', $run->id)
            ->where('rule_key', 'bill_payment_mismatch')->first();

        $this->assertNotNull($finding, 'A bill and its receipts disagreeing must be raised');
        $this->assertEquals(2000.0, round((float) $finding->variance, 2));
        $this->assertSame('critical', $finding->severity);
    }

    public function test_a_visit_left_open_is_raised(): void
    {
        $patient = $this->patient();

        HealthVisit::create([
            'company_id' => $this->companyId(),
            'health_patient_id' => $patient->id,
            'health_doctor_id' => $this->doctor->id,
            'visit_no' => 'V000900',
            'visit_date' => now()->subDays(5)->toDateString(),
            'visit_type' => 'new',
            'status' => 'in_consultation',
            'fee_amount' => 1000,
        ]);

        $run = HealthAuditEngine::run($this->context(), $this->actor());

        $this->assertTrue(
            HealthAuditFinding::where('health_audit_run_id', $run->id)
                ->where('rule_key', 'visit_left_open')->exists()
        );
    }

    public function test_a_finding_keeps_the_records_it_was_derived_from(): void
    {
        $charge = $this->reversedCharge(false);

        $run = HealthAuditEngine::run($this->context(), $this->actor());
        $finding = HealthAuditFinding::where('health_audit_run_id', $run->id)
            ->where('rule_key', 'charge_reversed_no_reason')->firstOrFail();

        $evidence = $finding->evidence;

        $this->assertIsArray($evidence);
        $this->assertArrayHasKey('charge', $evidence);
        $this->assertSame((int) $charge->id, (int) $evidence['charge']['id']);

        // The rule version travels with the finding, so a threshold moved next
        // year cannot make this month's finding look like a different judgement.
        $this->assertSame(HealthAuditRules::VERSION, $finding->rule_version);
    }

    // ═══════════════════════════════════════════════════════════════════
    // 3. IT IS REPRODUCIBLE
    // ═══════════════════════════════════════════════════════════════════

    public function test_the_same_period_run_twice_gives_the_same_answer(): void
    {
        $this->reversedCharge(false);
        $this->reversedCharge(true);

        $first = HealthAuditEngine::run($this->context(), $this->actor());
        $second = HealthAuditEngine::run($this->context(), $this->actor());

        $this->assertSame($first->result_hash, $second->result_hash);
        $this->assertSame($first->findings_total, $second->findings_total);
        $this->assertSame($first->risk_score, $second->risk_score);

        // Different runs, same question — which is what makes them comparable.
        $this->assertNotSame($first->id, $second->id);
        $this->assertSame($first->filters_hash, $second->filters_hash);
    }

    public function test_a_narrower_scope_is_a_different_question(): void
    {
        $wide = HealthAuditEngine::run($this->context(), $this->actor());

        $narrow = HealthAuditEngine::run(new HealthAuditContext(
            companyId: $this->companyId(),
            from: now()->subDays(30)->toDateString(),
            to: now()->toDateString(),
            doctorId: (int) $this->doctor->id,
            preset: 'last_30',
        ), $this->actor());

        $this->assertNotSame($wide->filters_hash, $narrow->filters_hash);
    }

    // ═══════════════════════════════════════════════════════════════════
    // 4. A DECISION SURVIVES A RERUN
    // ═══════════════════════════════════════════════════════════════════

    public function test_an_acknowledged_finding_comes_back_acknowledged(): void
    {
        $this->reversedCharge(false);

        $first = HealthAuditEngine::run($this->context(), $this->actor());
        $finding = HealthAuditFinding::where('health_audit_run_id', $first->id)
            ->where('rule_key', 'charge_reversed_no_reason')->firstOrFail();

        $finding->update([
            'status' => 'acknowledged',
            'status_note' => 'Asked reception; genuine wrong-file entry.',
            'status_by' => $this->owner->id,
            'status_by_name' => $this->owner->name,
            'status_at' => now(),
        ]);

        $second = HealthAuditEngine::run($this->context(), $this->actor());
        $again = HealthAuditFinding::where('health_audit_run_id', $second->id)
            ->where('fingerprint', $finding->fingerprint)->firstOrFail();

        $this->assertSame('acknowledged', $again->status);
        $this->assertSame($this->owner->name, $again->status_by_name);
        $this->assertNotSame($finding->id, $again->id);
    }

    public function test_a_fingerprint_does_not_move_when_the_run_does(): void
    {
        $this->reversedCharge(false);

        $a = HealthAuditEngine::run($this->context(), $this->actor());
        $b = HealthAuditEngine::run($this->context(), $this->actor());

        $fa = HealthAuditFinding::where('health_audit_run_id', $a->id)
            ->where('rule_key', 'charge_reversed_no_reason')->firstOrFail();
        $fb = HealthAuditFinding::where('health_audit_run_id', $b->id)
            ->where('rule_key', 'charge_reversed_no_reason')->firstOrFail();

        $this->assertSame($fa->fingerprint, $fb->fingerprint);
    }

    // ═══════════════════════════════════════════════════════════════════
    // 5. THE TRAIL IS TAMPER-EVIDENT
    // ═══════════════════════════════════════════════════════════════════

    public function test_recorded_acts_refuse_to_be_edited_or_deleted(): void
    {
        $event = HealthAuditRecorder::record('billing.charge.reversed', [
            'company_id' => $this->companyId(),
            'actor' => $this->owner,
            'entity_type' => 'health_charges',
            'entity_id' => 1,
            'amount' => 1000,
        ]);

        $this->assertNotNull($event);

        $this->expectException(\RuntimeException::class);
        $event->update(['amount' => 1]);
    }

    public function test_a_recorded_act_refuses_to_be_deleted(): void
    {
        $event = HealthAuditRecorder::record('billing.charge.reversed', [
            'company_id' => $this->companyId(),
            'actor' => $this->owner,
            'entity_type' => 'health_charges',
            'entity_id' => 2,
        ]);

        $this->expectException(\RuntimeException::class);
        $event->delete();
    }

    public function test_editing_the_trail_behind_the_models_back_is_still_detected(): void
    {
        // Setting the hospital up already wrote to the trail (a doctor joined
        // the catalogue), so count from where we actually are.
        $before = HealthAuditEvent::where('company_id', $this->companyId())->count();

        for ($i = 0; $i < 5; $i++) {
            HealthAuditRecorder::record('billing.charge.created', [
                'company_id' => $this->companyId(),
                'actor' => $this->owner,
                'entity_type' => 'health_charges',
                'entity_id' => $i,
                'amount' => 100 + $i,
            ]);
        }

        $clean = HealthAuditRecorder::verifyChain(
            $this->companyId(),
            now()->subDay()->toDateString(),
            now()->addDay()->toDateString()
        );

        $this->assertSame($before + 5, $clean['checked']);
        $this->assertSame(0, $clean['altered'], 'A trail nobody touched must verify clean');
        $this->assertSame(0, $clean['missing']);

        // Straight SQL, bypassing the model's refusal entirely — the way
        // somebody with database access would actually do it.
        $target = HealthAuditEvent::where('company_id', $this->companyId())->orderBy('id')->skip(2)->first();
        DB::table('health_audit_events')->where('id', $target->id)->update(['amount' => 999999]);

        $tampered = HealthAuditRecorder::verifyChain(
            $this->companyId(),
            now()->subDay()->toDateString(),
            now()->addDay()->toDateString()
        );

        $this->assertSame(1, $tampered['altered']);
        $this->assertContains((int) $target->id, $tampered['altered_ids']);
    }

    public function test_deleting_a_row_from_the_trail_leaves_a_gap_that_is_reported(): void
    {
        for ($i = 0; $i < 5; $i++) {
            HealthAuditRecorder::record('billing.charge.created', [
                'company_id' => $this->companyId(),
                'actor' => $this->owner,
                'entity_type' => 'health_charges',
                'entity_id' => $i,
            ]);
        }

        $target = HealthAuditEvent::where('company_id', $this->companyId())->orderBy('id')->skip(2)->first();
        DB::table('health_audit_events')->where('id', $target->id)->delete();

        $result = HealthAuditRecorder::verifyChain(
            $this->companyId(),
            now()->subDay()->toDateString(),
            now()->addDay()->toDateString()
        );

        $this->assertGreaterThanOrEqual(1, $result['missing']);
    }

    public function test_an_investigation_note_cannot_be_rewritten(): void
    {
        $this->reversedCharge(false);
        $run = HealthAuditEngine::run($this->context(), $this->actor());
        $finding = HealthAuditFinding::where('health_audit_run_id', $run->id)->firstOrFail();

        $note = HealthAuditNote::create([
            'company_id' => $this->companyId(),
            'health_audit_finding_id' => $finding->id,
            'user_id' => $this->owner->id,
            'actor_name' => $this->owner->name,
            'status_from' => 'open',
            'status_to' => 'investigating',
            'body' => 'Asked the counter for the till roll.',
            'created_at' => now(),
        ]);

        $this->expectException(\RuntimeException::class);
        $note->update(['body' => 'Nothing to see here.']);
    }

    // ═══════════════════════════════════════════════════════════════════
    // 6. CLINICAL NARRATIVE NEVER ENTERS THE TRAIL
    // ═══════════════════════════════════════════════════════════════════

    public function test_the_trail_records_that_a_note_changed_not_what_it_said(): void
    {
        $secret = 'Patient disclosed a psychiatric history and requested confidentiality.';

        $event = HealthAuditRecorder::record('clinical.visit.updated', [
            'company_id' => $this->companyId(),
            'actor' => $this->owner,
            'entity_type' => 'health_visits',
            'entity_id' => 1,
            'old' => ['clinical_notes' => 'Routine follow-up.'],
            'new' => ['clinical_notes' => $secret],
        ]);

        $raw = json_encode([$event->old_values, $event->new_values], JSON_UNESCAPED_UNICODE);

        $this->assertStringNotContainsString('psychiatric', $raw);
        $this->assertStringNotContainsString($secret, $raw);
        // It must still prove the note was rewritten.
        $this->assertStringContainsString('clinical_notes', $raw);
    }

    public function test_a_password_never_reaches_the_trail(): void
    {
        $event = HealthAuditRecorder::record('access.staff.updated', [
            'company_id' => $this->companyId(),
            'actor' => $this->owner,
            'entity_type' => 'users',
            'entity_id' => $this->auditor->id,
            'new' => ['password' => 'Sup3rSecret!', 'name' => 'Renamed'],
        ]);

        $raw = json_encode($event->new_values, JSON_UNESCAPED_UNICODE);

        $this->assertStringNotContainsString('Sup3rSecret!', $raw);
        $this->assertStringContainsString('Renamed', $raw);
    }

    // ═══════════════════════════════════════════════════════════════════
    // 7. THE ROLES SPLIT
    // ═══════════════════════════════════════════════════════════════════

    public function test_an_auditor_reads_and_exports_but_cannot_record_a_decision(): void
    {
        $this->assertTrue(HealthAccessService::can($this->auditor, 'audit.view'));
        $this->assertTrue(HealthAccessService::can($this->auditor, 'audit.export'));
        $this->assertFalse(
            HealthAccessService::can($this->auditor, 'audit.manage'),
            'An auditor who can close their own findings is not a control'
        );
    }

    public function test_the_owner_holds_all_three(): void
    {
        foreach (['audit.view', 'audit.export', 'audit.manage'] as $capability) {
            $this->assertTrue(
                HealthAccessService::can($this->owner, $capability),
                "Owner must hold {$capability}"
            );
        }
    }

    public function test_a_cashier_cannot_reach_the_audit_at_all(): void
    {
        $cashier = User::create([
            'name' => 'Counter Cashier',
            'email' => 'auditcashier@example.test',
            'password' => Hash::make('Passw0rd!2026'),
            'company_id' => $this->company->id,
            'role' => 'user',
            'health_role' => 'health_cashier',
            'is_active' => true,
        ]);

        $this->assertFalse(HealthAccessService::can($cashier, 'audit.view'));
        $this->assertFalse(HealthAccessService::can($cashier, 'audit.manage'));
    }

    public function test_the_audit_screen_refuses_a_role_without_the_capability(): void
    {
        $cashier = User::create([
            'name' => 'Counter Cashier Two',
            'email' => 'auditcashier2@example.test',
            'password' => Hash::make('Passw0rd!2026'),
            'company_id' => $this->company->id,
            'role' => 'user',
            'health_role' => 'health_cashier',
            'is_active' => true,
        ]);

        $this->actingAs($cashier, 'health')->get('/health/audit')->assertForbidden();
    }

    public function test_an_auditor_can_open_the_audit_screen(): void
    {
        $this->actingAs($this->auditor, 'health')->get('/health/audit')->assertOk();
    }

    public function test_an_auditor_is_refused_when_recording_a_decision(): void
    {
        $this->reversedCharge(false);
        $run = HealthAuditEngine::run($this->context(), $this->actor());
        $finding = HealthAuditFinding::where('health_audit_run_id', $run->id)->firstOrFail();

        $this->actingAs($this->auditor, 'health')
            ->post('/health/audit/finding/' . $finding->id . '/status', [
                'status' => 'false_positive',
                'note' => 'Nothing to answer for.',
            ])
            ->assertForbidden();

        $this->assertSame('open', $finding->fresh()->status);
    }

    public function test_closing_a_finding_needs_a_reason(): void
    {
        $this->reversedCharge(false);
        $run = HealthAuditEngine::run($this->context(), $this->actor());
        $finding = HealthAuditFinding::where('health_audit_run_id', $run->id)->firstOrFail();

        $this->actingAs($this->owner, 'health')
            ->post('/health/audit/finding/' . $finding->id . '/status', ['status' => 'resolved'])
            ->assertRedirect();

        $this->assertSame('open', $finding->fresh()->status, 'A finding must not close silently');

        $this->actingAs($this->owner, 'health')
            ->post('/health/audit/finding/' . $finding->id . '/status', [
                'status' => 'resolved',
                'note' => 'Duplicate entry, reversed correctly by the counter.',
            ]);

        $fresh = $finding->fresh();
        $this->assertSame('resolved', $fresh->status);
        $this->assertNotEmpty($fresh->status_note);
        $this->assertDatabaseHas('health_audit_notes', [
            'health_audit_finding_id' => $finding->id,
            'status_to' => 'resolved',
        ]);
    }

    public function test_recording_a_decision_is_itself_recorded(): void
    {
        $this->reversedCharge(false);
        $run = HealthAuditEngine::run($this->context(), $this->actor());
        $finding = HealthAuditFinding::where('health_audit_run_id', $run->id)->firstOrFail();

        $this->actingAs($this->owner, 'health')
            ->post('/health/audit/finding/' . $finding->id . '/status', [
                'status' => 'acknowledged',
                'note' => 'Seen, asking the counter.',
            ]);

        $this->assertTrue(
            HealthAuditEvent::where('company_id', $this->companyId())
                ->where('event', 'audit.finding.acknowledged')
                ->where('entity_id', $finding->id)
                ->exists(),
            'The auditor of the auditor has to have something to read'
        );
    }

    // ═══════════════════════════════════════════════════════════════════
    // 8. THE PACK IS SIGNED
    // ═══════════════════════════════════════════════════════════════════

    public function test_the_evidence_pack_builds_verifies_and_carries_its_scope(): void
    {
        if (!class_exists(\ZipArchive::class)) {
            $this->markTestSkipped('ZipArchive is not available in this PHP build');
        }

        Storage::fake('local');

        $this->reversedCharge(false);
        $run = HealthAuditEngine::run($this->context(), $this->actor());

        $this->assertTrue(HealthAuditPackService::claim($run));
        $run = HealthAuditPackService::build($run->fresh(), $this->company, $this->actor());

        $this->assertSame('ready', $run->pack_status, (string) $run->pack_error);
        $this->assertNotEmpty($run->pack_sha256);
        $this->assertNotEmpty($run->pack_signature);
        $this->assertTrue(Storage::disk('local')->exists($run->pack_path));
        $this->assertTrue(HealthAuditPackService::verify($run));
    }

    public function test_a_pack_that_no_longer_matches_its_signature_fails_verification(): void
    {
        if (!class_exists(\ZipArchive::class)) {
            $this->markTestSkipped('ZipArchive is not available in this PHP build');
        }

        Storage::fake('local');

        $this->reversedCharge(false);
        $run = HealthAuditEngine::run($this->context(), $this->actor());

        HealthAuditPackService::claim($run);
        $run = HealthAuditPackService::build($run->fresh(), $this->company, $this->actor());
        $this->assertTrue(HealthAuditPackService::verify($run));

        Storage::disk('local')->put($run->pack_path, 'not the pack that was signed');

        $this->assertFalse(HealthAuditPackService::verify($run));
    }

    public function test_a_pack_cannot_be_built_from_a_run_that_never_finished(): void
    {
        $run = HealthAuditRun::create([
            'company_id' => $this->companyId(),
            'user_id' => $this->owner->id,
            'actor_name' => $this->owner->name,
            'date_from' => now()->subDays(7)->toDateString(),
            'date_to' => now()->toDateString(),
            'ruleset_version' => HealthAuditRules::VERSION,
            'status' => 'failed',
            'filters_hash' => str_repeat('a', 64),
        ]);

        $this->actingAs($this->owner, 'health')
            ->post('/health/audit/' . $run->id . '/pack')
            ->assertRedirect();

        $this->assertNotSame('ready', (string) $run->fresh()->pack_status);
    }

    // ═══════════════════════════════════════════════════════════════════
    // COMPANY ISOLATION
    // ═══════════════════════════════════════════════════════════════════

    public function test_one_hospitals_audit_is_invisible_to_another(): void
    {
        $other = Company::create([
            'name' => 'Other Hospital',
            'ntn' => 'AUD-TEST-2',
            'product_type' => HealthPanel::PRODUCT_TYPE,
            'status' => 'approved',
            'company_status' => 'active',
            'health_org_type' => 'clinic',
            'health_modules' => json_encode(['opd', 'billing', 'accounts']),
        ]);

        $stranger = User::create([
            'name' => 'Other Owner',
            'email' => 'otherowner@example.test',
            'password' => Hash::make('Passw0rd!2026'),
            'company_id' => $other->id,
            'role' => 'company_admin',
            'health_role' => HealthAccessService::ROLE_OWNER,
            'is_active' => true,
        ]);

        $this->reversedCharge(false);
        $run = HealthAuditEngine::run($this->context(), $this->actor());
        $finding = HealthAuditFinding::where('health_audit_run_id', $run->id)->firstOrFail();

        // A record that belongs to someone else does not exist as far as this
        // hospital is concerned. The panel turns that into its own not-found
        // redirect rather than a bare 404, so the test asserts the outcome that
        // matters: no audit content is served, and the stranger lands back on
        // their own dashboard.
        foreach (['/health/audit/' . $run->id, '/health/audit/finding/' . $finding->id] as $url) {
            $response = $this->actingAs($stranger, 'health')->get($url);

            $response->assertRedirect('/health/dashboard');
            $response->assertDontSee($finding->rule_key);
            $response->assertDontSee((string) $run->result_hash);
        }
    }

    public function test_the_engine_never_looks_outside_the_company(): void
    {
        $other = Company::create([
            'name' => 'Other Hospital Two',
            'ntn' => 'AUD-TEST-3',
            'product_type' => HealthPanel::PRODUCT_TYPE,
            'status' => 'approved',
            'company_status' => 'active',
            'health_org_type' => 'clinic',
        ]);

        $patient = HealthPatient::create([
            'company_id' => $other->id,
            'mrn' => 'MRX0001',
            'name' => 'Somebody Elses Patient',
            'gender' => 'female',
            'age_years' => 30,
            'is_active' => true,
        ]);

        HealthCharge::create([
            'company_id' => $other->id,
            'health_patient_id' => $patient->id,
            'charge_no' => 'CX0001',
            'charge_date' => now()->subDays(3)->toDateString(),
            'category' => 'opd',
            'description' => 'Consultation',
            'unit_price' => 1000,
            'quantity' => 1,
            'gross_amount' => 1000,
            'net_amount' => 1000,
            'total_amount' => 1000,
            'status' => 'reversed',
            'reversed_at' => now()->subDays(2),
            'reversal_reason' => null,
        ]);

        $run = HealthAuditEngine::run($this->context(), $this->actor());

        $this->assertSame(0, (int) $run->findings_total);
    }

    // ═══════════════════════════════════════════════════════════════════
    // THE SCREENS
    // ═══════════════════════════════════════════════════════════════════

    public function test_the_owner_can_run_an_audit_from_the_screen_and_read_it(): void
    {
        $this->reversedCharge(false);

        $response = $this->actingAs($this->owner, 'health')
            ->post('/health/audit/run', ['preset' => 'last_30']);

        $run = HealthAuditRun::where('company_id', $this->companyId())->latest('id')->firstOrFail();
        $response->assertRedirect('/health/audit/' . $run->id);

        $this->actingAs($this->owner, 'health')->get('/health/audit/' . $run->id)->assertOk();
        $this->actingAs($this->owner, 'health')->get('/health/audit')->assertOk();
        $this->actingAs($this->owner, 'health')->get('/health/audit/trail')->assertOk();

        $finding = HealthAuditFinding::where('health_audit_run_id', $run->id)->firstOrFail();
        $this->actingAs($this->owner, 'health')->get('/health/audit/finding/' . $finding->id)->assertOk();
    }

    public function test_a_period_longer_than_a_year_is_refused_rather_than_quietly_trimmed(): void
    {
        $this->actingAs($this->owner, 'health')
            ->post('/health/audit/run', [
                'preset' => 'custom',
                'date_from' => now()->subYears(3)->toDateString(),
                'date_to' => now()->toDateString(),
            ])
            ->assertSessionHas('error');

        $this->assertSame(0, HealthAuditRun::where('company_id', $this->companyId())->count());
    }

    // ═══════════════════════════════════════════════════════════════════
    // 11. THE TAIL OF THE CHAIN, AND EVERY COLUMN OF IT
    // ═══════════════════════════════════════════════════════════════════

    private function fiveEvents(): void
    {
        for ($i = 0; $i < 5; $i++) {
            HealthAuditRecorder::record('billing.charge.created', [
                'company_id' => $this->companyId(),
                'actor' => $this->owner,
                'entity_type' => 'health_charges',
                'entity_id' => $i,
                'amount' => 100 + $i,
                'meta' => ['seq' => $i],
            ]);
        }
    }

    private function verifyWide(): array
    {
        return HealthAuditRecorder::verifyChain(
            $this->companyId(),
            now()->subDay()->toDateString(),
            now()->addDay()->toDateString()
        );
    }

    public function test_cutting_the_end_off_the_trail_is_detected(): void
    {
        $this->fiveEvents();
        $this->assertTrue($this->verifyWide()['intact']);
        $this->assertSame('intact', $this->verifyWide()['anchor']['status']);

        // Deleting the newest rows leaves every remaining row's prev_hash
        // satisfied — only the anchor knows the chain used to be longer.
        $newest = HealthAuditEvent::where('company_id', $this->companyId())->orderByDesc('id')->limit(2)->pluck('id');
        DB::table('health_audit_events')->whereIn('id', $newest->all())->delete();

        $result = $this->verifyWide();

        $this->assertSame(0, $result['missing'], 'Nothing in the middle is missing — that is the point');
        $this->assertSame('tail_removed', $result['anchor']['status']);
        $this->assertFalse($result['intact']);
    }

    public function test_rewriting_the_anchor_itself_is_detected(): void
    {
        $this->fiveEvents();

        // A database-only intruder who trims the chain and then points the
        // anchor at the new tip cannot sign it.
        $tip = HealthAuditEvent::where('company_id', $this->companyId())->orderByDesc('id')->first();
        DB::table('health_audit_events')->where('id', $tip->id)->delete();
        $newTip = HealthAuditEvent::where('company_id', $this->companyId())->orderByDesc('id')->first();

        DB::table('health_audit_chain_anchors')->where('company_id', $this->companyId())->update([
            'last_event_id' => $newTip->id,
            'tip_hash' => $newTip->sha256_hash,
            'event_count' => HealthAuditEvent::where('company_id', $this->companyId())->count(),
        ]);

        $this->assertSame('forged', $this->verifyWide()['anchor']['status']);
    }

    public function test_every_column_of_a_recorded_act_is_under_the_hash(): void
    {
        $this->fiveEvents();
        $target = HealthAuditEvent::where('company_id', $this->companyId())->orderByDesc('id')->first();

        // The columns the first cut left outside the hash: where it was filed,
        // who did it by role, where it came from, whether it was sensitive,
        // and the free-form meta.
        $edits = [
            ['branch_id' => 77],
            ['health_department_id' => 77],
            ['actor_role' => 'health_cashier'],
            ['ip_address' => '10.9.9.9'],
            ['route' => 'somewhere.else'],
            ['is_sensitive' => 1],
            ['meta' => json_encode(['seq' => 99])],
            ['entity_label' => 'C999'],
            // WHEN the system wrote it down is evidence too: back-dating the
            // recording of an act must not survive verification.
            ['created_at' => now()->subDays(40)->format('Y-m-d H:i:s')],
            ['occurred_at' => now()->subDays(40)->format('Y-m-d H:i:s')],
        ];

        foreach ($edits as $edit) {
            $keep = DB::table('health_audit_events')->where('id', $target->id)->first();
            DB::table('health_audit_events')->where('id', $target->id)->update($edit);

            $result = $this->verifyWide();
            $this->assertFalse($result['intact'], 'Undetected edit: ' . json_encode($edit));
            if (!isset($edit['occurred_at'])) {
                $this->assertContains((int) $target->id, $result['altered_ids'], 'Undetected edit: ' . json_encode($edit));
            }

            DB::table('health_audit_events')->where('id', $target->id)->update((array) $keep);
        }

        // Moving a MIDDLE row's occurred_at out of the verified window leaves
        // a hole where it stood: the next row's predecessor is no longer the
        // row before it.
        $middle = HealthAuditEvent::where('company_id', $this->companyId())->orderBy('id')->skip(2)->first();
        $keep = DB::table('health_audit_events')->where('id', $middle->id)->first();
        DB::table('health_audit_events')->where('id', $middle->id)
            ->update(['occurred_at' => now()->subDays(40)->format('Y-m-d H:i:s')]);
        $result = $this->verifyWide();
        $this->assertFalse($result['intact']);
        $this->assertNotEmpty($result['missing_ids'], 'A relocated row must leave a visible hole');
        DB::table('health_audit_events')->where('id', $middle->id)->update((array) $keep);

        $this->assertTrue($this->verifyWide()['intact'], 'Restored row must verify clean again');
    }

    public function test_moving_a_recorded_act_to_another_position_is_detected(): void
    {
        $this->fiveEvents();
        $middle = HealthAuditEvent::where('company_id', $this->companyId())->orderBy('id')->skip(2)->first();
        $newId = (int) $middle->id + 1000;

        // A row's id is its place in the order of the trail. Renumbering a
        // middle row so it reads as the newest keeps every hash link intact —
        // which is exactly why the id is under the hash.
        DB::table('health_audit_events')->where('id', $middle->id)->update(['id' => $newId]);

        $result = $this->verifyWide();
        $this->assertFalse($result['intact']);
        $this->assertContains($newId, $result['altered_ids']);

        DB::table('health_audit_events')->where('id', $newId)->update(['id' => $middle->id]);
        $this->assertTrue($this->verifyWide()['intact']);
    }

    public function test_a_cut_trail_becomes_a_critical_finding(): void
    {
        $this->fiveEvents();
        $newest = HealthAuditEvent::where('company_id', $this->companyId())->orderByDesc('id')->first();
        DB::table('health_audit_events')->where('id', $newest->id)->delete();

        $run = HealthAuditEngine::run($this->context(), $this->actor());

        $finding = HealthAuditFinding::where('health_audit_run_id', $run->id)
            ->where('rule_key', 'audit_trail_break')
            ->first();

        $this->assertNotNull($finding, 'A shortened trail must be raised');
        $this->assertSame('critical', $finding->severity);
        $this->assertSame('tail_removed', $finding->params['anchor'] ?? null);
    }

    // ═══════════════════════════════════════════════════════════════════
    // 12. A PATIENT'S IDENTITY NEVER REACHES THE TRAIL
    // ═══════════════════════════════════════════════════════════════════

    public function test_a_patient_edit_is_recorded_without_the_patients_identity(): void
    {
        $patient = $this->patient();

        $patient->update([
            'name' => 'Zulekha Bibi Renamed',
            'cnic' => '3520212345671',
            'phone' => '03001234567',
            'address' => '14-B Model Town Lahore',
            'guardian_name' => 'Ghulam Rasool',
            'is_active' => false,
        ]);

        $event = HealthAuditEvent::where('company_id', $this->companyId())
            ->where('entity_type', 'health_patients')
            ->where('entity_id', $patient->id)
            ->where('action', 'updated')
            ->orderByDesc('id')
            ->firstOrFail();

        $raw = json_encode([$event->old_values, $event->new_values, $event->entity_label], JSON_UNESCAPED_UNICODE);

        foreach (['Zulekha', 'Renamed', '3520212345671', '03001234567', 'Model Town', 'Ghulam Rasool', 'Patient 1'] as $secret) {
            $this->assertStringNotContainsString($secret, $raw, "Identity leaked into the trail: $secret");
        }

        // It still proves WHICH fields changed, and keeps the structural one.
        foreach (['name', 'cnic', 'phone', 'address', 'guardian_name'] as $field) {
            $this->assertArrayHasKey($field, $event->new_values);
            $this->assertSame('[redacted]', $event->new_values[$field]);
        }
        $this->assertArrayHasKey('is_active', $event->new_values);
        $this->assertNotSame('[redacted]', $event->new_values['is_active']);
        $this->assertSame($patient->mrn, $event->entity_label);
    }

    public function test_a_patient_identity_never_reaches_the_exported_pack(): void
    {
        if (!class_exists(\ZipArchive::class)) {
            $this->markTestSkipped('ZipArchive is not available in this PHP build');
        }

        Storage::fake('local');

        $patient = $this->patient();
        $patient->update(['name' => 'Zulekha Bibi Renamed', 'phone' => '03001234567', 'cnic' => '3520212345671']);
        $this->reversedCharge(false);

        $run = HealthAuditEngine::run($this->context(), $this->actor());
        $this->assertTrue(HealthAuditPackService::claim($run));
        $run = HealthAuditPackService::build($run->fresh(), $this->company, $this->actor());
        $this->assertSame('ready', $run->pack_status, (string) $run->pack_error);

        $zip = new \ZipArchive();
        $this->assertTrue($zip->open(Storage::disk('local')->path($run->pack_path)) === true);
        $everything = '';
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $everything .= $zip->getFromIndex($i);
        }
        $zip->close();

        $this->assertStringContainsString('audit-trail', $everything);
        foreach (['Zulekha', '03001234567', '3520212345671'] as $secret) {
            $this->assertStringNotContainsString($secret, $everything, "Identity leaked into the pack: $secret");
        }
    }

    // ═══════════════════════════════════════════════════════════════════
    // 13. THE WARD FENCE APPLIES TO THE AUDIT TOO
    // ═══════════════════════════════════════════════════════════════════

    private function department(string $name): HealthDepartment
    {
        return HealthDepartment::create([
            'company_id' => $this->companyId(),
            'name' => $name,
            'code' => strtoupper(substr($name, 0, 3)),
            'type' => 'clinical',
            'is_active' => true,
        ]);
    }

    private function wardAuditor(HealthDepartment $department, string $email): User
    {
        return User::create([
            'name' => 'Ward Auditor ' . $department->name,
            'email' => $email,
            'password' => Hash::make('Passw0rd!2026'),
            'company_id' => $this->company->id,
            'role' => 'user',
            'health_role' => 'health_auditor',
            'health_department_id' => $department->id,
            'is_active' => true,
        ]);
    }

    public function test_a_ward_confined_reader_cannot_open_an_organisation_wide_run(): void
    {
        $radiology = $this->department('Radiology');
        $reader = $this->wardAuditor($radiology, 'radiology.auditor@example.test');

        $this->reversedCharge(false);
        $run = HealthAuditEngine::run($this->context(), $this->actor());
        $finding = HealthAuditFinding::where('health_audit_run_id', $run->id)->firstOrFail();

        // The owner's run was computed across every ward; a reader posted to
        // one ward does not get to read the others through it.
        $this->assertNull($run->scope_department_ids);
        $this->actingAs($reader, 'health')->get('/health/audit/' . $run->id)->assertStatus(403);
        $this->actingAs($reader, 'health')->get('/health/audit/finding/' . $finding->id)->assertStatus(403);
        $this->actingAs($reader, 'health')->get('/health/audit/' . $run->id . '/pack/download')->assertStatus(403);

        // Nor is the run listed to them at all.
        $index = $this->actingAs($reader, 'health')->get('/health/audit');
        $index->assertOk();
        $index->assertDontSee((string) $run->result_hash);

        // The owner still reads it.
        $this->actingAs($this->owner, 'health')->get('/health/audit/' . $run->id)->assertOk();
    }

    public function test_a_run_remembers_the_boundary_it_was_computed_inside(): void
    {
        $radiology = $this->department('Radiology');
        $cardiology = $this->department('Cardiology');
        $radiologyReader = $this->wardAuditor($radiology, 'radiology.auditor@example.test');
        $cardiologyReader = $this->wardAuditor($cardiology, 'cardiology.auditor@example.test');

        $this->reversedCharge(false);

        // Radiology's auditor presses the button: no filter chosen, but the run
        // is confined to their posting — and says so on the row.
        $response = $this->actingAs($radiologyReader, 'health')->post('/health/audit/run', [
            'preset' => 'last_30',
        ]);
        $response->assertRedirect();

        $run = HealthAuditRun::where('company_id', $this->companyId())->orderByDesc('id')->firstOrFail();
        $this->assertSame([$radiology->id], $run->scope_department_ids);
        $this->assertSame('ready', $run->status);

        // Their own run opens; the other ward's auditor is refused; the owner may read anything.
        $this->actingAs($radiologyReader, 'health')->get('/health/audit/' . $run->id)->assertOk();
        $this->actingAs($cardiologyReader, 'health')->get('/health/audit/' . $run->id)->assertStatus(403);
        $this->actingAs($this->owner, 'health')->get('/health/audit/' . $run->id)->assertOk();

        // The same period from the two wards is two different questions.
        $this->actingAs($cardiologyReader, 'health')->post('/health/audit/run', ['preset' => 'last_30'])->assertRedirect();
        $other = HealthAuditRun::where('company_id', $this->companyId())->orderByDesc('id')->firstOrFail();
        $this->assertNotSame($run->filters_hash, $other->filters_hash);
    }

    public function test_a_finding_filed_under_another_ward_is_refused_even_inside_a_readable_run(): void
    {
        $radiology = $this->department('Radiology');
        $cardiology = $this->department('Cardiology');
        $reader = $this->wardAuditor($radiology, 'radiology.auditor@example.test');

        $this->reversedCharge(false);

        $ctx = new HealthAuditContext(
            companyId: $this->companyId(),
            from: now()->subDays(30)->toDateString(),
            to: now()->toDateString(),
            preset: 'last_30',
            branchBoundary: [],
            departmentBoundary: [$radiology->id],
        );
        $run = HealthAuditEngine::run($ctx, $this->actor($reader));
        $finding = HealthAuditFinding::where('health_audit_run_id', $run->id)->firstOrFail();

        $this->actingAs($reader, 'health')->get('/health/audit/finding/' . $finding->id)->assertOk();

        // Somebody re-files the finding under Cardiology behind the engine's back.
        DB::table('health_audit_findings')->where('id', $finding->id)->update(['health_department_id' => $cardiology->id]);

        $this->actingAs($reader, 'health')->get('/health/audit/finding/' . $finding->id)->assertStatus(403);
        $this->actingAs($reader, 'health')->get('/health/audit/' . $run->id)->assertOk()->assertDontSee($finding->rule_key);
    }

    public function test_the_trail_screen_keeps_other_wards_activity_out(): void
    {
        $radiology = $this->department('Radiology');
        $cardiology = $this->department('Cardiology');
        $reader = $this->wardAuditor($radiology, 'radiology.auditor@example.test');

        HealthAuditRecorder::record('billing.charge.created', [
            'company_id' => $this->companyId(),
            'actor' => $this->owner,
            'health_department_id' => $radiology->id,
            'entity_type' => 'health_charges',
            'entity_id' => 1,
            'entity_label' => 'RADIO-ONLY-LABEL',
        ]);
        HealthAuditRecorder::record('billing.charge.created', [
            'company_id' => $this->companyId(),
            'actor' => $this->owner,
            'health_department_id' => $cardiology->id,
            'entity_type' => 'health_charges',
            'entity_id' => 2,
            'entity_label' => 'CARDIO-ONLY-LABEL',
        ]);

        $page = $this->actingAs($reader, 'health')->get('/health/audit/trail?category=billing');
        $page->assertOk();
        $page->assertSee('RADIO-ONLY-LABEL');
        $page->assertDontSee('CARDIO-ONLY-LABEL');

        $this->actingAs($this->owner, 'health')->get('/health/audit/trail?category=billing')
            ->assertOk()->assertSee('CARDIO-ONLY-LABEL');
    }

    // ═══════════════════════════════════════════════════════════════════
    // 14. DUPLICATE PATIENTS: MRNs ONLY, AND ONLY INSIDE THE READER'S FENCE
    // ═══════════════════════════════════════════════════════════════════

    private function patientWithCnic(string $cnic, ?int $branchId = null, ?string $name = null): HealthPatient
    {
        $this->seq++;

        return HealthPatient::create([
            'company_id' => $this->companyId(),
            'branch_id' => $branchId,
            'mrn' => 'DUP' . str_pad((string) $this->seq, 5, '0', STR_PAD_LEFT),
            'name' => $name ?: ('Duplicate Person ' . $this->seq),
            'cnic' => $cnic,
            'gender' => 'female',
            'age_years' => 33,
            'is_active' => true,
        ]);
    }

    private function branch(string $name, string $code, bool $headOffice = false): \App\Models\Branch
    {
        return \App\Models\Branch::create([
            'company_id' => $this->companyId(),
            'name' => $name,
            'code' => $code,
            'is_head_office' => $headOffice,
            'is_active' => true,
        ]);
    }

    private function contextWithin(?array $branchIds, ?array $departmentIds): HealthAuditContext
    {
        return new HealthAuditContext(
            companyId: $this->companyId(),
            from: now()->subDays(30)->toDateString(),
            to: now()->toDateString(),
            preset: 'last_30',
            branchBoundary: $branchIds,
            departmentBoundary: $departmentIds,
        );
    }

    public function test_a_duplicate_patient_finding_names_nobody(): void
    {
        $a = $this->patientWithCnic('3520212345671', null, 'Zubaida Khatoon Secret');
        $b = $this->patientWithCnic('3520212345671', null, 'Zubaida K Secret');

        $run = HealthAuditEngine::run($this->context(), $this->actor());
        $finding = HealthAuditFinding::where('health_audit_run_id', $run->id)
            ->where('rule_key', 'duplicate_patient')->firstOrFail();

        $stored = json_encode($finding->getAttributes());
        $this->assertStringNotContainsString('Secret', $stored, 'A patient name must never sit in a finding');
        $this->assertStringNotContainsString('3520212345671', $stored, 'The identifier that matched must not be copied either');
        $this->assertStringContainsString($a->mrn, $stored);
        $this->assertStringContainsString($b->mrn, $stored);

        // The auditor — a role without clinical access — reads the finding and
        // the pack and still learns no name.
        $page = $this->actingAs($this->auditor, 'health')->get('/health/audit/finding/' . $finding->id);
        $page->assertOk();
        $page->assertDontSee('Secret');
        $page->assertDontSee('3520212345671');
        $page->assertSee($a->mrn);

        $show = $this->actingAs($this->auditor, 'health')->get('/health/audit/' . $run->id);
        $show->assertOk()->assertDontSee('Secret');

        $run = HealthAuditPackService::build($run->fresh(), $this->company, $this->actor($this->auditor));
        $this->assertSame('ready', $run->pack_status, (string) $run->pack_error);
        $zip = new \ZipArchive();
        $this->assertTrue($zip->open(Storage::disk('local')->path($run->pack_path)) === true);
        $all = '';
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $all .= (string) $zip->getFromIndex($i);
        }
        $zip->close();
        $this->assertStringNotContainsString('Secret', $all, 'The pack must not carry a patient name');
        $this->assertStringNotContainsString('3520212345671', $all);
        $this->assertStringContainsString($a->mrn, $all);
    }

    public function test_a_duplicate_split_across_branches_is_not_disclosed_to_a_confined_reader(): void
    {
        $main = $this->branch('Main Campus', 'MAIN', true);
        $city = $this->branch('City Branch', 'CITY');

        $inMain = $this->patientWithCnic('3520299999991', $main->id);
        $inCity = $this->patientWithCnic('3520299999991', $city->id);

        // Confined to Main: the copy in City is not theirs to know about, and
        // one visible file is not a duplicate.
        $confined = HealthAuditEngine::run($this->contextWithin([$main->id], null), $this->actor());
        $this->assertSame(0, HealthAuditFinding::where('health_audit_run_id', $confined->id)
            ->where('rule_key', 'duplicate_patient')->count());
        $this->assertStringNotContainsString($inCity->mrn, json_encode(
            HealthAuditFinding::where('health_audit_run_id', $confined->id)->get()->toArray()
        ));

        // Two copies INSIDE the fence are still raised for that reader.
        $inMainAgain = $this->patientWithCnic('3520299999991', $main->id);
        $again = HealthAuditEngine::run($this->contextWithin([$main->id], null), $this->actor());
        $finding = HealthAuditFinding::where('health_audit_run_id', $again->id)
            ->where('rule_key', 'duplicate_patient')->firstOrFail();
        $this->assertSame(2, (int) $finding->params['count']);
        $stored = json_encode($finding->evidence);
        $this->assertStringContainsString($inMain->mrn, $stored);
        $this->assertStringContainsString($inMainAgain->mrn, $stored);
        $this->assertStringNotContainsString($inCity->mrn, $stored, 'The other branch\'s file stays out of the evidence');

        // The owner's organisation-wide run sees all three.
        $wide = HealthAuditEngine::run($this->context(), $this->actor());
        $all = HealthAuditFinding::where('health_audit_run_id', $wide->id)
            ->where('rule_key', 'duplicate_patient')->firstOrFail();
        $this->assertSame(3, (int) $all->params['count']);
    }

    // ═══════════════════════════════════════════════════════════════════
    // 15. THE DEPARTMENT FENCE HOLDS ON RULES WHOSE TABLE HAS NO WARD COLUMN
    // ═══════════════════════════════════════════════════════════════════

    public function test_trail_derived_findings_stay_inside_the_readers_department(): void
    {
        $radiology = $this->department('Radiology');
        $cardiology = $this->department('Cardiology');

        foreach ([$radiology, $cardiology] as $dept) {
            HealthAuditRecorder::record('patient.confidential.viewed', [
                'company_id' => $this->companyId(),
                'actor' => $this->owner,
                'health_department_id' => $dept->id,
                'entity_type' => 'health_patients',
                'entity_id' => $dept->id,
                'entity_label' => 'MR-' . strtoupper($dept->code),
                'sensitive' => true,
            ]);
        }

        $radiologyOnly = HealthAuditEngine::run($this->contextWithin(null, [$radiology->id]), $this->actor());
        $findings = HealthAuditFinding::where('health_audit_run_id', $radiologyOnly->id)
            ->where('rule_key', 'sensitive_record_viewed')->get();
        $this->assertCount(1, $findings, 'Only the radiology view is this reader\'s business');
        $this->assertStringNotContainsString('MR-CAR', json_encode($findings->toArray()));

        $wide = HealthAuditEngine::run($this->context(), $this->actor());
        $this->assertSame(2, HealthAuditFinding::where('health_audit_run_id', $wide->id)
            ->where('rule_key', 'sensitive_record_viewed')->count());
    }

    public function test_a_receipt_is_fenced_by_the_ward_of_its_bill(): void
    {
        $radiology = $this->department('Radiology');
        $cardiology = $this->department('Cardiology');

        foreach ([$radiology, $cardiology] as $i => $dept) {
            $patient = $this->patient();
            $bill = HealthBill::create([
                'company_id' => $this->companyId(),
                'health_patient_id' => $patient->id,
                'health_department_id' => $dept->id,
                'bill_no' => 'BW0' . $i,
                'doc_type' => HealthBill::TYPE_INVOICE,
                'status' => HealthBill::STATUS_FINALIZED,
                'bill_date' => now()->subDays(2)->toDateString(),
                'business_date' => now()->subDays(2)->toDateString(),
                'gross_amount' => 1000,
                'net_amount' => 1000,
                'total_amount' => 1000,
                'patient_payable' => 1000,
                'paid_amount' => 0,
                'outstanding_amount' => 1000,
            ]);
            HealthPayment::create([
                'company_id' => $this->companyId(),
                'health_bill_id' => $bill->id,
                'health_patient_id' => $patient->id,
                'receipt_no' => 'RW-' . strtoupper($dept->code),
                'kind' => 'payment',
                'amount' => 1000,
                'method' => 'cash',
                'received_at' => now()->subDays(2),
                'received_by' => $this->owner->id,
                'business_date' => now()->subDays(2)->toDateString(),
                'reversed_at' => now()->subDay(),
                'reversed_by' => $this->owner->id,
                'reversal_reason' => null,
            ]);
        }

        $radiologyOnly = HealthAuditEngine::run($this->contextWithin(null, [$radiology->id]), $this->actor());
        $findings = HealthAuditFinding::where('health_audit_run_id', $radiologyOnly->id)
            ->where('rule_key', 'payment_reversed_no_reason')->get();
        $this->assertCount(1, $findings);
        $this->assertSame('RW-RAD', $findings->first()->entity_label);

        $wide = HealthAuditEngine::run($this->context(), $this->actor());
        $this->assertSame(2, HealthAuditFinding::where('health_audit_run_id', $wide->id)
            ->where('rule_key', 'payment_reversed_no_reason')->count());
    }

    public function test_a_cash_shift_is_fenced_by_the_cashiers_posting(): void
    {
        $radiology = $this->department('Radiology');
        $cardiology = $this->department('Cardiology');
        $radCashier = $this->wardAuditor($radiology, 'rad.cashier@example.test');
        $carCashier = $this->wardAuditor($cardiology, 'car.cashier@example.test');

        foreach ([$radCashier, $carCashier] as $cashier) {
            \App\Models\HealthCashierShift::create([
                'company_id' => $this->companyId(),
                'user_id' => $cashier->id,
                'opened_by' => $cashier->id,
                'opened_at' => now()->subDays(2)->setTime(9, 0),
                'closed_at' => now()->subDays(2)->setTime(18, 0),
                'closed_by' => $cashier->id,
                'opening_float' => 0,
                'expected_cash' => 10000,
                'counted_cash' => 9000,
                'variance' => -1000,
                'status' => 'closed',
                'business_date' => now()->subDays(2)->toDateString(),
            ]);
        }

        $radiologyOnly = HealthAuditEngine::run($this->contextWithin(null, [$radiology->id]), $this->actor());
        $findings = HealthAuditFinding::where('health_audit_run_id', $radiologyOnly->id)
            ->where('rule_key', 'shift_cash_variance')->get();
        $this->assertCount(1, $findings);
        $this->assertSame((int) $radCashier->id, (int) $findings->first()->subject_user_id);

        $wide = HealthAuditEngine::run($this->context(), $this->actor());
        $this->assertSame(2, HealthAuditFinding::where('health_audit_run_id', $wide->id)
            ->where('rule_key', 'shift_cash_variance')->count());
    }

    public function test_a_ward_auditor_running_from_the_screen_gets_only_their_wards_findings(): void
    {
        $radiology = $this->department('Radiology');
        $cardiology = $this->department('Cardiology');
        $reader = $this->wardAuditor($radiology, 'radiology.auditor@example.test');

        foreach ([$radiology, $cardiology] as $dept) {
            HealthAuditRecorder::record('patient.confidential.viewed', [
                'company_id' => $this->companyId(),
                'actor' => $this->owner,
                'health_department_id' => $dept->id,
                'entity_type' => 'health_patients',
                'entity_id' => $dept->id,
                'entity_label' => 'MR-' . strtoupper($dept->code),
                'sensitive' => true,
            ]);
        }

        $this->actingAs($reader, 'health')->post('/health/audit/run', ['preset' => 'last_30'])->assertRedirect();
        $run = HealthAuditRun::where('company_id', $this->companyId())->latest('id')->firstOrFail();
        $this->assertSame([$radiology->id], $run->scope_department_ids);

        $findings = HealthAuditFinding::where('health_audit_run_id', $run->id)
            ->where('rule_key', 'sensitive_record_viewed')->get();
        $this->assertCount(1, $findings);

        $page = $this->actingAs($reader, 'health')->get('/health/audit/' . $run->id);
        $page->assertOk()->assertDontSee('MR-CAR');
    }

    // ═══════════════════════════════════════════════════════════════════
    // 16. INDIRECT RECORDS ARE FILED UNDER THEIR PARENT'S WARD
    // ═══════════════════════════════════════════════════════════════════

    private function billInWard(?HealthDepartment $dept, string $billNo): HealthBill
    {
        return HealthBill::create([
            'company_id' => $this->companyId(),
            'health_patient_id' => $this->patient()->id,
            'health_department_id' => $dept?->id,
            'bill_no' => $billNo,
            'doc_type' => HealthBill::TYPE_INVOICE,
            'status' => HealthBill::STATUS_FINALIZED,
            'bill_date' => now()->toDateString(),
            'business_date' => now()->toDateString(),
            'gross_amount' => 500,
            'net_amount' => 500,
            'total_amount' => 500,
            'patient_payable' => 500,
            'paid_amount' => 0,
            'outstanding_amount' => 500,
        ]);
    }

    public function test_a_receipt_event_is_filed_under_the_ward_of_its_bill(): void
    {
        $radiology = $this->department('Radiology');
        $cardiology = $this->department('Cardiology');
        $reader = $this->wardAuditor($radiology, 'radiology.auditor@example.test');

        foreach ([$radiology, $cardiology] as $dept) {
            $bill = $this->billInWard($dept, 'BX-' . $dept->code);
            HealthPayment::create([
                'company_id' => $this->companyId(),
                'health_bill_id' => $bill->id,
                'health_patient_id' => $bill->health_patient_id,
                'receipt_no' => 'RX-' . strtoupper($dept->code),
                'kind' => 'payment',
                'amount' => 500,
                'method' => 'cash',
                'received_at' => now(),
                'received_by' => $this->owner->id,
                'business_date' => now()->toDateString(),
            ]);
        }

        // The receipts table has no ward column; the event still carries the
        // bill's ward.
        $event = HealthAuditEvent::where('company_id', $this->companyId())
            ->where('event', 'payment.receipt.created')->where('entity_label', 'RX-CAR')->firstOrFail();
        $this->assertSame($cardiology->id, (int) $event->health_department_id);

        $page = $this->actingAs($reader, 'health')->get('/health/audit/trail?category=payment');
        $page->assertOk();
        $page->assertSee('RX-RAD');
        $page->assertDontSee('RX-CAR');
    }

    public function test_an_explicit_act_is_filed_under_the_actors_posting(): void
    {
        $radiology = $this->department('Radiology');
        $cardiology = $this->department('Cardiology');
        $reader = $this->wardAuditor($radiology, 'radiology.auditor@example.test');
        $cardioClerk = $this->wardAuditor($cardiology, 'cardio.clerk@example.test');

        HealthAuditRecorder::record('export.payroll', [
            'company_id' => $this->companyId(),
            'actor' => $cardioClerk,
            'entity_type' => 'exports',
            'entity_label' => 'CARDIO-EXPORT-LABEL',
        ]);

        $event = HealthAuditEvent::where('company_id', $this->companyId())
            ->where('entity_label', 'CARDIO-EXPORT-LABEL')->firstOrFail();
        $this->assertSame($cardiology->id, (int) $event->health_department_id);

        $this->actingAs($reader, 'health')->get('/health/audit/trail?category=export')
            ->assertOk()->assertDontSee('CARDIO-EXPORT-LABEL');
    }

    // ═══════════════════════════════════════════════════════════════════
    // 17. THE PICKERS ARE FENCED LIKE THE FINDINGS
    // ═══════════════════════════════════════════════════════════════════

    public function test_the_pickers_show_a_ward_auditor_only_their_own_ward(): void
    {
        $radiology = $this->department('Radiology');
        $cardiology = $this->department('Cardiology');
        $reader = $this->wardAuditor($radiology, 'radiology.auditor@example.test');
        $cardioClerk = $this->wardAuditor($cardiology, 'cardio.clerk@example.test');
        $cardioClerk->forceFill(['name' => 'Clerk Cardio Zulfiqar'])->save();

        $cardioDoctor = HealthDoctor::create([
            'company_id' => $this->companyId(),
            'health_department_id' => $cardiology->id,
            'name' => 'Dr Cardio Only',
            'consultation_fee' => 1000,
            'is_active' => true,
        ]);
        $radioDoctor = HealthDoctor::create([
            'company_id' => $this->companyId(),
            'health_department_id' => $radiology->id,
            'name' => 'Dr Radio Only',
            'consultation_fee' => 1000,
            'is_active' => true,
        ]);

        $page = $this->actingAs($reader, 'health')->get('/health/audit');
        $page->assertOk();
        $page->assertSee('Dr Radio Only');
        $page->assertDontSee('Dr Cardio Only');
        $page->assertDontSee('Clerk Cardio Zulfiqar');

        // Posting the other ward's ids directly is refused, not quietly run.
        $this->actingAs($reader, 'health')
            ->post('/health/audit/run', ['preset' => 'last_30', 'health_doctor_id' => $cardioDoctor->id])
            ->assertForbidden();
        $this->actingAs($reader, 'health')
            ->post('/health/audit/run', ['preset' => 'last_30', 'subject_user_id' => $cardioClerk->id])
            ->assertForbidden();
        $this->actingAs($reader, 'health')
            ->post('/health/audit/run', ['preset' => 'last_30', 'health_doctor_id' => $radioDoctor->id])
            ->assertRedirect();

        // The owner still sees the whole roster.
        $this->actingAs($this->owner, 'health')->get('/health/audit')
            ->assertOk()->assertSee('Dr Cardio Only')->assertSee('Clerk Cardio Zulfiqar');
    }

    // ═══════════════════════════════════════════════════════════════════
    // 18. FREE-TEXT REASONS: IDENTIFIERS SCRUBBED, WORDS ONLY FOR CLINICAL READERS
    // ═══════════════════════════════════════════════════════════════════

    public function test_a_reason_never_stores_an_identifier_and_only_clinical_readers_see_its_words(): void
    {
        // No wards in this hospital: the books auditor is unconfined, so what
        // keeps the words from them is the capability alone.
        $bill = $this->billInWard(null, 'BR-01');
        HealthPayment::create([
            'company_id' => $this->companyId(),
            'health_bill_id' => $bill->id,
            'health_patient_id' => $bill->health_patient_id,
            'receipt_no' => 'RR-01',
            'kind' => 'payment',
            'amount' => 500,
            'method' => 'cash',
            'received_at' => now(),
            'received_by' => $this->owner->id,
            'business_date' => now()->toDateString(),
            'reversed_at' => now(),
            'reversed_by' => $this->owner->id,
            'reversal_reason' => 'Refund to Zubaida Parveen, phone 0300-1234567, CNIC 35202-1234567-1, mail zp@example.com, hepatitis positive',
        ]);

        $event = HealthAuditEvent::where('company_id', $this->companyId())
            ->where('entity_type', 'health_payments')->where('entity_label', 'RR-01')->firstOrFail();

        // The identifiers never reached the table.
        $this->assertStringNotContainsString('1234567', (string) $event->reason);
        $this->assertStringNotContainsString('35202', (string) $event->reason);
        $this->assertStringNotContainsString('zp@example.com', (string) $event->reason);
        $this->assertStringContainsString('[cnic]', (string) $event->reason);
        $this->assertStringContainsString('[number]', (string) $event->reason);
        $this->assertStringContainsString('[email]', (string) $event->reason);

        // An auditor of the books learns a reason exists, not what it says.
        $trail = $this->actingAs($this->auditor, 'health')->get('/health/audit/trail?category=payment');
        $trail->assertOk()->assertSee('RR-01');
        $trail->assertDontSee('Zubaida');
        $trail->assertDontSee('hepatitis');

        // The owner may open the record anyway, so the owner reads the words.
        $this->actingAs($this->owner, 'health')->get('/health/audit/trail?category=payment')
            ->assertOk()->assertSee('Zubaida');

        // The rule that raises the reversal copies the row into its evidence;
        // the stored finding must not become a second, unguarded copy.
        $run = HealthAuditEngine::run($this->context(), $this->actor());
        $finding = HealthAuditFinding::where('health_audit_run_id', $run->id)
            ->where('rule_key', 'payment_reversed')->where('entity_label', 'RR-01')->firstOrFail();
        $stored = json_encode([$finding->params, $finding->evidence]);
        foreach (['Zubaida', 'hepatitis', '1234567', '35202', 'zp@example.com'] as $needle) {
            $this->assertStringNotContainsString($needle, $stored, "finding row still carries {$needle}");
        }
        $this->assertStringContainsString('[free text, 108 characters]', $stored);

        $page = $this->actingAs($this->auditor, 'health')->get('/health/audit/finding/' . $finding->id);
        $page->assertOk()->assertSee('RR-01');
        $page->assertDontSee('Zubaida');
        $page->assertDontSee('hepatitis');

        // The pack travels; it carries the withheld form whoever built it.
        $run = HealthAuditPackService::build($run, $this->company, $this->actor());
        $this->assertSame('ready', $run->pack_status, (string) $run->pack_error);
        $zip = new \ZipArchive();
        $zip->open(Storage::disk('local')->path($run->pack_path));
        $trailCsv = (string) $zip->getFromName('audit-trail.csv');
        $findingsCsv = (string) $zip->getFromName('findings.csv');
        $zip->close();
        $this->assertStringContainsString('RR-01', $trailCsv);
        $this->assertStringContainsString('RR-01', $findingsCsv);
        foreach ([$trailCsv, $findingsCsv] as $csv) {
            $this->assertStringNotContainsString('Zubaida', $csv);
            $this->assertStringNotContainsString('hepatitis', $csv);
            $this->assertStringNotContainsString('zp@example.com', $csv);
        }
    }

    public function test_every_rule_persists_its_evidence_through_the_redaction_policy(): void
    {
        // A structural guard: whatever a rule puts in params/evidence, the
        // engine stores it through the ONE policy — free text as a length,
        // identifiers dropped. Feed the engine a synthetic row and read back
        // what it kept.
        $method = new \ReflectionMethod(HealthAuditEngine::class, 'normalise');
        $method->setAccessible(true);
        $ctx = $this->context();
        $rules = ['payment_reversed_no_reason' => HealthAuditRules::all()['payment_reversed_no_reason']];
        $rows = ['payment_reversed_no_reason' => [[
            'entity_type' => 'health_payments',
            'entity_id' => 9,
            'entity_label' => 'RX-9',
            'params' => ['cancel_reason' => 'Bibi Zainab 0300-1234567 asked', 'amount' => '500.00'],
            'evidence' => [
                'receipt' => [
                    'receipt_no' => 'RX-2026000123',
                    'reversal_reason' => 'hepatitis positive, refund',
                    'note' => 'CNIC 35202-1234567-1',
                    'phone' => '03001234567',
                    'method' => 'cash',
                ],
                'members' => [['id' => 1, 'description' => 'Ali Khan private', 'mrn' => 'MR-0001']],
            ],
        ]]];

        $out = $method->invoke(null, $ctx, $rules, $rows)[0];

        $this->assertSame('[free text, 30 characters]', $out['params']['cancel_reason']);
        $this->assertSame('500.00', $out['params']['amount']);
        $this->assertSame('RX-2026000123', $out['evidence']['receipt']['receipt_no'], 'document numbers keep their digits');
        $this->assertSame('[free text, 26 characters]', $out['evidence']['receipt']['reversal_reason']);
        $this->assertSame('[free text, 20 characters]', $out['evidence']['receipt']['note']);
        $this->assertSame('[redacted]', $out['evidence']['receipt']['phone']);
        $this->assertSame('cash', $out['evidence']['receipt']['method']);
        $this->assertSame('[free text, 16 characters]', $out['evidence']['members'][0]['description']);
        $this->assertSame('MR-0001', $out['evidence']['members'][0]['mrn']);
    }

    public function test_a_decision_note_keeps_no_identifier_and_shows_its_words_only_to_clinical_readers(): void
    {
        $this->reversedCharge(false);
        $run = HealthAuditEngine::run($this->context(), $this->actor());
        $finding = HealthAuditFinding::where('health_audit_run_id', $run->id)->firstOrFail();

        $this->actingAs($this->owner, 'health')->post('/health/audit/finding/' . $finding->id . '/status', [
            'status' => 'resolved',
            'note' => 'Spoke to Zubaida Parveen (0300-1234567, 35202-1234567-1) — hepatitis result refunded, mail zp@example.com',
        ])->assertRedirect();
        $this->actingAs($this->owner, 'health')->post('/health/audit/finding/' . $finding->id . '/note', [
            'body' => 'Follow-up: Zubaida confirmed on 0321-7654321.',
        ])->assertRedirect();

        $finding->refresh();
        $notes = HealthAuditNote::where('health_audit_finding_id', $finding->id)->get();
        $this->assertCount(2, $notes);
        foreach (array_merge([$finding->status_note], $notes->pluck('body')->all()) as $stored) {
            $this->assertStringNotContainsString('1234567', (string) $stored);
            $this->assertStringNotContainsString('7654321', (string) $stored);
            $this->assertStringNotContainsString('35202', (string) $stored);
            $this->assertStringNotContainsString('zp@example.com', (string) $stored);
        }
        $this->assertStringContainsString('hepatitis', (string) $finding->status_note, 'the words themselves are kept for clinical readers');

        // The books auditor learns a note exists; not who it names.
        $page = $this->actingAs($this->auditor, 'health')->get('/health/audit/finding/' . $finding->id);
        $page->assertOk();
        $page->assertDontSee('Zubaida');
        $page->assertDontSee('hepatitis');
        $page->assertSee(e(__('health.audit_reason_withheld', ['n' => mb_strlen((string) $finding->status_note)])), false);

        // The owner may open the clinical record anyway, so the owner reads them.
        $this->actingAs($this->owner, 'health')->get('/health/audit/finding/' . $finding->id)
            ->assertOk()->assertSee('Zubaida')->assertSee('Follow-up');

        // Neither pack CSV carries the words.
        $run = HealthAuditPackService::build($run->fresh(), $this->company, $this->actor());
        $this->assertSame('ready', $run->pack_status, (string) $run->pack_error);
        $zip = new \ZipArchive();
        $zip->open(Storage::disk('local')->path($run->pack_path));
        $findingsCsv = (string) $zip->getFromName('findings.csv');
        $trailCsv = (string) $zip->getFromName('audit-trail.csv');
        $zip->close();
        $this->assertStringContainsString('resolved', $findingsCsv);
        foreach ([$findingsCsv, $trailCsv] as $csv) {
            $this->assertStringNotContainsString('Zubaida', $csv);
            $this->assertStringNotContainsString('hepatitis', $csv);
        }
    }

    public function test_the_trail_search_is_not_an_oracle_for_the_words_of_a_reason(): void
    {
        HealthAuditRecorder::record('billing.charge.reversed', [
            'company_id' => $this->companyId(),
            'actor' => $this->owner,
            'entity_type' => 'health_charges',
            'entity_id' => 1,
            'entity_label' => 'CHG-ORACLE-1',
            'reason' => 'Refund for Zubaida Parveen after hepatitis result',
        ]);

        // Searching a word from the reason returns nothing, for the auditor
        // AND for the owner: the reason is simply not a searchable column.
        foreach ([$this->auditor, $this->owner] as $reader) {
            $this->actingAs($reader, 'health')->get('/health/audit/trail?q=Zubaida')
                ->assertOk()->assertDontSee('CHG-ORACLE-1');
            $this->actingAs($reader, 'health')->get('/health/audit/trail?q=hepatitis')
                ->assertOk()->assertDontSee('CHG-ORACLE-1');
            $this->actingAs($reader, 'health')->get('/health/audit/trail?q=CHG-ORACLE')
                ->assertOk()->assertSee('CHG-ORACLE-1');
        }
    }

    public function test_verification_walks_the_whole_window_not_the_first_page(): void
    {
        // More rows than one read chunk, then damage the LAST one: a walk
        // that stopped after its first page would certify the period intact.
        for ($i = 0; $i < 7; $i++) {
            HealthAuditRecorder::record('auth.login', [
                'company_id' => $this->companyId(),
                'actor' => $this->owner,
                'entity_type' => 'users',
                'entity_id' => $this->owner->id,
            ]);
        }
        $last = HealthAuditEvent::where('company_id', $this->companyId())->max('id');
        $total = HealthAuditEvent::where('company_id', $this->companyId())->count();
        DB::table('health_audit_events')->where('id', $last)->update(['ip_address' => '10.9.9.9']);

        $result = HealthAuditRecorder::verifyChain(
            $this->companyId(),
            now()->subDay()->toDateString(),
            now()->addDay()->toDateString(),
            3
        );
        $this->assertSame($total, $result['checked']);
        $this->assertFalse($result['intact']);
        $this->assertContains((int) $last, $result['altered_ids']);
    }
}
