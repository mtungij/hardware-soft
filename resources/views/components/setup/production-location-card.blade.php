@props([
    'role',
    'definition',
    'nameField',
    'currentName',
    'source' => 'recommended',
    'renameOpen' => false,
    'existingLocations' => [],
])

@php
    $toneClasses = match ($definition['tone']) {
        'amber' => 'bg-amber-50 text-amber-700 ring-amber-100 dark:bg-amber-500/10 dark:text-amber-300 dark:ring-amber-500/20',
        'blue' => 'bg-blue-50 text-blue-700 ring-blue-100 dark:bg-blue-500/10 dark:text-blue-300 dark:ring-blue-500/20',
        'emerald' => 'bg-emerald-50 text-emerald-700 ring-emerald-100 dark:bg-emerald-500/10 dark:text-emerald-300 dark:ring-emerald-500/20',
        default => 'bg-cyan-50 text-cyan-700 ring-cyan-100 dark:bg-cyan-500/10 dark:text-cyan-300 dark:ring-cyan-500/20',
    };
    $isExisting = $source !== 'recommended' && $source !== 'custom';
    $cardId = 'production-location-'.$role;
@endphp

<article class="min-w-0 rounded-2xl border border-slate-200 bg-white p-4 shadow-sm transition hover:border-cyan-300 hover:shadow-md dark:border-slate-700 dark:bg-slate-900 sm:p-5" aria-labelledby="{{ $cardId }}-title">
    <div class="flex min-w-0 items-start gap-3">
        <span class="grid h-11 w-11 shrink-0 place-items-center rounded-xl ring-1 {{ $toneClasses }}" aria-hidden="true">
            @switch($definition['icon'])
                @case('building-office')
                    <svg class="h-6 w-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M3.75 21h16.5M6 21V3.75A.75.75 0 0 1 6.75 3h6.75a.75.75 0 0 1 .75.75V21m0-12h3a.75.75 0 0 1 .75.75V21M8.25 6.75h1.5m-1.5 3h1.5m-1.5 3h1.5m-1.5 3h1.5"/></svg>
                    @break
                @case('beaker')
                    <svg class="h-6 w-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M9.75 3.75h4.5m-3.75 0v5.1L5.4 17.25A2.25 2.25 0 0 0 7.32 20.7h9.36a2.25 2.25 0 0 0 1.92-3.45l-5.1-8.4v-5.1M7.5 15h9"/></svg>
                    @break
                @case('building-storefront')
                    <svg class="h-6 w-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M13.5 21v-7.5a.75.75 0 0 1 .75-.75h3a.75.75 0 0 1 .75.75V21m-12 0v-7.5a.75.75 0 0 0-.75-.75h-3M3 8.25l1.5-4.5h15l1.5 4.5M3 8.25a2.25 2.25 0 0 0 4.5 0 2.25 2.25 0 0 0 4.5 0 2.25 2.25 0 0 0 4.5 0 2.25 2.25 0 0 0 4.5 0V21H3V8.25Z"/></svg>
                    @break
                @default
                    <svg class="h-6 w-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M20.25 7.5 12 3 3.75 7.5M20.25 7.5 12 12m8.25-4.5V16.5L12 21m0-9L3.75 7.5M12 12v9m-8.25-13.5V16.5L12 21"/></svg>
            @endswitch
        </span>
        <div class="min-w-0 flex-1">
            <div class="flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between">
                <div class="min-w-0">
                    <p class="text-xs font-black uppercase tracking-wide text-slate-400">{{ $definition['type'] }}</p>
                    <h2 id="{{ $cardId }}-title" class="mt-0.5 text-base font-black text-slate-950 dark:text-white">{{ $definition['title'] }}</h2>
                </div>
                <span class="inline-flex w-fit shrink-0 items-center gap-1 rounded-full px-2.5 py-1 text-xs font-black {{ $isExisting ? 'bg-emerald-100 text-emerald-800 dark:bg-emerald-500/15 dark:text-emerald-300' : 'bg-cyan-100 text-cyan-800 dark:bg-cyan-500/15 dark:text-cyan-300' }}">
                    <svg class="h-3.5 w-3.5" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M16.704 5.29a1 1 0 0 1 .006 1.414l-7.25 7.32a1 1 0 0 1-1.42 0L3.29 9.229a1 1 0 1 1 1.42-1.408l4.04 4.077 6.54-6.602a1 1 0 0 1 1.414-.006Z" clip-rule="evenodd"/></svg>
                    {{ $isExisting ? __('setup.existing_location') : __('setup.will_be_created') }}
                </span>
            </div>
        </div>
    </div>

    <p class="mt-3 text-sm leading-6 text-slate-600 dark:text-slate-300">{{ $definition['description'] }}</p>

    <div class="mt-4 rounded-xl bg-slate-50 px-3 py-2.5 dark:bg-slate-950/70">
        <p class="text-xs font-bold text-slate-500">{{ __('setup.current_selection') }}</p>
        <p class="mt-0.5 break-words text-sm font-black text-slate-900 dark:text-white">{{ $currentName }}</p>
    </div>

    <div class="mt-4 flex flex-wrap items-center gap-2">
        @if (count($existingLocations))
            <div class="relative" x-data="{ open: false, query: '' }" @click.outside="open = false">
                <button type="button" class="inline-flex min-h-10 items-center gap-2 rounded-lg border border-slate-200 px-3 text-sm font-bold text-slate-700 transition hover:border-cyan-400 dark:border-slate-700 dark:text-slate-200" @click="open = !open; $nextTick(() => open && $refs.search.focus())" :aria-expanded="open" aria-haspopup="listbox" aria-controls="{{ $cardId }}-locations">
                    {{ __('setup.use_existing_location') }}
                    <svg class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M5.23 7.21a.75.75 0 0 1 1.06.02L10 11.168l3.71-3.938a.75.75 0 1 1 1.08 1.04l-4.25 4.5a.75.75 0 0 1-1.08 0l-4.25-4.5a.75.75 0 0 1 .02-1.06Z" clip-rule="evenodd"/></svg>
                </button>
                <div x-cloak x-show="open" x-transition id="{{ $cardId }}-locations" role="listbox" class="absolute left-0 z-20 mt-2 w-64 max-w-[calc(100vw-3rem)] rounded-xl border border-slate-200 bg-white p-2 shadow-xl dark:border-slate-700 dark:bg-slate-900">
                    <label for="{{ $cardId }}-search" class="sr-only">{{ __('setup.search_locations') }}</label>
                    <input x-ref="search" x-model="query" id="{{ $cardId }}-search" type="search" autocomplete="off" placeholder="{{ __('setup.search_locations') }}" class="block min-h-10 w-full rounded-lg border border-slate-200 bg-white px-3 text-sm dark:border-slate-700 dark:bg-slate-950">
                    <div class="mt-1 max-h-40 overflow-y-auto">
                        @foreach ($existingLocations as $value => $label)
                            <button type="button" role="option" aria-selected="{{ $source === $value ? 'true' : 'false' }}" x-show="@js(mb_strtolower($label)).includes(query.toLowerCase())" wire:click="selectProductionLocation('{{ $role }}', '{{ $value }}')" @click="open = false" class="flex min-h-10 w-full items-center justify-between rounded-lg px-3 text-left text-sm font-bold hover:bg-cyan-50 dark:hover:bg-cyan-500/10">
                                <span>{{ $label }}</span>
                                @if ($source === $value)<span class="text-cyan-600" aria-hidden="true">✓</span>@endif
                            </button>
                        @endforeach
                    </div>
                </div>
            </div>
        @endif

        @if ($source !== 'recommended')
            <button type="button" wire:click="selectProductionLocation('{{ $role }}', 'recommended')" class="min-h-10 rounded-lg px-3 text-sm font-bold text-cyan-700 hover:bg-cyan-50 dark:text-cyan-300 dark:hover:bg-cyan-500/10">{{ __('setup.use_recommended') }}</button>
        @endif
        <button type="button" wire:click="toggleLocationRename('{{ $role }}')" aria-expanded="{{ $renameOpen ? 'true' : 'false' }}" aria-controls="{{ $cardId }}-rename" class="min-h-10 rounded-lg px-3 text-sm font-bold text-slate-600 hover:bg-slate-100 dark:text-slate-300 dark:hover:bg-white/5">{{ __('setup.rename') }}</button>
    </div>

    @if ($renameOpen)
        <div id="{{ $cardId }}-rename" class="mt-3 border-t border-slate-100 pt-3 dark:border-slate-800">
            <label for="{{ $cardId }}-name" class="block text-sm font-bold text-slate-700 dark:text-slate-200">{{ __('setup.location_name') }}</label>
            <input id="{{ $cardId }}-name" type="text" wire:model.live.debounce.300ms="{{ $nameField }}" autocomplete="off" class="mt-1 block min-h-11 w-full rounded-lg border border-slate-200 bg-white px-3 text-sm dark:border-slate-700 dark:bg-slate-950" aria-describedby="{{ $cardId }}-error">
            @error($nameField)<span id="{{ $cardId }}-error" class="mt-1 block text-xs font-semibold text-red-600" role="alert">{{ $message }}</span>@enderror
        </div>
    @endif
</article>
