<x-school-erp-layout :title="$activeClass->name">
    <a href="{{ route('school-erp.gradebook') }}">{{ __('school_erp.classes') }}</a>
    <h1 class="school-title">{{ $activeClass->name }}</h1>
    <p>{{ $activeClass->campus_name }} · {{ $activeClass->term_name }}</p>
    @if($canManage)
        <div class="school-grid mt-4">
            <section class="school-card">
                <h2>{{ __('school_erp.add_to_class') }}</h2>
                @if($availableStudents->isEmpty())
                    <p>{{ __('school_erp.no_roster_candidates') }}</p>
                @else
                    <form class="school-form" method="POST" action="{{ route('school-erp.gradebook.students.store', $activeClass->id) }}">
                        @csrf
                        <label>
                            {{ __('school_erp.student_name') }}
                            <select name="student_id" required>
                                @foreach($availableStudents as $student)
                                    <option value="{{ $student->id }}">{{ $student->display_name }} · {{ $student->student_reference }}</option>
                                @endforeach
                            </select>
                        </label>
                        <button class="school-button" type="submit">{{ __('school_erp.add_to_class') }}</button>
                    </form>
                    {{ $availableStudents->links() }}
                @endif
            </section>
            <section class="school-card">
                <h2>{{ __('school_erp.add_assessment') }}</h2>
                <form class="school-form" method="POST" action="{{ route('school-erp.gradebook.assessments.store', $activeClass->id) }}">
                    @csrf
                    <label>
                        {{ __('school_erp.assessment_code') }}
                        <input name="code" value="{{ old('code') }}" required maxlength="32">
                    </label>
                    <label>
                        {{ __('school_erp.assessment_title') }}
                        <input name="title" value="{{ old('title') }}" required maxlength="120">
                    </label>
                    <label>
                        {{ __('school_erp.assessed_on') }}
                        <input type="date" name="assessed_on" value="{{ old('assessed_on') }}" required>
                    </label>
                    <label>
                        {{ __('school_erp.max_points') }}
                        <input name="max_points" type="number" min="0.01" max="99999.99" step="0.01" value="{{ old('max_points') }}" required>
                    </label>
                    <button class="school-button" type="submit">{{ __('school_erp.add_assessment') }}</button>
                </form>
            </section>
        </div>
    @endif

    @if($assessment)
        <form class="school-card school-form" method="GET" action="{{ route('school-erp.gradebook') }}">
            <input type="hidden" name="class_id" value="{{ $activeClass->id }}">
            <label>
                {{ __('school_erp.assessment') }}
                <select name="assessment_id" required>
                    @foreach($assessments as $choice)
                        <option value="{{ $choice->id }}" @selected((int) $choice->id === (int) $assessment->id)>
                            {{ $choice->title }} · {{ $choice->assessed_on }}
                        </option>
                    @endforeach
                </select>
            </label>
            <button class="school-button" type="submit">{{ __('school_erp.show_scores') }}</button>
        </form>
    @endif

    <section class="school-card">
        <h2>{{ __('school_erp.class_roster') }}</h2>
        @if($assessment)
            <p>{{ $assessment->title }} · {{ __('school_erp.max_points') }} {{ number_format($assessment->max_hundredths / 100, 2, '.', '') }}</p>
        @endif
        <div class="school-scroll">
            <table>
                <thead>
                    <tr>
                        <th scope="col">{{ __('school_erp.student_reference') }}</th>
                        <th scope="col">{{ __('school_erp.student_name') }}</th>
                        @if($assessment)
                            <th scope="col">{{ __('school_erp.score') }}</th>
                        @endif
                    </tr>
                </thead>
                <tbody>
                    @forelse($roster as $row)
                        @php
                            $currentCampus = (int) $row->campus_id === (int) $activeClass->campus_id;
                            $campusOnAssessmentDate = !$row->transferred_at || ($assessment && substr($row->transferred_at, 0, 10) <= $assessment->assessed_on);
                        @endphp
                        <tr>
                            <td>{{ $row->student_reference }}</td>
                            <td>{{ $currentCampus ? $row->display_name : __('school_erp.class_former_student') }}</td>
                            @if($assessment)
                                <td>
                                    @if($canGrade && $currentCampus && $campusOnAssessmentDate && $row->admitted_on <= $assessment->assessed_on)
                                        <form class="school-form" method="POST" action="{{ route('school-erp.gradebook.grades.store', $activeClass->id) }}">
                                            @csrf
                                            <input type="hidden" name="student_id" value="{{ $row->student_id }}">
                                            <input type="hidden" name="assessment_id" value="{{ $assessment->id }}">
                                            <label>
                                                {{ __('school_erp.score') }}
                                                <input name="score" type="number" min="0" step="0.01" max="{{ number_format($assessment->max_hundredths / 100, 2, '.', '') }}"
                                                    value="{{ $row->score_hundredths === null ? '' : number_format($row->score_hundredths / 100, 2, '.', '') }}" required>
                                            </label>
                                            <button class="school-button" type="submit">{{ __('school_erp.save_score') }}</button>
                                        </form>
                                    @else
                                        {{ $row->score_hundredths === null ? '—' : number_format($row->score_hundredths / 100, 2, '.', '') }}
                                    @endif
                                </td>
                            @endif
                        </tr>
                    @empty
                        <tr><td colspan="{{ $assessment ? 3 : 2 }}">{{ __('school_erp.no_class_students') }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        {{ $roster->links() }}
    </section>
</x-school-erp-layout>
