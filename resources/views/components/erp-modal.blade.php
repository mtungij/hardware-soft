@props([
    'name',
    'title',
])

<div x-cloak x-show="{{ $name }}" x-transition.opacity class="fixed inset-0 z-50 grid place-items-center bg-slate-950/60 p-4" role="dialog" aria-modal="true" @keydown.escape.window="{{ $name }} = false">
    <div class="max-h-[90vh] w-full max-w-2xl overflow-y-auto rounded-xl border border-slate-200 bg-white p-5 shadow-soft dark:border-slate-700 dark:bg-slate-900" @click.outside="{{ $name }} = false">
        <div class="mb-4 flex items-start justify-between gap-4">
            <h2 class="text-lg font-black text-navy-900 dark:text-white">{{ $title }}</h2>
            <button type="button" class="rounded-lg border border-slate-200 px-3 py-1.5 text-sm font-bold dark:border-slate-700" @click="{{ $name }} = false">Close</button>
        </div>

        {{ $slot }}
    </div>
</div>
