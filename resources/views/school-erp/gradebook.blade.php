<x-school-erp-layout :title="__('school_erp.gradebook')">
    <h1 class="school-title">{{ __('school_erp.gradebook') }}</h1>
    @if($canCreate)
        <section class="school-card">
            <h2>{{ __('school_erp.add_class') }}</h2>
            @if($campuses->isEmpty() || $terms->isEmpty())
                <p>{{ __('school_erp.class_setup') }} <a href="{{ route('school-erp.dashboard') }}">{{ __('school_erp.workspace') }}</a></p>
            @else
                <form class="school-form" method="POST" action="{{ route('school-erp.gradebook.classes.store') }}">
                    @csrf
                    <label>
                        {{ __('school_erp.class_name') }}
                        <input name="name" value="{{ old('name') }}" maxlength="120" required>
                    </label>
                    <label>
                        {{ __('school_erp.class_code') }}
                        <input name="code" value="{{ old('code') }}" maxlength="32" required>
                    </label>
                    <label>
                        {{ __('school_erp.campus') }}
                        <select name="campus_id" required>
                            @foreach($campuses as $campus)
                                <option value="{{ $campus->id }}" @selected((int) old('campus_id') === (int) $campus->id)>{{ $campus->name }}</option>
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
                    <button class="school-button" type="submit">{{ __('school_erp.add_class') }}</button>
                </form>
            @endif
        </section>
    @endif

    <section class="school-card">
        <h2>{{ __('school_erp.classes') }}</h2>
        <div class="school-scroll">
            <table>
                <thead>
                    <tr>
                        <th scope="col">{{ __('school_erp.class_name') }}</th>
                        <th scope="col">{{ __('school_erp.campus') }}</th>
                        <th scope="col">{{ __('school_erp.term_name') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($list as $class)
                        <tr>
                            <td><a href="{{ route('school-erp.gradebook', ['class_id' => $class->id]) }}">{{ $class->name }}</a> ({{ $class->code }})</td>
                            <td>{{ $class->campus_name }}</td>
                            <td>{{ $class->term_name }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="3">{{ __('school_erp.no_classes') }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        {{ $list->links() }}
    </section>
</x-school-erp-layout>
