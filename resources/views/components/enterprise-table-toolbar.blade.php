@props([
    'searchPlaceholder' => 'Search records...',
])

@php
    $searchPlaceholder = \App\Support\UiText::translate($searchPlaceholder);
@endphp

<div {{ $attributes->merge(['class' => 'mb-4 flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between']) }}>
    <div class="relative w-full lg:max-w-sm">
        <span class="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-slate-400">/</span>
        <input class="h-10 w-full rounded-lg border border-slate-200 bg-slate-50 pl-9 pr-3 text-sm outline-none ring-build-orange/20 focus:border-build-orange focus:ring-4 dark:border-slate-700 dark:bg-slate-950 dark:text-white" placeholder="{{ $searchPlaceholder }}">
    </div>
    <div class="flex flex-wrap gap-2">
        <button type="button" class="rounded-lg border border-slate-200 px-3 py-2 text-sm font-bold dark:border-slate-700">{{ \App\Support\UiText::translate('Filters') }}</button>
        <button type="button" class="rounded-lg border border-slate-200 px-3 py-2 text-sm font-bold dark:border-slate-700">{{ \App\Support\UiText::translate('Export') }}</button>
        {{ $slot }}
    </div>
</div>
