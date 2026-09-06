{{--
    Task 1585 — "Which shops" control for an elaan (What's New).

    All shops (default) = no category stored. Specific categories = only shops
    whose resolved business category is on the list. The lists come from
    PosFeatureService::categoryGroups(), so they can never drift away from the
    signup pickers, and the visible panel sections follow the chosen audience.

    Requires: $prefix (unique id prefix), $elaanGroups, $elaanCatLabel.
--}}
<div class="rounded-lg border border-gray-200 dark:border-gray-700 p-3" data-elaan-cats="{{ $prefix }}">
    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Which shops</label>
    <div class="flex items-center gap-4 mb-2">
        <label class="flex items-center gap-1.5 text-sm text-gray-700 dark:text-gray-300">
            <input type="radio" name="{{ $prefix }}_scope" value="all" checked class="text-emerald-600" data-elaan-scope="all"> All shops
        </label>
        <label class="flex items-center gap-1.5 text-sm text-gray-700 dark:text-gray-300">
            <input type="radio" name="{{ $prefix }}_scope" value="cats" class="text-emerald-600" data-elaan-scope="cats"> Sirf chuni hui business categories
        </label>
    </div>
    <div data-elaan-catbox class="hidden space-y-3 max-h-64 overflow-y-auto pr-1">
        @foreach($elaanGroups as $panel => $groups)
            <div data-elaan-panel="{{ $panel }}">
                <p class="text-[11px] font-bold uppercase tracking-wider text-gray-400 mb-1">{{ $panel === 'fbr' ? 'FBR POS categories' : 'PRA POS categories' }}</p>
                @foreach($groups as $heading => $members)
                    <div class="mb-2">
                        <div class="flex items-center gap-2 mb-1">
                            <span class="text-[10px] font-semibold uppercase tracking-wider text-gray-400">{{ str_replace('_', ' ', $heading) }}</span>
                            <button type="button" class="text-[10px] text-emerald-600 hover:underline" data-elaan-pick="{{ implode(',', $members) }}">select all</button>
                        </div>
                        <div class="grid grid-cols-2 sm:grid-cols-3 gap-1">
                            @foreach($members as $cat)
                                <label class="flex items-center gap-1.5 text-xs text-gray-700 dark:text-gray-300">
                                    <input type="checkbox" name="target_categories[]" value="{{ $cat }}" data-elaan-cat="{{ $cat }}" class="rounded border-gray-300 text-emerald-600">
                                    {{ $elaanCatLabel($cat) }}
                                </label>
                            @endforeach
                        </div>
                    </div>
                @endforeach
            </div>
        @endforeach
    </div>
    <p class="mt-1 text-[11px] text-gray-400">Kuch bhi tick na karein = us panel ke sab shops ko elaan jayega. Category chunne par sirf wohi business type wale shops dekhenge.</p>
</div>
