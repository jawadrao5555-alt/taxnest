<x-school-erp-layout :title="__('school_family.title')">
    <h1 class="school-title">{{ __('school_family.title') }}</h1>
    <section class="school-card">
        <h2>{{ __('school_erp.students') }}</h2>
        @if($students->isEmpty())
            <p>{{ __('school_family.no_access') }}</p>
        @else
            <ul class="school-list">
                @foreach($students as $student)
                    <li>
                        <a href="{{ route('school-erp.family.student', $student->id) }}">
                            {{ $student->display_name }} · {{ $student->student_reference }}
                        </a>
                    </li>
                @endforeach
            </ul>
        @endif
    </section>
    @if($notices->isNotEmpty() || $notices->currentPage() > 1)
        <section class="school-card">
            <h2>{{ __('school_announcement.title') }}</h2>
            @foreach($notices as $notice)
                <article class="school-card">
                    @include('school-erp.announcement-content')
                </article>
            @endforeach
            {{ $notices->links() }}
        </section>
    @endif
</x-school-erp-layout>
