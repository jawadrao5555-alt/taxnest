@php
    use App\Models\HealthPrescriptionItem;
    use App\Support\PosLocale;

    $locale = PosLocale::normalize(auth()->guard(\App\Support\HealthPanel::GUARD)->user()->language ?? app()->getLocale());
    $rtl = $locale === 'ur';
    $patient = $prescription->patient;
    $doctor = $prescription->doctor;
@endphp
<!DOCTYPE html>
<html lang="{{ $locale }}" dir="{{ $rtl ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $prescription->prescription_no }}</title>
    {{-- A standalone <html> template inherits nothing from the panel layout, so
         the print styles live here rather than in a stylesheet the print view
         would never load. --}}
    <style>
        * { box-sizing: border-box; }
        body {
            font-family: 'Segoe UI', system-ui, -apple-system, sans-serif;
            margin: 0; padding: 24px; color: #111827; background: #f3f4f6;
            font-size: 13px; line-height: 1.5;
        }
        .sheet { max-width: 760px; margin: 0 auto; background: #fff; padding: 28px 32px; border-radius: 8px; }
        .head { display: flex; justify-content: space-between; gap: 16px; border-bottom: 3px solid #0f766e; padding-bottom: 14px; }
        .org { font-size: 20px; font-weight: 800; color: #0f766e; margin: 0; }
        .muted { color: #6b7280; font-size: 12px; }
        .grid { display: flex; flex-wrap: wrap; gap: 18px; margin: 16px 0; }
        .grid > div { min-width: 150px; }
        .lbl { font-size: 10px; text-transform: uppercase; letter-spacing: .04em; color: #9ca3af; font-weight: 700; }
        .val { font-weight: 700; }
        .rx { font-size: 34px; font-weight: 800; color: #0f766e; line-height: 1; margin: 18px 0 8px; }
        table { width: 100%; border-collapse: collapse; margin-top: 6px; }
        th { text-align: {{ $rtl ? 'right' : 'left' }}; font-size: 10px; text-transform: uppercase; letter-spacing: .04em;
             color: #6b7280; border-bottom: 2px solid #e5e7eb; padding: 7px 6px; }
        td { padding: 9px 6px; border-bottom: 1px solid #f3f4f6; vertical-align: top; }
        .med { font-weight: 800; }
        .note { margin-top: 18px; padding: 12px 14px; background: #f0fdfa; border-inline-start: 4px solid #0f766e; border-radius: 4px; }
        .sign { margin-top: 40px; display: flex; justify-content: space-between; align-items: flex-end; }
        .sign .line { border-top: 1px solid #9ca3af; padding-top: 6px; min-width: 200px; text-align: center; font-size: 11px; }
        .foot { margin-top: 22px; font-size: 10px; color: #9ca3af; text-align: center; }
        .actions { max-width: 760px; margin: 0 auto 14px; text-align: {{ $rtl ? 'left' : 'right' }}; }
        .btn { background: #0f766e; color: #fff; border: 0; padding: 9px 18px; border-radius: 8px; font-weight: 700; cursor: pointer; font-size: 13px; }
        @media print {
            body { background: #fff; padding: 0; }
            .sheet { max-width: none; border-radius: 0; padding: 0; }
            .actions { display: none; }
        }
    </style>
</head>
<body>
    <div class="actions">
        <button type="button" class="btn" onclick="window.print()">{{ __('health.presc_print') }}</button>
    </div>

    <div class="sheet">
        <div class="head">
            <div>
                <p class="org">{{ $company->name ?? '' }}</p>
                <p class="muted">
                    {{ $prescription->branch?->name ?? '' }}
                    @if($prescription->branch?->address) &middot; {{ $prescription->branch->address }} @endif
                    @if($prescription->branch?->phone) &middot; {{ $prescription->branch->phone }} @endif
                </p>
            </div>
            <div style="text-align: {{ $rtl ? 'left' : 'right' }}">
                <p class="val" style="font-family: monospace">{{ $prescription->prescription_no }}</p>
                <p class="muted">{{ ($prescription->issued_at ?? $prescription->created_at)?->format('d M Y, h:i A') }}</p>
            </div>
        </div>

        <div class="grid">
            <div>
                <p class="lbl">{{ __('health.patient') }}</p>
                <p class="val">{{ $patient?->name }}</p>
                <p class="muted" style="font-family: monospace">{{ $patient?->mrn }}</p>
            </div>
            <div>
                <p class="lbl">{{ __('health.patient_age_years') }} / {{ __('health.patient_gender') }}</p>
                <p class="val">
                    {{ $patient?->age_label ?: '—' }}
                    @if($patient?->gender) &middot; {{ __('health.gender_' . $patient->gender) }} @endif
                </p>
            </div>
            <div>
                <p class="lbl">{{ __('health.doctor') }}</p>
                <p class="val">{{ $doctor?->name }}</p>
                <p class="muted">{{ $doctor?->qualification }}@if($doctor?->registration_no) &middot; {{ $doctor->registration_no }} @endif</p>
            </div>
            @if($prescription->visit)
                <div>
                    <p class="lbl">{{ __('health.visit') }}</p>
                    <p class="val" style="font-family: monospace">{{ $prescription->visit->visit_no }}</p>
                </div>
            @endif
        </div>

        @if($patient?->allergies)
            <div class="note" style="background:#fef2f2; border-inline-start-color:#dc2626">
                <span class="lbl" style="color:#b91c1c">{{ __('health.patient_allergies') }}</span>
                <div>{{ $patient->allergies }}</div>
            </div>
        @endif

        @if($prescription->visit?->diagnosis)
            <div style="margin-top:14px">
                <p class="lbl">{{ __('health.visit_diagnosis') }}</p>
                <p class="val">{{ $prescription->visit->diagnosis }}</p>
            </div>
        @endif

        <p class="rx">&#8478;</p>

        <table>
            <thead>
                <tr>
                    <th style="width:26px">#</th>
                    <th>{{ __('health.presc_medicine') }}</th>
                    <th>{{ __('health.presc_dose') }}</th>
                    <th>{{ __('health.presc_route') }}</th>
                    <th>{{ __('health.presc_frequency') }}</th>
                    <th>{{ __('health.presc_duration') }}</th>
                    <th>{{ __('health.presc_quantity') }}</th>
                </tr>
            </thead>
            <tbody>
                @foreach($prescription->items as $item)
                    <tr>
                        <td>{{ $item->line_no }}</td>
                        <td>
                            <span class="med">{{ $item->medicine_name }}</span>
                            @if($item->strength) {{ $item->strength }} @endif
                            @if($item->form)
                                <span class="muted">({{ __(HealthPrescriptionItem::formLabelKey($item->form)) }})</span>
                            @endif
                            @if($item->generic_name)
                                <div class="muted">{{ $item->generic_name }}</div>
                            @endif
                            @if($item->instructions)
                                <div class="muted">{{ $item->instructions }}</div>
                            @endif
                        </td>
                        <td>{{ $item->dose ?: '—' }}</td>
                        <td>{{ $item->route ? __(HealthPrescriptionItem::routeLabelKey($item->route)) : '—' }}</td>
                        <td>{{ $item->frequency ?: '—' }}</td>
                        <td>{{ $item->duration_days ? __('health.presc_days', ['days' => $item->duration_days]) : '—' }}</td>
                        <td>{{ $item->quantity !== null ? rtrim(rtrim(number_format((float) $item->quantity, 2), '0'), '.') : '—' }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>

        @if($prescription->general_instructions)
            <div class="note">
                <span class="lbl">{{ __('health.presc_instructions') }}</span>
                <div>{{ $prescription->general_instructions }}</div>
            </div>
        @endif

        @if($prescription->visit?->follow_up_date)
            <div class="note">
                <span class="lbl">{{ __('health.visit_follow_up') }}</span>
                <div>{{ \Illuminate\Support\Carbon::parse($prescription->visit->follow_up_date)->format('d M Y') }}
                    @if($prescription->visit->follow_up_notes) &middot; {{ $prescription->visit->follow_up_notes }} @endif
                </div>
            </div>
        @endif

        <div class="sign">
            <div class="muted">
                @if($prescription->valid_until)
                    {{ __('health.presc_valid_until') }}: {{ \Illuminate\Support\Carbon::parse($prescription->valid_until)->format('d M Y') }}
                @endif
            </div>
            <div class="line">{{ $doctor?->name }}</div>
        </div>

        <p class="foot">{{ __('health.presc_print_footer') }}</p>
    </div>
</body>
</html>
