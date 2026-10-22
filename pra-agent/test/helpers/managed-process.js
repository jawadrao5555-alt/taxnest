'use strict';

// Own only the process tree launched by this test. In POSIX a wrapper such as
// xvfb-run can exit before Electron; its inherited pipes still keep Node alive.
function managedProcess(child, processGroup = false) {
  const instance = { child, processGroup, closed: false };
  child.once('close', () => { instance.closed = true; });
  return instance;
}
async function stopManagedProcess(instance) {
  if (!instance || instance.closed) return;
  const { child, processGroup } = instance;
  const closed = new Promise(resolve => child.once('close', resolve));
  function signal(name) {
    try {
      if (processGroup) process.kill(-child.pid, name);
      else if (child.exitCode === null && child.signalCode === null) child.kill(name);
    } catch (error) { if (error.code !== 'ESRCH') throw error; }
  }
  signal('SIGTERM');
  const timer = setTimeout(() => signal('SIGKILL'), 5000);
  timer.unref();
  try { await closed; } finally { clearTimeout(timer); }
}
module.exports = { managedProcess, stopManagedProcess };
