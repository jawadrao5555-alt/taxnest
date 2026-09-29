/* Hotel form previews use the server's canonical tax and pricing engine. */
window.hotelBookingForm = function (config) {
    return {
        ...config, quote: null, error: '', busy: false, revision: 0, submitting: false,
        init() {
            for (const key of ['room', 'arrival', 'departure', 'rate', 'discountType', 'discountValue', 'method']) {
                this.$watch(key, () => { ++this.revision; this.quote = null; this.busy = true; clearTimeout(this.timer); this.timer = setTimeout(() => this.refresh(), 200); });
            }
            this.refresh();
        },
        selectRoom() {
            const standard = Number(this.rooms[this.room]?.rate || 0);
            this.rate = standard > 0 ? standard : '';
        },
        selectGuest(event) {
            const guest = this.customers[event.target.value];
            if (guest) { this.$refs.guest.value = guest.name; this.$refs.phone.value = guest.phone || ''; }
        },
        selectRecentGuest(event) {
            const guest = this.recentGuests[event.target.value];
            if (guest) { this.$refs.guest.value = guest.name; this.$refs.phone.value = guest.phone || ''; this.$refs.cnic.value = guest.cnic || ''; }
        },
        async refresh() {
            const revision = ++this.revision;
            this.quote = null; this.error = ''; this.busy = true;
            if (!this.room || this.rate === '') { this.busy = false; return; }
            const params = new URLSearchParams({room_id: this.room, check_in_date: this.arrival, check_out_date: this.departure, rate_amount: this.rate, discount_type: this.discountType, discount_value: this.discountValue || 0, payment_method: this.method});
            try {
                const response = await fetch(this.url + '?' + params, {headers: {Accept: 'application/json'}, credentials: 'same-origin'});
                const data = await response.json();
                if (revision !== this.revision) return;
                if (!response.ok) throw new Error(data.message || this.failure);
                this.quote = data;
                if (!data.available || (this.walkIn && data.dirty)) this.error = data.dirty && this.walkIn ? this.dirtyMessage : this.unavailable;
            } catch (error) { if (revision === this.revision) this.error = error.message || this.failure; }
            finally { if (revision === this.revision) this.busy = false; }
        },
        money(value) { return Number(value || 0).toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2}); },
    };
};
window.hotelCheckoutForm = function (config) {
    return {
        ...config, busy: false, error: '', submitting: false, revision: 0,
        async refresh() {
            const revision = ++this.revision;
            this.busy = true; this.error = '';
            try {
                const response = await fetch(this.url + '?payment_method=' + encodeURIComponent(this.method), {headers: {Accept: 'application/json'}, credentials: 'same-origin'});
                const data = await response.json();
                if (revision !== this.revision) return;
                if (!response.ok) throw new Error(data.message || this.failure);
                this.quote = data; this.amount = data.balance;
            } catch (error) { if (revision === this.revision) this.error = error.message || this.failure; }
            finally { if (revision === this.revision) this.busy = false; }
        },
        money(value) { return Number(value || 0).toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2}); },
    };
};
window.hotelCollectionForm = function (config) {
    return {
        ...config, method: 'cash', amount: '', kind: 'payment', quote: null, busy: false, error: '', revision: 0, edited: false,
        init() { this.refresh(); },
        selectMethod() { this.edited = false; this.refresh(); },
        async refresh() {
            const revision = ++this.revision;
            this.busy = true; this.error = '';
            try {
                const response = await fetch(this.url + '?payment_method=' + encodeURIComponent(this.method), {headers: {Accept: 'application/json'}, credentials: 'same-origin'});
                const data = await response.json();
                if (revision !== this.revision) return;
                if (!response.ok) throw new Error(data.message || this.failure);
                this.quote = data;
                if (!this.edited && this.kind === 'payment') this.amount = data.balance > 0 ? data.balance : '';
            } catch (error) { if (revision === this.revision) { this.quote = null; this.error = error.message || this.failure; } }
            finally { if (revision === this.revision) this.busy = false; }
        },
        money(value) { return Number(value || 0).toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2}); },
    };
};
window.hotelChangeForm = function (config) {
    return {
        ...config, quote: null, error: '', busy: false, revision: 0,
        init() { for (const key of ['rate', 'date', 'room']) this.$watch(key, () => { ++this.revision; this.quote = null; clearTimeout(this.timer); this.timer = setTimeout(() => this.refresh(), 200); }); },
        async refresh() {
            const revision = ++this.revision;
            this.busy = true; this.error = ''; this.quote = null;
            try {
                const response = await fetch(this.url + '?' + new URLSearchParams({kind: this.kind, rate_amount: this.rate, check_out_date: this.date || '', room_id: this.room || ''}), {headers: {Accept: 'application/json'}, credentials: 'same-origin'});
                const data = await response.json();
                if (revision !== this.revision) return;
                if (!response.ok) throw new Error(data.message || this.failure);
                this.quote = data;
            } catch (error) { if (revision === this.revision) this.error = error.message || this.failure; }
            finally { if (revision === this.revision) this.busy = false; }
        },
        money(value) { return Number(value || 0).toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2}); },
    };
};
