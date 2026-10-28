import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import vm from 'node:vm';

// Exercise the actual shared preview state factory; browser acceptance separately tests real controls.
const source = readFileSync('resources/views/pos/partials/receipt-theme-preview.blade.php', 'utf8');
const script = source.match(/<script>([\s\S]*?)<\/script>/)[1];
const sandbox = { window: {} };
vm.runInNewContext(script, sandbox);
const make = () => sandbox.window.rcptThemePicker({
  theme: 'current', themes: { current: { bold: false, logo: 'center' } },
  prefs: { logo: false, menuQr: false }, paper: '80mm', mode: 'pra', live: true,
});

test('actual preview keeps legacy defaults and never hides fiscal QR', () => {
  const state = make();
  assert.equal(state.showLogoNow(), false);
  assert.equal(state.optionShow('show_item_serial', false), false);
  assert.equal(state.qrNow(), true);
  state.optional = { show_menu_qr: 'hide' };
  assert.equal(state.qrNow(), true);
  state.tab = 'local';
  assert.equal(state.qrNow(), false);
  state.optional.show_menu_qr = 'show';
  assert.equal(state.qrNow(), true);
});

test('actual preview applies scoped optional styles without changing amounts or executing note', () => {
  const state = make();
  state.optional = { logo_position: 'side', total_size: 'large', total_bold: 'normal', item_bold: 'bold',
    extra_note: '<script>doNotRun()</script>', font_family: 'url(evil)' };
  assert.equal(state.thLogo(), 'side');
  const css = state.typographyPreviewCss();
  assert.match(css, /font-size:20px/);
  assert.match(css, /font-weight:400/);
  assert.doesNotMatch(css, /url\(evil\)|doNotRun/);
  state.optional = {};
  assert.equal(state.thLogo(), 'center');
  assert.equal(state.typographyPreviewCss(), '');
});

test('explicit Local logo visibility overrides the legacy finals-only rule; inheritance preserves it', () => {
  const state=make(); state.tab='local'; state.p.logo=true; state.p.logoFinalsOnly=true;
  assert.equal(state.showLogoNow(),false);
  state.optional={show_logo:'show'}; assert.equal(state.showLogoNow(),true);
  state.optional={show_logo:'hide'}; assert.equal(state.showLogoNow(),false);
  state.optional={}; assert.equal(state.showLogoNow(),false);
});
