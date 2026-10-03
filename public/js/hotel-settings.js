(function (root) {
    'use strict';
    root.TnHotelSettings = function (initial, messages) {
        return {
            values: { ...initial }, busy: {}, status: '', error: '',
            async save(key, url, payload, acceptedValue, acknowledgementKey = 'success') {
                if (this.busy[key]) return false;
                this.busy[key] = true;
                this.status = messages.saving;
                this.error = '';
                try {
                    const response = await fetch(url, {
                        method: 'POST', credentials: 'same-origin',
                        headers: { 'Content-Type': 'application/json', 'Accept': 'application/json',
                            'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]')?.content || '' },
                        body: JSON.stringify(payload),
                    });
                    const result = await response.json();
                    if (!response.ok || result?.[acknowledgementKey] !== true) {
                        throw new Error(result?.message || messages.failed);
                    }
                    this.values[key] = acceptedValue;
                    if (key === 'theme') document.body.setAttribute('data-theme', acceptedValue);
                    this.status = messages.saved;
                    return true;
                } catch (error) {
                    this.status = '';
                    this.error = error?.message || messages.failed;
                    return false;
                } finally {
                    this.busy[key] = false;
                }
            },
        };
    };
})(typeof window === 'undefined' ? globalThis : window);
