<label for="amount">{{ __('hospital.amount') }} (PKR)</label><input id="amount" name="amount" type="number" min="0.01" max="99999999.99" step="0.01" required>
<label for="payment_method">{{ __('hospital.method') }}</label><select id="payment_method" name="payment_method">@foreach(['cash','card','bank'] as $method)<option value="{{ $method }}">{{ __('hospital.'.$method) }}</option>@endforeach</select>
<label class="cash-confirm"><input type="checkbox" name="payment_confirmed" value="1" required><span>{{ __('hospital.confirm') }}</span></label>
@if($closed)<p class="notice">{{ __('hospital.closed') }}</p>@endif
<p id="hospital-recovery" class="notice" role="alert" hidden></p><button type="submit" @disabled($closed)>{{ __('hospital.collect') }}</button>
