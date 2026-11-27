@foreach (\App\Models\HrPayrollLine::INPUTS as $field => $label)
    <label>{{ $label }}
        <input type="number" name="{{ $field }}" required min="0" max="999999999.99" step="0.01"
            value="{{ !$line || old('employee_id') == $line->employee_id
                ? old($field, \App\Models\HrPayrollLine::amount($line?->{$field.'_minor'} ?? 0))
                : \App\Models\HrPayrollLine::amount($line->{$field.'_minor'}) }}">
    </label>
@endforeach
