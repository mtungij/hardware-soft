@props([
    'label',
    'name',
    'rows' => 4,
])

<label class="erp-label" for="{{ $name }}">
    {{ \App\Support\UiText::translate($label) }} @if ($attributes->has('required')) <span class="text-red-500">*</span> @endif
    <textarea
        id="{{ $name }}"
        rows="{{ $rows }}"
        {{ $attributes->merge(['class' => 'erp-input mt-1 min-h-24']) }}
    >{{ $slot }}</textarea>
    @error($name)
        <span class="erp-error">{{ $message }}</span>
    @enderror
</label>
