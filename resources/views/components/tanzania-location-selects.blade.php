@props([
    'region',
    'district' => '',
    'regionModel' => 'region',
    'districtModel' => 'district',
    'regionName' => 'region',
    'districtName' => 'district',
    'regionLabel' => 'Mkoa',
    'districtLabel' => 'Wilaya',
    'showDistrict' => true,
])

@php
    $regions = config('tanzania.regions', []);
    $districts = $region ? ($regions[$region] ?? []) : [];
    $baseSelectClasses = 'relative py-2.5 ps-3 pe-9 flex w-full cursor-pointer rounded-lg border border-slate-200 bg-white text-start text-sm text-slate-800 shadow-sm outline-none transition before:absolute before:inset-0 before:z-1 focus:border-cyan-500 focus:ring-4 focus:ring-cyan-500/20 disabled:pointer-events-none disabled:opacity-60 dark:border-slate-700 dark:bg-slate-950 dark:text-white dark:focus:border-cyan-400';
    $dropdownClasses = 'z-[80] mt-2 max-h-72 w-full overflow-hidden overflow-y-auto rounded-xl border border-slate-200 bg-white p-1 shadow-xl dark:border-slate-700 dark:bg-slate-900';
    $optionClasses = 'cursor-pointer rounded-lg px-3 py-2 text-sm text-slate-800 hover:bg-cyan-50 focus:bg-cyan-50 hs-selected:bg-cyan-500 hs-selected:text-white dark:text-slate-100 dark:hover:bg-cyan-500/10 dark:focus:bg-cyan-500/10 dark:hs-selected:bg-cyan-500';
    $searchClasses = 'block w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm text-slate-800 outline-none ring-cyan-500/20 placeholder:text-slate-400 focus:border-cyan-500 focus:ring-4 dark:border-slate-700 dark:bg-slate-950 dark:text-white dark:placeholder:text-slate-500 dark:focus:border-cyan-400';
    $regionSelectOptions = [
        'placeholder' => 'Tafuta au chagua mkoa',
        'hasSearch' => true,
        'minSearchLength' => 0,
        'searchPlaceholder' => 'Tafuta au chagua mkoa',
        'searchNoResultText' => 'Hakuna mkoa uliopatikana',
        'optionAllowEmptyOption' => true,
        'toggleClasses' => $baseSelectClasses,
        'dropdownClasses' => $dropdownClasses,
        'optionClasses' => $optionClasses,
        'searchClasses' => $searchClasses,
        'searchWrapperClasses' => 'sticky top-0 z-10 bg-white p-1 dark:bg-slate-900',
        'dropdownScope' => 'parent',
    ];
    $districtSelectOptions = [
        'placeholder' => $region ? 'Tafuta au chagua wilaya' : 'Tafadhali chagua mkoa kwanza',
        'hasSearch' => true,
        'minSearchLength' => 0,
        'searchPlaceholder' => 'Tafuta au chagua wilaya',
        'searchNoResultText' => 'Hakuna wilaya zilizopatikana',
        'optionAllowEmptyOption' => true,
        'toggleClasses' => $baseSelectClasses,
        'dropdownClasses' => $dropdownClasses,
        'optionClasses' => $optionClasses,
        'searchClasses' => $searchClasses,
        'searchWrapperClasses' => 'sticky top-0 z-10 bg-white p-1 dark:bg-slate-900',
        'dropdownScope' => 'parent',
    ];
@endphp

<label class="block text-sm font-bold text-slate-700 dark:text-slate-200">
    {{ $regionLabel }}
    <select
        wire:model.live="{{ $regionModel }}"
        wire:key="{{ $regionModel }}-searchable-region-select"
        name="{{ $regionName }}"
        data-hs-select='@json($regionSelectOptions)'
        class="hidden"
    >
        <option value="" @selected(blank($region))>Tafuta au chagua mkoa</option>
        @foreach ($regions as $regionNameOption => $regionDistricts)
            <option value="{{ $regionNameOption }}" @selected($region === $regionNameOption)>{{ $regionNameOption }}</option>
        @endforeach
    </select>
    @error($regionModel)
        <span class="mt-1 block text-xs font-semibold text-red-600">{{ $message }}</span>
    @enderror
</label>

@if ($showDistrict)
    <label class="block text-sm font-bold text-slate-700 dark:text-slate-200">
        {{ $districtLabel }}
        <select
            wire:model="{{ $districtModel }}"
            wire:key="{{ $districtModel }}-searchable-district-select-{{ \Illuminate\Support\Str::slug($region ?: 'none') }}"
            name="{{ $districtName }}"
            data-hs-select='@json($districtSelectOptions)'
            class="hidden"
            @disabled(! $region)
        >
            <option value="" @selected(blank($district))>{{ $region ? 'Tafuta au chagua wilaya' : 'Tafadhali chagua mkoa kwanza' }}</option>
            @foreach ($districts as $districtNameOption)
                <option value="{{ $districtNameOption }}" @selected($district === $districtNameOption)>{{ $districtNameOption }}</option>
            @endforeach
        </select>
        @if (! $region)
            <span class="mt-1 block text-xs font-semibold text-slate-500 dark:text-slate-400">Tafadhali chagua mkoa kwanza.</span>
        @endif
        @error($districtModel)
            <span class="mt-1 block text-xs font-semibold text-red-600">{{ $message }}</span>
        @enderror
    </label>
@endif
