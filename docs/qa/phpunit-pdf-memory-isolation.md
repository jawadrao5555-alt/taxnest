# Full PHPUnit PDF memory isolation

Failure evidence: run43701940996, Full PHPUnit job132774781231, head
71df986d97a9e6d8f0fc0ad3a3a9184c0aba65cb. The process exhausted its existing1GiB
ceiling in Dompdf Cpdf.php2215 (gzcompress) after6222 progress tests plus21 dots.
Exact6405-test order identifies the next test as SubscriptionDocumentAccessTest
::test_company_owner_sees_itemized_history_and_downloads_scoped_pdf_documents.

That entire4-test class passes independently (48 assertions,94.50MB peak). This
is evidence against an isolated1GiB document explosion, not proof every PDF is
bounded. An aggregate of six real PDF/ZIP test classes was then instrumented at
Laravel's completed teardown boundary.53 tests/265 assertions ran; explicit cycle
collection reclaimed500,920,656 bytes across1,826,028 unreachable cycles. The
largest single cleanup reclaimed48,180,752 bytes (ZIP resume test). Peak219MB.
Only test names and numeric memory/cycle counts were captured; no request/document
content, configuration, credentials or customer data. Temporary profiling code is
not shipped. The28 reported PHPUnit deprecations were not hidden or suppressed.

The minimal fix collects unreachable cycles after Laravel has released its
application and Mockery has checked assertions, including when teardown throws.
Automatic PHP GC stays enabled. The1GiB cap is unchanged. No process-isolation
exception, skipped test, PDF stub, simplified document or removed assertion is
introduced. Dompdf frame/canvas/request graphs contain cycles; relying only on
PHP's adaptive collection threshold can defer reclamation across a long suite.
This addresses measured cycle accumulation, not a claim that all retained memory
or every possible native renderer leak has been identified.

Final exact integrated-head full-suite CI is still required. Focused aggregate
success must not be reported as complete full-suite success.

A/B limitation: the unchanged short aggregate also passed53/265 at213MB; the
minimal candidate on current integration base55434d8 passed53/265 at219MB. These
short runs do NOT demonstrate peak reduction or prove the full OOM is resolved.
The patch is a candidate long-process isolation correction with bounded numeric
telemetry (at most one line per qualifying class) to verify the next full run.
A persistent post-collection growth would require investigating retained roots;
it is not permission to raise the limit or skip the failing PDF assertion.
