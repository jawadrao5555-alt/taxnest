'use strict';

// The detached Windows script cannot report through Electron after the app
// quits. Its small outcome file survives the relaunch of an OLD Agent.
function failedHandoff(state, localVersion, isNewerVersion, readFile) {
  if (!state || !state.target || !isNewerVersion(state.target, localVersion)) return null;
  let result = 'missing';
  try { result = String(readFile(state.resultPath, 'utf8')).trim().slice(0, 80); } catch (_) {}
  const reasons = {
    recovery_waiting_for_agent: 'The Agent is still running; recovery did not change its files. Close it before manual repair.',
    recovered_before_copy: 'The interrupted updater had not changed any Agent files; the previous Agent is running.',
    recovered_old_version: 'An interrupted update was repaired; the previous Agent version is running.',
    preparing: 'The updater was interrupted before installation completed; Windows recovery is pending.',
    failed_updater_start: 'Windows could not start the updater; the old Agent is still available.',
    copying: 'Installation was interrupted; Windows recovery is pending.',
    failed_recovery_registration: 'Windows could not enable update recovery; the old installation was left unchanged.',
    failed_backup: 'Updater could not back up this installation.',
    failed_copy_or_verify: 'Updater could not replace or verify the Agent files; backup restore attempted.',
    failed_restore: 'Updater could not replace the Agent files or restore the backup. Manual installation is required.',
    failed_source_missing: 'Downloaded Agent package had no app.asar.',
    installed: 'Updater copied files, but the old Agent version restarted.',
    missing: 'Updater did not report an installation result.',
  };
  return {
    target: state.target,
    stage: 'install',
    error: reasons[result] || 'Updater returned an unrecognized installation result.',
    at: new Date().toISOString(),
  };
}

function recoveryPending(state, readFile) {
  if (!state || !state.recoveryPath) return false; // legacy handoffs unchanged
  try {
    const result = String(readFile(state.resultPath, 'utf8')).trim();
    return !['installed','recovered_old_version','recovered_before_copy','failed_copy_or_verify',
      'failed_restore','failed_backup','failed_source_missing','failed_updater_start',
      'failed_recovery_registration','recovery_waiting_for_agent'].includes(result);
  }
  catch (_) { return true; } // uncertain handoff: never launch a second installer
}

module.exports = { failedHandoff, recoveryPending };
