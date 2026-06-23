@props([
    'label',
    'name',
])

<label class="block text-sm font-bold text-slate-700 dark:text-slate-200" for="{{ $name }}">
    {{ $label }}
    <input
        id="{{ $name }}"
        type="file"
        {{ $attributes->merge(['class' => 'mt-1 block w-full rounded-lg border border-dashed border-slate-300 bg-white px-3 py-3 text-sm file:mr-3 file:rounded-lg file:border-0 file:bg-build-orange file:px-3 file:py-2 file:text-sm file:font-bold file:text-white dark:border-slate-700 dark:bg-slate-950 dark:text-slate-200']) }}
    >
    @error($name)
        <span class="mt-1 block text-xs font-semibold text-red-600">{{ $message }}</span>
    @enderror
</label>
