@props([
    'label',
    'name',
])

<label class="flex items-start gap-3 text-sm font-bold text-slate-700 dark:text-slate-200" for="{{ $name }}">
    <input
        id="{{ $name }}"
        type="checkbox"
        {{ $attributes->merge(['class' => 'mt-1 rounded border-slate-300 text-build-orange shadow-sm focus:ring-build-orange dark:border-slate-700 dark:bg-slate-950']) }}
    >
    <span>{{ \App\Support\UiText::translate($label) }}</span>
</label>
