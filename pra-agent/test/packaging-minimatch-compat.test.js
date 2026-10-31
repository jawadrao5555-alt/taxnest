'use strict';
const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const minimatch = require('minimatch');
const asar = require('@electron/asar');
const glob = require('glob');
const { FileList } = require('filelist');
const compare = require('dir-compare');

test('patched matching supports both legacy callable/defaults and named constructor APIs', () => {
  assert.equal(minimatch('main.js', '*.js'), true);
  assert.equal(minimatch.minimatch('src/main.js', '**/*.js'), true);
  assert.equal(new minimatch.Minimatch('**/*.js').match('src/main.js'), true);
  assert.equal(minimatch.defaults({ nocase: true })('MAIN.JS', '*.js'), true);
  assert.equal(minimatch.defaults({ nocase: true }).defaults({ dot: true })('.MAIN.JS', '*.js'), true);
  assert.deepEqual(minimatch.match(['a.js', 'b.tmp'], '*.js'), ['a.js']);
});

test('actual legacy ASAR, glob, filelist and directory filters package the intended files', async () => {
  const root = fs.mkdtempSync(path.join(os.tmpdir(), 'taxnest-package-match-'));
  try {
    const source = path.join(root, 'source');
    fs.mkdirSync(path.join(source, 'src'), { recursive: true });
    fs.writeFileSync(path.join(source, 'main.js'), 'module.exports = "fixture";');
    fs.writeFileSync(path.join(source, 'src', 'worker.js'), '// fixture');
    fs.writeFileSync(path.join(source, 'binding.node'), 'fictional native bytes');
    const wanted = ['main.js', 'src/worker.js'];
    assert.deepEqual(glob.sync('**/*.js', { cwd: source }).sort(), wanted);
    const list = new FileList(); list.include(path.join(source, '**/*.js'));
    assert.deepEqual(list.toArray().map(file => path.relative(source, file).split(path.sep).join('/')).sort(), wanted);
    const archive = path.join(root, 'application.asar');
    await asar.createPackageWithOptions(source, archive, { unpack: '*.node' });
    assert.equal(asar.extractFile(archive, 'main.js').toString(), 'module.exports = "fixture";');
    assert.equal(asar.statFile(archive, 'binding.node').unpacked, true);
    const restored = path.join(root, 'restored'); asar.extractAll(archive, restored);
    fs.writeFileSync(path.join(source, 'ignored.tmp'), 'first');
    fs.writeFileSync(path.join(restored, 'ignored.tmp'), 'second');
    assert.equal(compare.compareSync(source, restored, { compareContent: true, excludeFilter: '*.tmp' }).same, true);
  } finally { fs.rmSync(root, { recursive: true, force: true }); }
});
