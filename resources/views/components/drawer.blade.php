@props([
    'id',
    'title' => null,
    'placement' => 'right',
])

@php
    $placementClass = $placement === 'left' ? 'left-0 -translate-x-full hs-overlay-open:translate-x-0' : 'right-0 translate-x-full hs-overlay-open:translate-x-0';
@endphp

<div id="{{ $id }}" class="hs-overlay pointer-events-none fixed inset-y-0 {{ $placementClass }} z-[80] hidden w-full max-w-md border-s border-slate-200 bg-white transition-all dark:border-slate-700 dark:bg-slate-900" role="dialog" tabindex="-1">
    <div class="flex h-full flex-col">
        <div class="flex items-center justify-between border-b border-slate-200 px-4 py-3 dark:border-slate-700">
            <h3 class="text-lg font-semibold text-slate-900 dark:text-white">{{ $title }}</h3>
            <button type="button" class="erp-btn-secondary px-2 py-1" data-hs-overlay="#{{ $id }}">Close</button>
        </div>
        <div class="flex-1 overflow-y-auto p-4">
            {{ $slot }}
        </div>
    </div>
</div>
