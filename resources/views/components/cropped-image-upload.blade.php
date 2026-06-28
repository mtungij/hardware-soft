@props([
    'label',
    'name',
    'previewSize' => 512,
])

<label class="block text-sm font-bold text-slate-700 dark:text-slate-200" data-image-crop-upload data-preview-size="{{ $previewSize }}">
    {{ \App\Support\UiText::translate($label) }}
    <input
        id="{{ $name }}"
        type="file"
        accept="image/png,image/jpeg,image/webp"
        data-image-crop-input
        {{ $attributes->merge(['class' => 'mt-1 block w-full cursor-pointer rounded-xl border border-dashed border-slate-300 bg-white px-3 py-3 text-sm text-slate-600 shadow-sm file:me-3 file:rounded-lg file:border-0 file:bg-cyan-500 file:px-3 file:py-2 file:text-sm file:font-semibold file:text-white hover:border-cyan-500 focus:outline-none focus:ring-2 focus:ring-cyan-500 disabled:pointer-events-none disabled:opacity-50 dark:border-slate-700 dark:bg-slate-950 dark:text-slate-300 dark:hover:border-cyan-400']) }}
    >
    <p class="mt-2 text-xs font-semibold text-slate-500 dark:text-slate-400">Chagua picha, kisha ipunguze vizuri kabla ya kuhifadhi.</p>
    @error($name)
        <span class="mt-1 block text-xs font-semibold text-red-600">{{ $message }}</span>
    @enderror
</label>
