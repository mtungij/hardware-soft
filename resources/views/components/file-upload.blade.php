@props([
    'label',
    'name',
])

<label class="erp-label" for="{{ $name }}">
    {{ $label }}
    <input
        id="{{ $name }}"
        type="file"
        {{ $attributes->merge(['class' => 'mt-1 block w-full cursor-pointer rounded-xl border border-dashed border-slate-300 bg-white px-3 py-3 text-sm text-slate-600 shadow-sm file:me-3 file:rounded-lg file:border-0 file:bg-build-orange file:px-3 file:py-2 file:text-sm file:font-semibold file:text-white hover:border-build-orange focus:outline-none focus:ring-2 focus:ring-build-orange disabled:pointer-events-none disabled:opacity-50 dark:border-slate-700 dark:bg-slate-950 dark:text-slate-300 dark:hover:border-build-orange']) }}
    >
    @error($name)
        <span class="erp-error">{{ $message }}</span>
    @enderror
</label>
