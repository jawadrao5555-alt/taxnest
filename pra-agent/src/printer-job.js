'use strict';

// Transport and spool outcomes are different. A failed content download never
// invokes printing; a failed/lost acknowledgement never causes a second print.
async function processCloudPrintJob(job, { fetchContent, printHtml, report, clock = () => performance.now(), pause = ms => new Promise(resolve => setTimeout(resolve, ms)) }) {
  const start = clock();
  const elapsed = since => Math.min(300000, Math.max(0, Math.round(clock() - since)));
  let content;
  let content_attempts = 0;
  try {
    for (;;) {
      content_attempts++;
      try { content = await fetchContent(job); break; }
      catch (error) {
        // Same job/claim, before any print call: one bounded download recovery.
        // HTTP/auth/claim failures and all post-spool errors are never retried.
        if (content_attempts >= 2 || !['ECONNABORTED', 'ETIMEDOUT', 'ECONNRESET', 'ENETUNREACH'].includes(error.code)) throw error;
        await pause(200);
      }
    }
  } catch (error) {
    const result = { success: false, error: error.message, outcome: 'pre_spool_failed', stage: 'content_fetch', content_ms: elapsed(start), content_attempts };
    await report(job, result);
    return result;
  }
  const content_ms = elapsed(start);
  if (content.status === 204 || !content.data) {
    const result = { success: true, error: null, outcome: 'no_document', stage: 'content_received', content_ms, content_attempts };
    await report(job, result);
    return result;
  }
  const printStart = clock();
  let printed;
  try { printed = await printHtml(content.data, job.target_printer, job.type); }
  catch (error) { printed = { success: false, error: error.message }; }
  const result = { ...printed, outcome: printed.success ? 'spool_accepted' : 'output_unknown', stage: 'print_call', content_ms, content_attempts, print_ms: elapsed(printStart) };
  await report(job, result);
  return result;
}

module.exports = { processCloudPrintJob };
