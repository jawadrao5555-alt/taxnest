import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import vm from 'node:vm';

for (const layout of ['pos-app', 'fbr-pos-app']) {
    function component(fetch) {
        const html = fs.readFileSync(new URL('../../resources/views/layouts/' + layout + '.blade.php', import.meta.url), 'utf8');
        const match = html.match(/<div x-data="(\{ wnOpen: true,[\s\S]*?)"\s+x-show="wnOpen"/);
        assert.ok(match, 'real featured notice component exists');
        const expression = match[1].replace(/\{\{ \(int\) \$whatsNewPopup->id \}\}/g, '123')
            .replace(/@if[^\n]*@endif/g, '');
        const window = { TnModalA11y: { close() {} }, location: { href: '' } };
        const notice = vm.runInNewContext('(' + expression + ')', {
            fetch, window, document: { querySelector: () => ({ content: 'synthetic-csrf' }) },
        });
        notice.$refs = { wnDialog: {} };
        notice.$nextTick = callback => callback();
        return { notice, window };
    }
    test(layout + ': failed acknowledgement keeps notice visible, retry closes only after server success', async () => {
        let calls = 0;
        const { notice } = component(async (url, options) => {
            assert.equal(url, layout === 'pos-app' ? '/pos/whats-new/seen' : '/fbr-pos/whats-new/seen');
            assert.equal(JSON.parse(options.body).update_id, 123);
            calls++;
            return { ok: calls > 1, json: async () => ({ ok: true }) };
        });
        assert.equal(await notice.wnDismiss(), false);
        assert.equal(notice.wnOpen, true);
        assert.ok(notice.wnError);
        assert.equal(notice.wnSaving, false);
        assert.equal(await notice.wnDismiss(), true);
        assert.equal(notice.wnOpen, false);
        assert.equal(notice.wnError, '');
    });
    test(layout + ': failed save prevents navigation and an in-flight save prevents duplicate requests', async () => {
        let release, calls = 0;
        const { notice, window } = component(() => {
            calls++;
            return new Promise(resolve => { release = resolve; });
        });
        const first = notice.wnDismiss();
        assert.equal(await notice.wnDismiss(), false);
        assert.equal(calls, 1);
        release({ ok: false, json: async () => ({ ok: false }) });
        await first;
        const navigation = notice.wnTry('/next');
        release({ ok: true, json: async () => ({ ok: false }) });
        await navigation;
        assert.equal(window.location.href, '');
        assert.equal(notice.wnOpen, true);
    });
}
