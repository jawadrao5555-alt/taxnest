(function (root) {
    'use strict';

    function newUuid() {
        try {
            if (root.crypto && root.crypto.randomUUID) return root.crypto.randomUUID();
        } catch (e) {}
        return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, function (c) {
            var r = Math.floor(Math.random() * 16);
            return (c === 'x' ? r : ((r & 0x3) | 0x8)).toString(16);
        });
    }

    function billPayload(transactionId) {
        return {
            type: 'bill',
            transaction_id: transactionId,
            print_attempt_uuid: newUuid()
        };
    }

    function canDispatch(online) {
        return online !== false;
    }

    function normalize(result) {
        if (result && result.accepted) {
            return { state: 'accepted', data: result.data || null };
        }
        if (result && result.definitiveFailure) {
            return {
                state: 'rejected',
                retryable: !!result.retryable,
                data: result.data || null
            };
        }
        return { state: 'unknown' };
    }

    async function run(options) {
        var send = options.send;
        var payload = options.payload;
        var wait = options.wait || function (ms) {
            return new Promise(function (resolve) { root.setTimeout(resolve, ms); });
        };
        var retryDelay = Number.isFinite(options.retryDelay) ? options.retryDelay : 1200;

        async function once() {
            try {
                return normalize(await send(payload));
            } catch (e) {
                return { state: 'unknown' };
            }
        }

        var first = await once();
        if (first.state === 'accepted') return first;
        if (first.state === 'rejected' && !first.retryable) {
            return { state: 'rejected', fallbackAllowed: true, data: first.data };
        }

        await wait(retryDelay);
        var second = await once();
        if (second.state === 'accepted') return second;
        if (second.state === 'rejected') {
            return { state: 'rejected', fallbackAllowed: true, data: second.data };
        }

        // A dispatched request with no trustworthy response may already have
        // printed. Suppress automatic iframe printing for this same attempt.
        return { state: 'accepted_unknown', fallbackAllowed: false };
    }

    root.NestPosPrintAttempt = {
        newUuid: newUuid,
        billPayload: billPayload,
        canDispatch: canDispatch,
        run: run
    };
})(typeof globalThis !== 'undefined' ? globalThis : window);