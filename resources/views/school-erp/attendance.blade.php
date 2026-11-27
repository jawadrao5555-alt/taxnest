<x-school-erp-layout :title="__('school_erp.attendance')">
    <h1 class="school-title">{{ __('school_erp.attendance') }}</h1>

    <form class="school-form school-card" method="GET" action="{{ route('school-erp.attendance') }}">
        <label>
            {{ __('school_erp.attendance_on') }}
            <input type="date" name="attendance_on" value="{{ $date }}" max="{{ now()->toDateString() }}" required>
        </label>
        <label>
            {{ __('school_erp.campus') }}
            <select name="campus_id">
                <option value="">{{ __('school_erp.all_campuses') }}</option>
                @foreach($campuses as $campus)
                    <option value="{{ $campus->id }}" @selected($campusId === (int) $campus->id)>{{ $campus->name }}</option>
                @endforeach
            </select>
        </label>
        <button class="school-button" type="submit">{{ __('school_erp.apply_filter') }}</button>
    </form>

    @if($eligible !== null)
        <section class="school-card">
            <h2>{{ __('school_erp.record_attendance') }}</h2>
            <div class="school-grid">
                @forelse($eligible as $student)
                    <form class="school-card school-form" method="POST" action="{{ route('school-erp.attendance.store') }}">
                        @csrf
                        <input name="student_id" type="hidden" value="{{ $student->student_id }}">
                        <input name="term_id" type="hidden" value="{{ $student->term_id }}">
                        <input name="attendance_on" type="hidden" value="{{ $date }}">
                        <div>
                            <strong>{{ $student->display_name }}</strong>
                            <div>{{ $student->student_reference }} · {{ $student->term_name }}</div>
                        </div>
                        <label>
                            {{ __('school_erp.attendance_status') }}
                            <select name="status" required>
                                <option value="">{{ __('school_erp.attendance_select') }}</option>
                                @foreach($statuses as $status)
                                    <option value="{{ $status }}" @selected($student->status === $status)>{{ __('school_erp.attendance_'.$status) }}</option>
                                @endforeach
                            </select>
                        </label>
                        <button class="school-button" type="submit">{{ __('school_erp.attendance_save') }}</button>
                    </form>
                @empty
                    <p>{{ __('school_erp.no_eligible_attendance') }}</p>
                @endforelse
            </div>
            {{ $eligible->links() }}
        </section>
    @endif

    <section class="school-card">
        <h2>{{ __('school_erp.attendance_records') }}</h2>
        <div class="school-scroll">
            <table>
                <thead>
                    <tr>
                        <th scope="col">{{ __('school_erp.student_reference') }}</th>
                        <th scope="col">{{ __('school_erp.term_name') }}</th>
                        <th scope="col">{{ __('school_erp.campus') }}</th>
                        <th scope="col">{{ __('school_erp.attendance_status') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($attendance as $row)
                        <tr>
                            <td>{{ $row->student_reference }}</td>
                            <td>{{ $row->term_name }}</td>
                            <td>{{ $row->campus_name }}</td>
                            <td>{{ __('school_erp.attendance_'.$row->status) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="4">{{ __('school_erp.no_attendance') }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        {{ $attendance->links() }}
    </section>
</x-school-erp-layout>
