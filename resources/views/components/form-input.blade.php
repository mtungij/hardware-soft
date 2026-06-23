@props([
    'label',
    'name',
    'type' => 'text',
])

<label class="erp-label" for="{{ $name }}">
    {{ $label }} @if ($attributes->has('required')) <span class="text-red-500">*</span> @endif
    <input
        id="{{ $name }}"
        type="{{ $type }}"
        {{ $attributes->merge(['class' => 'erp-input mt-1']) }}
    >
    @error($name)
        <span class="erp-error">{{ $message }}</span>
    @enderror
</label>
