@props([
    'label',
    'name',
])

<label class="flex items-center justify-between gap-4 rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm font-bold text-slate-700 dark:border-slate-700 dark:bg-slate-950 dark:text-slate-200" for="{{ $name }}">
    <span>{{ \App\Support\UiText::translate($label) }}</span>
    <input id="{{ $name }}" type="checkbox" class="peer sr-only" {{ $attributes }}>
    <span class="h-6 w-11 rounded-full bg-slate-300 p-0.5 transition peer-checked:bg-build-orange dark:bg-slate-700">
        <span class="block h-5 w-5 rounded-full bg-white transition peer-checked:translate-x-5"></span>
    </span>
</label>
