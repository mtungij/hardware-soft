@props([
    'label',
    'name',
    'type' => 'text',
])

<label class="block text-sm font-bold text-slate-700 dark:text-slate-200" for="{{ $name }}">
    {{ $label }} @if ($attributes->has('required')) <span class="text-red-500">*</span> @endif
    <input
        id="{{ $name }}"
        type="{{ $type }}"
        {{ $attributes->merge(['class' => 'mt-1 block min-h-10 w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm shadow-sm outline-none ring-build-orange/20 transition focus:border-build-orange focus:ring-4 disabled:cursor-not-allowed disabled:opacity-60 dark:border-slate-700 dark:bg-slate-950 dark:text-white dark:placeholder:text-slate-500']) }}
    >
    @error($name)
        <span class="mt-1 block text-xs font-semibold text-red-600">{{ $message }}</span>
    @enderror
</label>
