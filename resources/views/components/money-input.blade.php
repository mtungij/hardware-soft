@props([
    'label',
    'name',
])

<label class="erp-label" for="{{ $name }}_display">
    {{ \App\Support\UiText::translate($label) }} @if ($attributes->has('required')) <span class="text-red-500">*</span> @endif
    <span data-money-field wire:ignore class="block" data-money-currency="TZS">
        <input
            id="{{ $name }}_display"
            type="text"
            inputmode="decimal"
            data-money-display
            class="erp-input mt-1"
        >
        <input
            id="{{ $name }}"
            type="hidden"
            data-money-value
            {{ $attributes }}
        >
    </span>
    @error($name)
        <span class="erp-error">{{ $message }}</span>
    @enderror
</label>
