import assert from 'node:assert/strict';
import test from 'node:test';

await import('../../public/js/pos-print-attempt.js');
const api = globalThis.NestPosPrintAttempt;

test('new and existing jobs in every status are accepted without fallback', async () => {
    for (const status of ['pending', 'printing', 'done', 'failed']) {
        const payload = api.billPayload(41);
        const result = await api.run({
            payload,
            send: async () => ({ accepted: true, data: { success: true, status } }),
            wait: async () => {}
        });
        assert.equal(result.state, 'accepted');
        assert.notEqual(result.fallbackAllowed, true);
    }
});

test('lost response retries the exact same payload and accepts the existing job', async () => {
    const payload = api.billPayload(42);
    const seen = [];
    const result = await api.run({
        payload,
        send: async (sent) => {
            seen.push(sent);
            if (seen.length === 1) throw new Error('response lost');
            return { accepted: true, data: { success: true, deduped: true, status: 'done' } };
        },
        wait: async () => {}
    });
    assert.equal(seen.length, 2);
    assert.strictEqual(seen[0], payload);
    assert.strictEqual(seen[1], payload);
    assert.equal(seen[0].print_attempt_uuid, seen[1].print_attempt_uuid);
    assert.equal(result.state, 'accepted');
    assert.notEqual(result.fallbackAllowed, true);
});

test('two lost or malformed responses become accepted-unknown and suppress fallback', async () => {
    const payload = api.billPayload(43);
    for (const send of [
        async () => { throw new Error('response lost'); },
        async () => ({})
    ]) {
        const result = await api.run({ payload, send, wait: async () => {} });
        assert.equal(result.state, 'accepted_unknown');
        assert.equal(result.fallbackAllowed, false);
    }
});

test('definitive pre-enqueue failure still permits iframe fallback', async () => {
    const result = await api.run({
        payload: api.billPayload(44),
        send: async () => ({ definitiveFailure: true, retryable: false }),
        wait: async () => {}
    });
    assert.equal(result.state, 'rejected');
    assert.equal(result.fallbackAllowed, true);
});

test('ambiguous 5xx-style failures suppress fallback after the retry', async () => {
    let calls = 0;
    const result = await api.run({
        payload: api.billPayload(45),
        send: async () => {
            calls++;
            return {};
        },
        wait: async () => {}
    });
    assert.equal(calls, 2);
    assert.equal(result.state, 'accepted_unknown');
    assert.equal(result.fallbackAllowed, false);
});

test('manual reprint payload gets a fresh UUID', () => {
    const first = api.billPayload(46);
    const reprint = api.billPayload(46);
    assert.notEqual(first.print_attempt_uuid, reprint.print_attempt_uuid);
});

test('known pre-dispatch offline state keeps fallback available', () => {
    assert.equal(api.canDispatch(false), false);
    assert.equal(api.canDispatch(true), true);
});

test('delivery-board decision calls fallback only for a definitive rejection', async () => {
    async function deliveryFallbackWasCalled(send) {
        let fallbackCalled = false;
        const decision = await api.run({
            payload: api.billPayload(47),
            send,
            wait: async () => {}
        });
        if (decision.fallbackAllowed) fallbackCalled = true;
        return fallbackCalled;
    }

    assert.equal(await deliveryFallbackWasCalled(async () => {
        throw new Error('both responses lost after enqueue');
    }), false);
    assert.equal(await deliveryFallbackWasCalled(async () => ({
        definitiveFailure: true,
        retryable: false
    })), true);
});