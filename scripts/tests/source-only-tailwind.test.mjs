import test from 'node:test';
import assert from 'node:assert/strict';
import {createRequire} from 'node:module';
import {fileURLToPath} from 'node:url';
const require=createRequire(import.meta.url);
const config=require('tailwindcss/loadConfig')(fileURLToPath(new URL('../../tailwind.config.js',import.meta.url)));
import {readFileSync} from 'node:fs';
test('Tailwind scans canonical views and vendor pagination, never generated compiled views',()=>{
 assert.ok(config.content.includes('./resources/views/**/*.blade.php'));
 assert.ok(config.content.includes('./vendor/laravel/framework/src/Illuminate/Pagination/resources/views/*.blade.php'));
 assert.ok(config.content.every(path=>!path.includes('storage/')&&!path.includes('public/build')));
});
test('ERP source check remains fail-closed and diagnostic contains paths, not file contents',()=>{
 const s=readFileSync(new URL('../check-erp-demo-browser.sh',import.meta.url),'utf8');
 assert.match(s,/git status --porcelain --untracked-files=normal/);
 assert.match(s,/NR<=40/);assert.match(s,/substr\(\$0,1,512\)/);
 assert.match(s,/exit 2\nfi/);assert.doesNotMatch(s,/git diff|git checkout|git restore|git reset/);
});
