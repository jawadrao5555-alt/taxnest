@php
    $catalogs = json_decode(file_get_contents(resource_path('data/fbr/dsi-1.0.44.json')), true, 512, JSON_THROW_ON_ERROR)['catalogs'];
@endphp
<datalist id="dsi-sro-schedules">
    @foreach($catalogs['sro'] as $schedule)
        <option value="{{ $schedule }}"></option>
    @endforeach
</datalist>
<datalist id="dsi-item-serials">
    @foreach($catalogs['serial'] as $serial)
        <option value="{{ $serial }}"></option>
    @endforeach
</datalist>

<script>
    window.diSpecialTax = (() => {
        const rules = @js(\App\Services\DiSpecialTax::RULES);
        const round = (value, decimals) => {
            const factor = 10 ** decimals;
            const scaled = value * factor;
            return Math.round(scaled + Number.EPSILON * Math.abs(scaled)) / factor;
        };
        const getRule = item => Object.hasOwn(rules, item.schedule_type) ? rules[item.schedule_type] : null;
        const packingFactor = item => Number((item.packings || []).find(pack => pack.id === item.selling_packing_id)?.factor ?? 1);
        const reportingQuantity = item => round(round(Number(item.quantity || 0), 4) * packingFactor(item), 4);
        const excise = item => round((getRule(item)?.fed ?? 0) * reportingQuantity(item), 2);

        return {
            getRule,
            round,
            excise,
            salesTax(item, subtotal) {
                const rule = getRule(item);
                const mrp = Number(item.mrp || 0);
                const base = rule.retail
                    ? round(mrp * round(Number(item.quantity || 0), 4), 2) : subtotal + excise(item);
                return round(base * rule.percentage / 100 + rule.fixed_tax * reportingQuantity(item), 2);
            },
            exclusivePrice(item, gross) {
                const rule = getRule(item);
                const factor = packingFactor(item);
                const percentage = rule.percentage / 100;
                const fixedDuty = rule.fed * factor;
                const mrp = Number(item.mrp || 0);
                const unfixed = gross - rule.fixed_tax * factor;
                return round(rule.retail
                    ? unfixed - mrp * percentage - fixedDuty
                    : unfixed / (1 + percentage) - fixedDuty, 6);
            },
        };
    })();

    window.diProductProfileCheck = () => {
        let key = '';
        let sequence = 0;
        let timer;
        let context;
        const endpoint = @js(route('invoice.fbr-reference-options', [], false));
        return {
            message: '',
            retryable: false,
            queue(value) {
                const next = JSON.stringify(value);
                if (key === next) return;
                key = next;
                context = value;
                this.retry();
            },
            retry() {
                const request = ++sequence;
                clearTimeout(timer);
                this.retryable = false;
                this.message = '';
                if (!context?.[2]) return;
                const [product_id, profile_revision, invoice_date, branch, matches] = context;
                if (!matches) {
                    this.message = 'Tax fields differ from the saved product defaults. The invoice line will be checked before FBR submission.';
                    return;
                }
                const params = new URLSearchParams({product_id, profile_revision, invoice_date});
                if (branch) params.set('branch_id', branch);
                this.message = 'Checking saved product tax defaults…';
                timer = setTimeout(async () => {
                    try {
                        const response = await fetch(endpoint + '?' + params, {headers: {Accept: 'application/json'}});
                        if (!response.ok) throw new Error('Reference check unavailable');
                        const {errors, province} = await response.json();
                        if (request !== sequence) return;
                        this.message = errors.length
                            ? errors.join(' ') + ' Review the line using Search FBR tax references.'
                            : 'Saved product tax defaults confirmed for ' + province + ' on ' + invoice_date + '.';
                    } catch {
                        if (request !== sequence) return;
                        this.message = 'Unable to recheck saved product tax defaults. Keep the draft and retry.';
                        this.retryable = true;
                    }
                }, 350);
            },
            destroy() {
                clearTimeout(timer);
                sequence++;
            },
        };
    };


    window.dsiReferencePicker = (() => {
        const catalogs = @js(array_intersect_key($catalogs, array_flip(['hs', 'sale_type', 'rate', 'sro', 'serial', 'uom'])));
        const schedules = new Map([
            ['Goods at standard rate (default)', 'standard'],
            ['Goods at Reduced Rate', 'reduced'],
            ['Goods at zero-rate', 'zero_rated'],
            ['3rd Schedule Goods', '3rd_schedule'],
            ['Exempt goods', 'exempt'],
            ['Services (FED in ST Mode)', 'fed_services'],
            ['Services', 'services'],
        ]);
        const units = new Map([
            ['KG', 'KG'], ['Liter', 'Liter'], ['Kilogram', 'Kilograms'],
            ['Meter', 'Meters'], ['Square Metre', 'Square meters'],
            ['Cubic Metre', 'Cubic meters'], ['Packs', 'Packs'],
            ['Dozen', 'Dozens'], ['Thousand Unit', 'Thousand Unit'],
            ['Numbers, pieces, units', 'Numbers, pieces, units'],
        ]);

        const officialCategories = { rate: 'rates', sro: 'schedules', serial: 'serials', uom: 'units' };
        const endpoint = @js(route('invoice.fbr-reference-options', [], false));

        return () => ({
            category: 'hs',
            query: '',
            applying: false,
            feedback: '',
            live: false,
            loading: false,
            liveStatus: '',
            officialOptions: {},
            lookupKey: '',
            lookupContext: null,
            timer: null,
            requestNumber: 0,

            get isOfficialCategory() {
                return Object.hasOwn(officialCategories, this.category);
            },

            queueLookup(context) {
                // Keep the Alpine effect dependent on form inputs only.
                queueMicrotask(() => this.scheduleLookup(context));
            },

            scheduleLookup(context, retry = false) {
                const key = JSON.stringify(context);
                if (!retry && this.lookupKey === key) return;
                this.lookupKey = key;
                this.lookupContext = context;
                clearTimeout(this.timer);
                const request = ++this.requestNumber;
                this.officialOptions = {};
                this.feedback = '';
                this.loading = Boolean(context?.[0]);
                this.liveStatus = this.loading ? 'Looking up FBR options…' : '';
                if (this.loading) this.timer = setTimeout(() => this.loadOptions(context, request), 350);
            },

            retryLookup() {
                if (this.lookupContext) this.scheduleLookup(this.lookupContext, true);
            },

            async loadOptions([date, branch, hs, schedule, rate, sro], request) {
                const params = new URLSearchParams({ invoice_date: date, schedule_type: schedule });
                if (branch) params.set('branch_id', branch);
                if (/^\d{4}\.?\d{4}$/.test(hs)) params.set('hs', hs);
                if (rate !== null && rate !== undefined && rate !== '') params.set('tax_rate', rate);
                if (sro) params.set('sro', sro);
                try {
                    const response = await fetch(endpoint + '?' + params, { headers: { Accept: 'application/json' } });
                    if (!response.ok) throw new Error('Lookup unavailable');
                    const data = await response.json();
                    if (this.requestNumber !== request) return;
                    this.officialOptions = data.options;
                    const incomplete = Object.values(data.options).some(rows => rows === null);
                    this.liveStatus = incomplete
                        ? 'Some FBR options could not be verified for ' + data.province + '.'
                        : '';
                } catch {
                    if (this.requestNumber === request) this.liveStatus = 'FBR options are unavailable. Your draft values are intact.';
                } finally {
                    if (this.requestNumber === request) this.loading = false;
                }
            },

            get results() {
                const query = this.query.trim().toLowerCase();
                if (this.category === 'hs' && !query) return [];

                const official = this.live && this.isOfficialCategory;
                const values = official
                    ? (this.officialOptions[officialCategories[this.category]] ?? []).map(row => row.label)
                    : catalogs[this.category];
                const entries = [];
                const seen = new Set();
                for (const value of values) {
                    const parsed = official && this.category === 'rate' ? String(value).match(/^([0-9]+(?:\.[0-9]+)?)\s*%$/) : null;
                    const percentage = parsed ? Number(parsed[1]) : this.category === 'rate' && typeof value === 'number'
                        ? Number((value * 100).toFixed(4)) : null;
                    const label = percentage === null ? String(value).replace(/:-$/, '') : percentage + '%';
                    const matches = label.toLowerCase().includes(query)
                        || (this.category === 'hs' && /^\d+$/.test(query)
                            && label.split(':')[0].replace('.', '').startsWith(query));
                    if (!matches || seen.has(label)) continue;
                    seen.add(label);
                    entries.push({ category: this.category, value, label, percentage, official });
                    if (entries.length === 25) break;
                }
                return entries;
            },

            canApply(entry, item) {
                switch (entry.category) {
                    case 'hs': return !item.product_id;
                    case 'sale_type': return schedules.has(entry.value);
                    case 'rate': return !window.diSpecialTax.getRule(item) && entry.percentage !== null && entry.percentage >= 0
                        && entry.percentage <= 100 && Number(entry.percentage.toFixed(2)) === entry.percentage;
                    case 'sro': return item.requires_sro || item.optional_sro;
                    case 'serial': return entry.value !== '-' && (item.requires_serial || item.optional_serial);
                    case 'uom': return units.has(entry.value) && !item.selling_packing_id;
                    default: return false;
                }
            },

            async apply(entry, item, callbacks) {
                if (this.applying || !this.canApply(entry, item)) return;
                this.applying = true;
                this.feedback = '';
                try {
                    switch (entry.category) {
                        case 'hs': {
                            const hsCode = entry.value.split(':')[0];
                            if (item.hs_code !== hsCode) {
                                item.hs_code = hsCode;
                                await callbacks.lookup();
                            }
                            break;
                        }
                        case 'sale_type': {
                            const schedule = schedules.get(entry.value);
                            if (item.schedule_type !== schedule) {
                                item.schedule_type = schedule;
                                callbacks.schedule();
                            }
                            break;
                        }
                        case 'rate':
                            if (entry.official && Number(item.tax_rate) !== entry.percentage) {
                                item.sro_schedule_no = '';
                                item.serial_no = '';
                            }
                            item.tax_rate = entry.percentage;
                            callbacks.calculate();
                            break;
                        case 'sro':
                            if (item.sro_schedule_no !== entry.value) {
                                item.sro_schedule_no = entry.value;
                                item.serial_no = '';
                            }
                            break;
                        case 'serial':
                            item.serial_no = entry.value;
                            break;
                        case 'uom':
                            item.default_uom = units.get(entry.value);
                            break;
                    }
                    if (entry.official) item.fbr_reference_checked = true;
                    this.feedback = 'Selected ' + entry.label + '. Check the complete invoice line before submission.';
                } finally {
                    this.applying = false;
                }
            },
        });
    })();
</script>
