'use strict';

// The detached Windows script cannot report through Electron after the app
// quits. Its small outcome file survives the relaunch of an OLD Agent.
function failedHandoff(state, localVersion, isNewerVersion, readFile) {
  if (!state || !state.target || !isNewerVersion(state.target, localVersion)) return null;
  let result = 'missing';
  try { result = String(readFile(state.resultPath, 'utf8')).trim().slice(0, 80); } catch (_) {}
  const reasons = {
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

module.exports = { failedHandoff };
