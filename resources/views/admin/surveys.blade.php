<x-admin-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between flex-wrap gap-3">
            <h2 class="font-bold text-xl text-gray-800 dark:text-gray-100 leading-tight">POS Surveys</h2>
            <button onclick="openCreateModal()" class="inline-flex items-center px-3 py-2 bg-emerald-600 text-white rounded-lg text-sm font-medium hover:bg-emerald-700 transition">
                <svg class="w-4 h-4 mr-1.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
                New Survey
            </button>
        </div>
    </x-slot>

    <div class="py-8">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            @if(session('success'))
                <div class="mb-4 p-4 bg-green-100 dark:bg-green-900/30 text-green-800 dark:text-green-300 rounded-lg">{{ session('success') }}</div>
            @endif
            @if(session('error'))
                <div class="mb-4 p-4 bg-red-100 dark:bg-red-900/30 text-red-800 dark:text-red-300 rounded-lg">{{ session('error') }}</div>
            @endif

            <div class="mb-5 flex items-center justify-between gap-3 flex-wrap">
                <p class="text-sm text-gray-500 dark:text-gray-400">One-question-set advice surveys shown as a popup on the PRA POS panel (admins/managers only).</p>
                <form method="POST" action="{{ route('admin.surveys.feature-toggle') }}">
                    @csrf
                    <button type="submit" class="px-3.5 py-2 rounded-lg text-xs font-bold transition {{ $featureOn ? 'bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-300 hover:bg-green-200' : 'bg-red-100 text-red-700 dark:bg-red-900/30 dark:text-red-300 hover:bg-red-200' }}">
                        Popups: {{ $featureOn ? 'ON — click to disable' : 'OFF — click to enable' }}
                    </button>
                </form>
            </div>

            <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-100 dark:border-gray-700">
                <div class="overflow-x-auto">
                    <table class="w-full text-sm text-left table-cards">
                        <thead class="bg-gray-50 dark:bg-gray-900/50 text-gray-600 dark:text-gray-400 text-xs uppercase tracking-wider">
                            <tr>
                                <th class="px-4 py-3">Survey</th>
                                <th class="px-4 py-3">Audience</th>
                                <th class="px-4 py-3">Status</th>
                                <th class="px-4 py-3">Seen / Answered (users)</th>
                                <th class="px-4 py-3">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                            @forelse($surveys as $sv)
                                <tr class="hover:bg-gray-50 dark:hover:bg-gray-900/30 align-top">
                                    <td class="px-4 py-3">
                                        <a href="{{ route('admin.surveys.show', $sv->id) }}" class="font-medium text-emerald-600 dark:text-emerald-400 hover:underline">{{ $sv->title }}</a>
                                        @if($sv->intro)
                                            <div class="text-xs text-gray-400 mt-0.5 max-w-xs truncate">{{ $sv->intro }}</div>
                                        @endif
                                        <div class="text-xs text-gray-400 mt-0.5">{{ count($sv->questions) }} question{{ count($sv->questions) !== 1 ? 's' : '' }} · {{ $sv->created_at->format('d M Y') }}</div>
                                    </td>
                                    <td class="px-4 py-3">
                                        <span class="px-2 py-1 rounded-full text-xs font-medium {{ $sv->audience === 'pos_restaurant' ? 'bg-orange-100 text-orange-700 dark:bg-orange-900/30 dark:text-orange-300' : 'bg-blue-100 text-blue-700 dark:bg-blue-900/30 dark:text-blue-300' }}">
                                            {{ $sv->audience === 'pos_restaurant' ? 'Restaurant-mode only' : 'All PRA POS' }}
                                        </span>
                                    </td>
                                    <td class="px-4 py-3">
                                        @if(!$sv->is_published)
                                            <span class="px-2 py-1 rounded-full text-xs font-medium bg-gray-100 text-gray-600 dark:bg-gray-700 dark:text-gray-300">Draft</span>
                                        @elseif($sv->closed_at)
                                            <span class="px-2 py-1 rounded-full text-xs font-medium bg-red-100 text-red-700 dark:bg-red-900/30 dark:text-red-300">Closed {{ $sv->closed_at->format('d M Y') }}</span>
                                        @else
                                            <span class="px-2 py-1 rounded-full text-xs font-medium bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-300">Live</span>
                                        @endif
                                    </td>
                                    <td class="px-4 py-3 text-gray-600 dark:text-gray-300">{{ $sv->seen_count }} / <span class="font-bold">{{ $sv->answered_count }}</span></td>
                                    <td class="px-4 py-3">
                                        <div class="flex items-center gap-2 flex-wrap">
                                            <a href="{{ route('admin.surveys.show', $sv->id) }}" class="px-2.5 py-1.5 rounded-lg text-xs font-medium bg-emerald-600 text-white hover:bg-emerald-700">Results</a>

                                            {{-- Edit: always show, but warn if has responses + published --}}
                                            <button type="button"
                                                onclick='openEditModal(
                                                    {{ $sv->id }},
                                                    @json($sv->title),
                                                    @json($sv->intro ?? ""),
                                                    @json($sv->questions),
                                                    @json($sv->allow_comment),
                                                    @json($sv->audience),
                                                    @json($sv->is_published),
                                                    {{ $sv->seen_count > 0 ? 'true' : 'false' }}
                                                )'
                                                class="px-2.5 py-1.5 rounded-lg text-xs font-medium bg-gray-100 dark:bg-gray-700 text-gray-700 dark:text-gray-200 hover:bg-gray-200">Edit</button>

                                            @if($sv->is_published)
                                                <form method="POST" action="{{ route('admin.surveys.toggle-close', $sv->id) }}">
                                                    @csrf
                                                    <button type="submit" class="px-2.5 py-1.5 rounded-lg text-xs font-medium {{ $sv->closed_at ? 'bg-blue-50 text-blue-600 dark:bg-blue-900/20 dark:text-blue-400 hover:bg-blue-100' : 'bg-red-50 text-red-600 dark:bg-red-900/20 dark:text-red-400 hover:bg-red-100' }}">
                                                        {{ $sv->closed_at ? 'Reopen' : 'Close survey' }}
                                                    </button>
                                                </form>
                                            @endif

                                            {{-- Delete: only if no responses --}}
                                            @if($sv->seen_count == 0)
                                                <form method="POST" action="{{ route('admin.surveys.destroy', $sv->id) }}" onsubmit="return confirm({{ Js::from(__('pos.survey_admin_delete_confirm')) }});">
                                                    @csrf
                                                    @method('DELETE')
                                                    <button type="submit" class="px-2.5 py-1.5 rounded-lg text-xs font-medium bg-red-50 text-red-600 dark:bg-red-900/20 dark:text-red-400 hover:bg-red-100">Delete</button>
                                                </form>
                                            @endif
                                        </div>
                                    </td>
                                </tr>
                            @empty
                                <tr><td colspan="5" class="px-4 py-10 text-center text-gray-400">{{ __('pos.survey_admin_empty') }}</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                @if($surveys->hasPages())
                    <div class="px-4 py-3 border-t border-gray-100 dark:border-gray-700">{{ $surveys->links() }}</div>
                @endif
            </div>
        </div>
    </div>

    {{-- ===== CREATE MODAL ===== --}}
    <div id="createSurveyModal" class="hidden fixed inset-0 z-50 flex items-center justify-center p-4" style="background: rgba(0,0,0,0.5);">
        <div class="bg-white dark:bg-gray-800 rounded-xl shadow-2xl w-full max-w-2xl max-h-[92vh] overflow-y-auto">
            <div class="px-6 py-4 border-b border-gray-100 dark:border-gray-700 flex items-center justify-between sticky top-0 bg-white dark:bg-gray-800 z-10">
                <h3 class="font-bold text-gray-800 dark:text-gray-100">New Survey</h3>
                <button onclick="document.getElementById('createSurveyModal').classList.add('hidden')" class="text-gray-400 hover:text-gray-600 text-xl leading-none">&times;</button>
            </div>
            <form method="POST" action="{{ route('admin.surveys.store') }}" class="px-6 py-5 space-y-5" id="createSurveyForm">
                @csrf
                <input type="hidden" name="questions_json" id="createQuestionsJson">

                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Title <span class="text-red-500">*</span></label>
                    <input type="text" name="title" required maxlength="200" placeholder="{{ __('pos.survey_admin_title_placeholder') }}" class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100 text-sm">
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Intro / description <span class="text-gray-400 font-normal">{{ __('pos.survey_admin_intro_hint') }}</span></label>
                    <textarea name="intro" rows="2" maxlength="1000" placeholder="{{ __('pos.survey_admin_intro_placeholder') }}" class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100 text-sm"></textarea>
                </div>

                {{-- Questions builder --}}
                <div x-data="surveyBuilder()" x-init="init()">
                    <div class="flex items-center justify-between mb-2">
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Questions <span class="text-red-500">*</span></label>
                        <button type="button" @click="addQuestion()" class="text-xs font-medium text-emerald-600 dark:text-emerald-400 hover:underline">+ Add question</button>
                    </div>

                    <div class="space-y-4">
                        <template x-for="(q, qi) in questions" :key="q.key">
                            <div class="border border-gray-200 dark:border-gray-600 rounded-lg p-4 bg-gray-50 dark:bg-gray-900/40">
                                <div class="flex items-start gap-2 mb-3">
                                    <span class="mt-2 text-xs font-bold text-gray-400 w-6 shrink-0" x-text="'Q' + (qi+1)"></span>
                                    <input type="text" x-model="q.text" maxlength="300" placeholder="{{ __('pos.survey_admin_question_placeholder') }}" class="flex-1 rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-100 text-sm">
                                    <button type="button" @click="removeQuestion(qi)" x-show="questions.length > 1" class="mt-1.5 text-red-400 hover:text-red-600 text-lg leading-none" title="Remove question">&times;</button>
                                </div>

                                <div class="ml-8 space-y-2">
                                    <template x-for="(opt, oi) in q.options" :key="opt.key">
                                        <div class="flex items-center gap-2">
                                            <span class="w-4 h-4 rounded-full border-2 border-gray-300 dark:border-gray-500 shrink-0"></span>
                                            <input type="text" x-model="opt.label" maxlength="150" placeholder="Option..." class="flex-1 rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-100 text-sm py-1.5">
                                            <button type="button" @click="removeOption(qi, oi)" x-show="q.options.length > 2" class="text-red-400 hover:text-red-600 text-lg leading-none" title="Remove option">&times;</button>
                                        </div>
                                    </template>
                                    <button type="button" @click="addOption(qi)" class="text-xs text-gray-400 hover:text-emerald-600 mt-1">+ option</button>
                                </div>
                            </div>
                        </template>
                    </div>

                    {{-- Sync hidden input before submit --}}
                    <input type="hidden" :value="JSON.stringify(questions)" name="_qs_preview" x-ref="qsPreview">
                    <div class="mt-4 space-y-3 border-t border-gray-100 dark:border-gray-700 pt-4">
                        <label class="flex items-center gap-2 text-sm text-gray-700 dark:text-gray-300">
                            <input type="checkbox" x-model="allowComment" class="rounded border-gray-300 text-emerald-600">
                            Allow a free-text comment box (optional, end of survey)
                        </label>
                        <input type="hidden" name="allow_comment" :value="allowComment ? '1' : '0'">
                    </div>

                    {{-- Sync JSON on submit --}}
                    <script>
                        document.getElementById('createSurveyForm').addEventListener('submit', function () {
                            document.getElementById('createQuestionsJson').value = JSON.stringify(
                                window._createSurveyBuilderData ? window._createSurveyBuilderData() : []
                            );
                        });
                    </script>
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Audience</label>
                    <select name="audience" class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100 text-sm">
                        <option value="pos_all">All PRA POS (restaurants + retail)</option>
                        <option value="pos_restaurant">Restaurant-mode companies only</option>
                    </select>
                </div>

                <div class="flex items-center gap-2">
                    <input type="checkbox" name="is_published" value="1" id="createPublish" class="rounded border-gray-300 text-emerald-600">
                    <label for="createPublish" class="text-sm text-gray-700 dark:text-gray-300">Publish immediately (POS users will see popup)</label>
                </div>

                <div class="flex justify-end gap-2 pt-2">
                    <button type="button" onclick="document.getElementById('createSurveyModal').classList.add('hidden')" class="px-4 py-2 rounded-lg text-sm font-medium bg-gray-100 dark:bg-gray-700 text-gray-700 dark:text-gray-200">Cancel</button>
                    <button type="submit" class="px-4 py-2 rounded-lg text-sm font-semibold bg-emerald-600 text-white hover:bg-emerald-700">Create Survey</button>
                </div>
            </form>
        </div>
    </div>

    {{-- ===== EDIT MODAL ===== --}}
    <div id="editSurveyModal" class="hidden fixed inset-0 z-50 flex items-center justify-center p-4" style="background: rgba(0,0,0,0.5);">
        <div class="bg-white dark:bg-gray-800 rounded-xl shadow-2xl w-full max-w-2xl max-h-[92vh] overflow-y-auto">
            <div class="px-6 py-4 border-b border-gray-100 dark:border-gray-700 flex items-center justify-between sticky top-0 bg-white dark:bg-gray-800 z-10">
                <h3 class="font-bold text-gray-800 dark:text-gray-100">Edit Survey</h3>
                <button onclick="document.getElementById('editSurveyModal').classList.add('hidden')" class="text-gray-400 hover:text-gray-600 text-xl leading-none">&times;</button>
            </div>

            {{-- Warning banner when responses exist --}}
            <div id="editHasResponsesWarn" class="hidden mx-6 mt-4 p-3 bg-amber-50 dark:bg-amber-900/20 border border-amber-200 dark:border-amber-700 rounded-lg text-sm text-amber-800 dark:text-amber-300">
                {{ __('pos.survey_admin_responses_warn') }}
            </div>

            <form method="POST" id="editSurveyForm" action="" class="px-6 py-5 space-y-5">
                @csrf
                <input type="hidden" name="questions_json" id="editQuestionsJson">

                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Title <span class="text-red-500">*</span></label>
                    <input type="text" name="title" id="editTitle" required maxlength="200" class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100 text-sm">
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Intro / description <span class="text-gray-400 font-normal">(optional)</span></label>
                    <textarea name="intro" id="editIntro" rows="2" maxlength="1000" class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100 text-sm"></textarea>
                </div>

                {{-- Edit questions builder --}}
                <div id="editBuilderMount" x-data="editSurveyBuilder()" x-init="init()">
                    <div class="flex items-center justify-between mb-2">
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Questions <span class="text-red-500">*</span></label>
                        <button type="button" @click="addQuestion()" class="text-xs font-medium text-emerald-600 dark:text-emerald-400 hover:underline">+ Add question</button>
                    </div>

                    <div class="space-y-4">
                        <template x-for="(q, qi) in questions" :key="q.key">
                            <div class="border border-gray-200 dark:border-gray-600 rounded-lg p-4 bg-gray-50 dark:bg-gray-900/40">
                                <div class="flex items-start gap-2 mb-3">
                                    <span class="mt-2 text-xs font-bold text-gray-400 w-6 shrink-0" x-text="'Q' + (qi+1)"></span>
                                    <input type="text" x-model="q.text" maxlength="300" placeholder="{{ __('pos.survey_admin_question_placeholder') }}" class="flex-1 rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-100 text-sm">
                                    <button type="button" @click="removeQuestion(qi)" x-show="questions.length > 1" class="mt-1.5 text-red-400 hover:text-red-600 text-lg leading-none" title="Remove question">&times;</button>
                                </div>
                                <div class="ml-8 space-y-2">
                                    <template x-for="(opt, oi) in q.options" :key="opt.key">
                                        <div class="flex items-center gap-2">
                                            <span class="w-4 h-4 rounded-full border-2 border-gray-300 dark:border-gray-500 shrink-0"></span>
                                            <input type="text" x-model="opt.label" maxlength="150" placeholder="Option..." class="flex-1 rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-100 text-sm py-1.5">
                                            <button type="button" @click="removeOption(qi, oi)" x-show="q.options.length > 2" class="text-red-400 hover:text-red-600 text-lg leading-none" title="Remove option">&times;</button>
                                        </div>
                                    </template>
                                    <button type="button" @click="addOption(qi)" class="text-xs text-gray-400 hover:text-emerald-600 mt-1">+ option</button>
                                </div>
                            </div>
                        </template>
                    </div>

                    <div class="mt-4 space-y-3 border-t border-gray-100 dark:border-gray-700 pt-4">
                        <label class="flex items-center gap-2 text-sm text-gray-700 dark:text-gray-300">
                            <input type="checkbox" id="editAllowComment" x-model="allowComment" class="rounded border-gray-300 text-emerald-600">
                            Allow a free-text comment box (optional, end of survey)
                        </label>
                        <input type="hidden" name="allow_comment" :value="allowComment ? '1' : '0'">
                    </div>
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Audience</label>
                    <select name="audience" id="editAudience" class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100 text-sm">
                        <option value="pos_all">All PRA POS (restaurants + retail)</option>
                        <option value="pos_restaurant">Restaurant-mode companies only</option>
                    </select>
                </div>

                <div class="flex items-center gap-2">
                    <input type="checkbox" name="is_published" value="1" id="editPublish" class="rounded border-gray-300 text-emerald-600">
                    <label for="editPublish" class="text-sm text-gray-700 dark:text-gray-300">Published (POS users will see popup)</label>
                </div>

                <div class="flex justify-end gap-2 pt-2">
                    <button type="button" onclick="document.getElementById('editSurveyModal').classList.add('hidden')" class="px-4 py-2 rounded-lg text-sm font-medium bg-gray-100 dark:bg-gray-700 text-gray-700 dark:text-gray-200">Cancel</button>
                    <button type="submit" id="editSurveySubmitBtn" class="px-4 py-2 rounded-lg text-sm font-semibold bg-emerald-600 text-white hover:bg-emerald-700">Save Changes</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        // ── Shared helpers ──────────────────────────────────────────────────
        // Collision-free key generator — uses timestamp + random so reopening
        // the modal after a page load never collides with keys loaded from
        // existing survey data (which may have been created by the same fn).
        function makeKey(prefix) {
            return (prefix || 'k') + Date.now().toString(36) + Math.random().toString(36).slice(2, 7);
        }
        function makeQKey()   { return makeKey('q'); }
        function makeOptKey() { return makeKey('o'); }

        function blankQuestion() {
            return { key: makeQKey(), text: '', options: [{ key: makeOptKey(), label: '' }, { key: makeOptKey(), label: '' }] };
        }

        // ── Create-modal builder ─────────────────────────────────────────────
        function surveyBuilder() {
            return {
                questions: [],
                allowComment: false,
                init() {
                    this.questions = [blankQuestion()];
                    // Expose getter for hidden-input sync on form submit
                    window._createSurveyBuilderData = () => this.questions;
                    window._createSurveyAllowComment = () => this.allowComment;
                },
                addQuestion() { this.questions.push(blankQuestion()); },
                removeQuestion(qi) { if (this.questions.length > 1) this.questions.splice(qi, 1); },
                addOption(qi) { this.questions[qi].options.push({ key: makeOptKey(), label: '' }); },
                removeOption(qi, oi) { if (this.questions[qi].options.length > 2) this.questions[qi].options.splice(oi, 1); },
            };
        }

        // ── Edit-modal builder ───────────────────────────────────────────────
        let _editBuilderRef = null;
        function editSurveyBuilder() {
            return {
                questions: [blankQuestion()],
                allowComment: false,
                init() { _editBuilderRef = this; },
                loadQuestions(qs, allowComment) {
                    // Deep-clone and ensure every question/option has a key
                    this.questions = qs.map((q, qi) => ({
                        key: q.key || makeQKey(),
                        text: q.text || '',
                        options: (q.options || []).map((o, oi) => ({
                            key: o.key || makeOptKey(),
                            label: o.label || '',
                        })),
                    }));
                    if (this.questions.length === 0) this.questions = [blankQuestion()];
                    this.allowComment = !!allowComment;
                },
                addQuestion() { this.questions.push(blankQuestion()); },
                removeQuestion(qi) { if (this.questions.length > 1) this.questions.splice(qi, 1); },
                addOption(qi) { this.questions[qi].options.push({ key: makeOptKey(), label: '' }); },
                removeOption(qi, oi) { if (this.questions[qi].options.length > 2) this.questions[qi].options.splice(oi, 1); },
            };
        }

        // ── Open create modal ────────────────────────────────────────────────
        function openCreateModal() {
            document.getElementById('createSurveyModal').classList.remove('hidden');
        }

        // ── Open edit modal ──────────────────────────────────────────────────
        function openEditModal(id, title, intro, questions, allowComment, audience, isPublished, hasResponses) {
            document.getElementById('editSurveyForm').action = '/admin/surveys/' + id + '/update';
            document.getElementById('editTitle').value = title;
            document.getElementById('editIntro').value = intro || '';
            document.getElementById('editAudience').value = ['pos_all','pos_restaurant'].includes(audience) ? audience : 'pos_all';
            document.getElementById('editPublish').checked = !!isPublished;

            // Warn + optionally block if published with responses
            var warnEl = document.getElementById('editHasResponsesWarn');
            var submitBtn = document.getElementById('editSurveySubmitBtn');
            if (hasResponses && isPublished) {
                warnEl.classList.remove('hidden');
                submitBtn.disabled = true;
                submitBtn.classList.add('opacity-50', 'cursor-not-allowed');
            } else {
                warnEl.classList.add('hidden');
                submitBtn.disabled = false;
                submitBtn.classList.remove('opacity-50', 'cursor-not-allowed');
            }

            // Load questions into the Alpine builder
            if (_editBuilderRef) {
                _editBuilderRef.loadQuestions(questions || [], allowComment);
            }

            document.getElementById('editSurveyModal').classList.remove('hidden');
        }

        // ── Sync hidden JSON fields before each form submit ───────────────────
        document.getElementById('createSurveyForm').addEventListener('submit', function () {
            var qs = window._createSurveyBuilderData ? window._createSurveyBuilderData() : [];
            document.getElementById('createQuestionsJson').value = JSON.stringify(qs);
        });

        document.getElementById('editSurveyForm').addEventListener('submit', function () {
            var qs = _editBuilderRef ? _editBuilderRef.questions : [];
            document.getElementById('editQuestionsJson').value = JSON.stringify(qs);
        });
    </script>
</x-admin-layout>
