<x-school-erp-layout :title="__('school_erp.workspace')">
    <h1 class="school-title">{{ $company->name }}</h1>
    <p>{{ __('school_erp.workspace') }}</p>

    @unless($canWrite)
        <p class="school-note">{{ __('school_erp.read_only') }}</p>
    @endunless

    @if($campuses->isNotEmpty())
        <form class="school-form school-card" method="GET" action="{{ route('school-erp.dashboard') }}">
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
    @endif

    @if($canWrite && $canManage)
        <div class="school-grid mt-4">
            <section class="school-card">
                <h2>{{ __('school_erp.add_campus') }}</h2>
                <form class="school-form" method="POST" action="{{ route('school-erp.campuses.store') }}">
                    @csrf
                    <label>
                        {{ __('school_erp.campus_name') }}
                        <input name="campus_name" value="{{ old('campus_name') }}" required maxlength="120">
                    </label>
                    <button class="school-button" type="submit">{{ __('school_erp.add_campus') }}</button>
                </form>
            </section>
            <section class="school-card">
                <h2>{{ __('school_erp.add_term') }}</h2>
                <form class="school-form" method="POST" action="{{ route('school-erp.terms.store') }}">
                    @csrf
                    <label>
                        {{ __('school_erp.term_code') }}
                        <input name="code" value="{{ old('code') }}" required maxlength="32">
                    </label>
                    <label>
                        {{ __('school_erp.term_name') }}
                        <input name="name" value="{{ old('name') }}" required maxlength="120">
                    </label>
                    <label>
                        {{ __('school_erp.starts_on') }}
                        <input name="starts_on" type="date" value="{{ old('starts_on') }}" required>
                    </label>
                    <label>
                        {{ __('school_erp.ends_on') }}
                        <input name="ends_on" type="date" value="{{ old('ends_on') }}" required>
                    </label>
                    <button class="school-button" type="submit">{{ __('school_erp.add_term') }}</button>
                </form>
            </section>
            @if($writeCampuses->isNotEmpty())
                <section class="school-card">
                    <h2>{{ __('school_erp.add_staff') }}</h2>
                    <form class="school-form" method="POST" action="{{ route('school-erp.staff.store') }}">
                        @csrf
                        <label>
                            {{ __('school_erp.name') }}
                            <input name="staff_name" value="{{ old('staff_name') }}" required maxlength="120">
                        </label>
                        <label>
                            {{ __('school_erp.email') }}
                            <input name="staff_email" type="email" value="{{ old('staff_email') }}" required autocomplete="off">
                        </label>
                        <label>
                            {{ __('school_erp.campus') }}
                            <select name="staff_campus_id" required>
                                @foreach($writeCampuses as $campus)
                                    <option value="{{ $campus->id }}" @selected((int) old('staff_campus_id') === (int) $campus->id)>{{ $campus->name }}</option>
                                @endforeach
                            </select>
                        </label>
                        <label>
                            {{ __('school_erp.access') }}
                            <select name="staff_access" required>
                                @foreach(['full', 'read_only'] as $level)
                                    <option value="{{ $level }}" @selected(old('staff_access') === $level)>{{ __('school_erp.access_'.$level) }}</option>
                                @endforeach
                            </select>
                        </label>
                        <label>
                            {{ __('school_erp.password') }}
                            <input id="school-staff-password" name="password" type="password" required autocomplete="new-password">
                        </label>
                        <label>
                            {{ __('school_erp.confirm_password') }}
                            <input id="school-staff-password-confirmation" name="password_confirmation" type="password" required autocomplete="new-password">
                        </label>
                        <button class="school-button" type="submit">{{ __('school_erp.add_staff') }}</button>
                    </form>
                </section>
            @endif
        </div>
    @endif

    <section class="school-card">
        <h2>{{ __('school_erp.terms') }}</h2>
        <ul class="school-list">
            @forelse($terms as $term)
                <li>{{ $term->name }} ({{ $term->code }}) · {{ $term->starts_on }} – {{ $term->ends_on }}</li>
            @empty
                <li>{{ __('school_erp.no_terms') }}</li>
            @endforelse
        </ul>
    </section>

    @if($canAdmit)
        <div class="school-grid">
            <section class="school-card">
                <h2>{{ __('school_erp.add_student') }}</h2>
                <form class="school-form" method="POST" action="{{ route('school-erp.students.store') }}">
                    @csrf
                    <label>
                        {{ __('school_erp.student_reference') }}
                        <input name="student_reference" value="{{ old('student_reference') }}" required maxlength="64">
                    </label>
                    <label>
                        {{ __('school_erp.student_name') }}
                        <input name="display_name" value="{{ old('display_name') }}" required maxlength="160">
                    </label>
                    <label>
                        {{ __('school_erp.campus') }}
                        <select name="campus_id" @required(!$canManage)>
                            @if($canManage)
                                <option value="">{{ __('school_erp.unassigned') }}</option>
                            @endif
                            @foreach($writeCampuses as $campus)
                                <option value="{{ $campus->id }}" @selected((int) old('campus_id', $campusId) === (int) $campus->id)>{{ $campus->name }}</option>
                            @endforeach
                        </select>
                    </label>
                    <button class="school-button" type="submit">{{ __('school_erp.add_student') }}</button>
                </form>
            </section>
            @php($admissionStudents = $students->filter(fn ($student) => $canManage || $writeCampuses->contains(fn ($campus) => (int) $campus->id === (int) $student->campus_id)))
            @if($terms->isNotEmpty() && $admissionStudents->isNotEmpty())
                <section class="school-card">
                    <h2>{{ __('school_erp.record_admission') }}</h2>
                    <form class="school-form" method="POST" action="{{ route('school-erp.admissions.store') }}">
                        @csrf
                        <label>
                            {{ __('school_erp.student_name') }}
                            <select name="student_id" required>
                                @foreach($admissionStudents as $student)
                                    <option value="{{ $student->id }}" @selected((int) old('student_id') === (int) $student->id)>{{ $student->display_name }} · {{ $student->student_reference }}</option>
                                @endforeach
                            </select>
                        </label>
                        <label>
                            {{ __('school_erp.term_name') }}
                            <select name="term_id" required>
                                @foreach($terms as $term)
                                    <option value="{{ $term->id }}" @selected((int) old('term_id') === (int) $term->id)>{{ $term->name }}</option>
                                @endforeach
                            </select>
                        </label>
                        <label>
                            {{ __('school_erp.admitted_on') }}
                            <input name="admitted_on" type="date" value="{{ old('admitted_on', now()->toDateString()) }}" required>
                        </label>
                        <button class="school-button" type="submit">{{ __('school_erp.record_admission') }}</button>
                    </form>
                </section>
            @endif
        </div>
    @endif

    <section class="school-card">
        <h2>{{ __('school_erp.students') }}</h2>
        <div class="school-scroll">
            <table>
                <thead>
                    <tr>
                        <th scope="col">{{ __('school_erp.student_reference') }}</th>
                        <th scope="col">{{ __('school_erp.student_name') }}</th>
                        <th scope="col">{{ __('school_erp.campus') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($students as $student)
                        <tr>
                            <td>{{ $student->student_reference }}</td>
                            <td>{{ $student->display_name }}</td>
                            <td>
                                {{ $student->campus_name ?? __('school_erp.unassigned') }}
                                @if($canWrite && $canManage && $writeCampuses->contains(fn ($campus) => (int) $campus->id !== (int) $student->campus_id))
                                    <details>
                                        <summary>{{ __('school_erp.transfer') }}</summary>
                                        <form class="school-form" method="POST" action="{{ route('school-erp.students.transfer', $student->id) }}">
                                            @csrf
                                            <label>
                                                {{ __('school_erp.target_campus') }}
                                                <select name="campus_id" required>
                                                    @foreach($writeCampuses as $campus)
                                                        @if((int) $campus->id !== (int) $student->campus_id)
                                                            <option value="{{ $campus->id }}">{{ $campus->name }}</option>
                                                        @endif
                                                    @endforeach
                                                </select>
                                            </label>
                                            <button class="school-button" type="submit">{{ __('school_erp.transfer') }}</button>
                                        </form>
                                    </details>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="3">{{ __('school_erp.no_students') }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        {{ $students->links() }}
    </section>

    <section class="school-card">
        <h2>{{ __('school_erp.admissions') }}</h2>
        <div class="school-scroll">
            <table>
                <thead>
                    <tr>
                        <th scope="col">{{ __('school_erp.student_reference') }}</th>
                        <th scope="col">{{ __('school_erp.term_name') }}</th>
                        <th scope="col">{{ __('school_erp.campus') }}</th>
                        <th scope="col">{{ __('school_erp.admitted_on') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($enrollments as $enrollment)
                        <tr>
                            <td>{{ $enrollment->student_reference }}</td>
                            <td>{{ $enrollment->term_name }}</td>
                            <td>{{ $enrollment->campus_name ?? __('school_erp.unassigned') }}</td>
                            <td>{{ $enrollment->admitted_on }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="4">{{ __('school_erp.no_admissions') }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        {{ $enrollments->links() }}
    </section>
</x-school-erp-layout>
