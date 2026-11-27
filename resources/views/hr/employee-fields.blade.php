<label>Full name <input name="full_name" value="{{ $employee?->full_name }}" required maxlength="200"></label>
<label>Father's name <input name="father_name" value="{{ $employee?->father_name }}" maxlength="200"></label>
<label>Designation <input name="designation" value="{{ $employee?->designation }}" maxlength="150"></label>
<label>Work email <input name="email" type="email" value="{{ $employee?->email }}" maxlength="255"></label>
<label>Contact number <input name="phone" type="tel" value="{{ $employee?->phone }}" maxlength="40"></label>
<label>Linked workspace account
    <select name="user_id">
        <option value="">No linked account</option>
        @if ($employee?->user_id && !$members->contains('user_id', $employee->user_id))
            <option value="{{ $employee->user_id }}" selected>Existing account (inactive)</option>
        @endif
        @foreach ($members as $member)
            <option value="{{ $member->user_id }}" @selected((int) $employee?->user_id === (int) $member->user_id)>{{ $member->user->name }} ({{ $member->user->email }})</option>
        @endforeach
    </select>
</label>
