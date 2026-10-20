import {test} from 'node:test';
import assert from 'node:assert/strict';
import {mkdtempSync, mkdirSync, symlinkSync, rmSync} from 'node:fs';
import {join} from 'node:path';
import {recordingDirectory} from '../lib/tutorial-recording.mjs';

test('recording stays opt-in and rejects live paths and unrestricted journeys', () => {
  assert.equal(recordingDirectory('0', '', []), null);
  assert.throws(() => recordingDirectory('1', '/var/www/fixture.json', ['restaurant-top-selling']));
  assert.throws(() => recordingDirectory('1', '/tmp/taxnest-rc-browser-123/safe-runtime/browser-state/fixture.json', []));
  assert.throws(() => recordingDirectory('1', '/tmp/taxnest-rc-browser-123/safe-runtime/browser-state/fixture.json', ['admin-dashboard']));
});
test('exact temporary fixture can record, symlink target cannot', () => {
  const root=mkdtempSync('/tmp/taxnest-rc-browser-123-'), state=join(root,'safe-runtime/browser-state');
  try {
    mkdirSync(state,{recursive:true});
    const dir=recordingDirectory('1',join(state,'fixture.json'),['restaurant-top-selling']);
    assert.equal(dir,join(state,'tutorial-videos'));
    rmSync(dir,{recursive:true}); symlinkSync('/tmp',dir);
    assert.throws(()=>recordingDirectory('1',join(state,'fixture.json'),['restaurant-top-selling']),/Unsafe/);
  } finally {rmSync(root,{recursive:true,force:true});}
});
