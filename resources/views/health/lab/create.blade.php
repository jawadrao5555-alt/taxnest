<x-health-layout>
    <section class="mx-auto max-w-4xl space-y-5 px-4 py-6 sm:px-6">
        <a href="{{ route('health.lab') }}" class="text-sm font-semibold text-teal-700 underline dark:text-teal-300">{{ __('health.nav_lab') }}</a>
        <h1 class="text-2xl font-bold">{{ __('lab_workflow.new_order') }}</h1>

        @if($visit)
            <p><a class="font-semibold text-teal-700 underline dark:text-teal-300" href="{{ route('health.clinical.visit', $visit) }}">
                {{ __('lab_workflow.visit', ['number' => $visit->visit_no]) }}
            </a></p>
        @else
            <form method="GET" action="{{ route('health.lab.orders.create') }}" class="flex flex-wrap items-end gap-3">
                <label class="grow text-sm font-medium">
                    {{ __('lab_workflow.patient_search') }}
                    <input name="q" value="{{ $search }}" maxlength="80" class="mt-1 block w-full rounded-lg border-gray-300 bg-white dark:border-gray-600 dark:bg-gray-800">
                </label>
                <button class="rounded-lg border border-gray-300 px-4 py-2 font-semibold dark:border-gray-600">{{ __('lab_workflow.search') }}</button>
                @if(in_array('patients.manage', $healthCapabilities ?? [], true))
                    <a href="{{ route('health.patients.create') }}" class="rounded-lg border border-gray-300 px-4 py-2 font-semibold dark:border-gray-600">{{ __('health.patient_register') }}</a>
                @endif
            </form>
        @endif

        @if($patients->isEmpty() || $tests->isEmpty())
            <p>{{ $patients->isEmpty() ? __('lab_workflow.no_patients') : __('lab_workflow.no_active_tests') }}</p>
        @else
            <form method="POST" action="{{ route('health.lab.orders.store') }}" class="space-y-4 rounded-xl border border-gray-300 p-4 dark:border-gray-700">
                @csrf
                <input type="hidden" name="request_key" value="{{ old('request_key', $requestKey) }}">
                @if($visit)
                    <input type="hidden" name="visit_id" value="{{ $visit->id }}">
                    <input type="hidden" name="patient_id" value="{{ $visit->health_patient_id }}">
                    <p>{{ $visit->patient->name }} · <bdi>{{ $visit->patient->mrn }}</bdi></p>
                @else
                    <label class="block text-sm font-medium">
                        {{ __('lab_workflow.patient') }}
                        <select name="patient_id" class="mt-1 block w-full rounded-lg border-gray-300 bg-white dark:border-gray-600 dark:bg-gray-800" required>
                            <option value="">{{ __('lab_workflow.choose') }}</option>
                            @foreach($patients as $patient)
                                <option value="{{ $patient->id }}" @selected(old('patient_id') == $patient->id)>{{ $patient->mrn }} · {{ $patient->name }}</option>
                            @endforeach
                        </select>
                    </label>
                    @if($branches->isNotEmpty())
                        <label class="block text-sm font-medium">
                            {{ __('lab_workflow.branch') }}
                            <select name="branch_id" class="mt-1 block w-full rounded-lg border-gray-300 bg-white dark:border-gray-600 dark:bg-gray-800" required>
                                <option value="">{{ __('lab_workflow.choose') }}</option>
                                @foreach($branches as $branch)
                                    <option value="{{ $branch->id }}" @selected(old('branch_id', $defaultBranchId) == $branch->id)>{{ $branch->name }}</option>
                                @endforeach
                            </select>
                        </label>
                    @endif
                    @if($departments->isNotEmpty())
                        <label class="block text-sm font-medium">
                            {{ __('lab_workflow.department') }}
                            <select name="department_id" class="mt-1 block w-full rounded-lg border-gray-300 bg-white dark:border-gray-600 dark:bg-gray-800">
                                <option value="">{{ __('lab_workflow.none') }}</option>
                                @foreach($departments as $department)
                                    <option value="{{ $department->id }}" @selected(old('department_id') == $department->id)>{{ $department->name }}</option>
                                @endforeach
                            </select>
                        </label>
                    @endif
                @endif
                <fieldset>
                    <legend class="font-semibold">{{ __('lab_workflow.select_tests') }}</legend>
                    <ul class="mt-2 grid gap-2 sm:grid-cols-2">
                        @foreach($tests as $test)
                            <li class="rounded-lg border border-gray-300 p-3 dark:border-gray-600">
                                <label class="flex items-center gap-2">
                                    <input type="checkbox" name="test_ids[]" value="{{ $test->id }}" @checked(in_array($test->id, (array) old('test_ids', [])))>
                                    <span><bdi>{{ $test->code }}</bdi> · {{ $test->name }} <bdi dir="ltr">PKR {{ number_format($test->price_paisa / 100, 2) }}</bdi></span>
                                </label>
                            </li>
                        @endforeach
                    </ul>
                </fieldset>
                <button class="rounded-lg bg-teal-700 px-4 py-2 font-semibold text-white">{{ __('lab_workflow.save_order') }}</button>
            </form>
        @endif
    </section>
</x-health-layout>
