@props([
    'label',
    'name',
])

<label class="erp-label" for="{{ $name }}">
    {{ $label }} @if ($attributes->has('required')) <span class="text-red-500">*</span> @endif
    <select
        id="{{ $name }}"
        {{ $attributes->merge(['class' => 'erp-input mt-1']) }}
    >
        {{ $slot }}
    </select>
    @error($name)
        <span class="erp-error">{{ $message }}</span>
    @enderror
</label>
