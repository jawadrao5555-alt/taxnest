<x-school-erp-layout :title="$student->display_name">
    <h1 class="school-title">{{ $student->display_name }}</h1>
    <p>{{ $student->student_reference }}</p>
    <a href="{{ route('school-erp.family') }}">{{ __('school_family.title') }}</a>

    <section class="school-card">
        <h2>{{ __('school_erp.attendance') }}</h2>
        @if($attendance->isEmpty())
            <p>{{ __('school_family.no_attendance') }}</p>
        @else
            <div class="school-scroll">
                <table>
                    <thead><tr><th scope="col">{{ __('school_erp.attendance_on') }}</th><th scope="col">{{ __('school_erp.attendance_status') }}</th></tr></thead>
                    <tbody>
                        @foreach($attendance as $record)
                            <tr><td>{{ $record->attendance_on }}</td><td>{{ __('school_erp.attendance_'.$record->status) }}</td></tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
        {{ $attendance->links() }}
    </section>
    <section class="school-card">
        <h2>{{ __('school_family.grades') }}</h2>
        @if($grades->isEmpty())
            <p>{{ __('school_family.no_grades') }}</p>
        @else
            <div class="school-scroll">
                <table>
                    <thead><tr>
                        <th scope="col">{{ __('school_erp.assessment') }}</th>
                        <th scope="col">{{ __('school_family.class') }}</th>
                        <th scope="col">{{ __('school_family.date') }}</th>
                        <th scope="col">{{ __('school_erp.score') }}</th>
                    </tr></thead>
                    <tbody>
                        @foreach($grades as $grade)
                            <tr>
                                <td>{{ $grade->title }}</td>
                                <td>{{ $grade->class_name }}</td>
                                <td>{{ $grade->assessed_on }}</td>
                                <td>{{ number_format($grade->score_hundredths / 100, 2) }} / {{ number_format($grade->max_hundredths / 100, 2) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
        {{ $grades->links() }}
    </section>
</x-school-erp-layout>
