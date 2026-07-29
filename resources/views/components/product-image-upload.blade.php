@props([
    'previewUrl' => null,
    'hasCurrentImage' => false,
    'isNewPreview' => false,
])

<section
    class="md:col-span-2 xl:col-span-3"
    x-data="{ uploading: false, progress: 0 }"
    x-on:livewire-upload-start="uploading = true; progress = 0"
    x-on:livewire-upload-finish="uploading = false"
    x-on:livewire-upload-error="uploading = false"
    x-on:livewire-upload-progress="progress = $event.detail.progress"
>
    <p class="text-sm font-bold text-slate-700 dark:text-slate-200">{{ __('products.image.label') }}</p>

    <div class="mt-1 grid gap-4 rounded-xl border border-slate-200 p-4 dark:border-slate-700 sm:grid-cols-[9rem_1fr]">
        <div class="relative h-32 overflow-hidden rounded-xl bg-slate-100 dark:bg-slate-800">
            <img
                src="{{ $previewUrl ?: asset('images/product-placeholder.svg') }}"
                alt="{{ $previewUrl ? __('products.image.preview') : __('products.image.no_image') }}"
                class="h-full w-full object-cover"
                onerror="this.onerror=null;this.src='/images/product-placeholder.svg';"
            >
            @if ($hasCurrentImage && ! $isNewPreview)
                <span class="absolute inset-x-2 bottom-2 rounded-md bg-slate-950/70 px-2 py-1 text-center text-xs font-bold text-white">
                    {{ __('products.image.current') }}
                </span>
            @endif
        </div>

        <div class="min-w-0">
            <label class="relative flex min-h-24 cursor-pointer touch-manipulation flex-col items-center justify-center rounded-xl border-2 border-dashed border-slate-300 bg-slate-50 px-4 py-4 text-center transition hover:border-build-orange dark:border-slate-700 dark:bg-white/5">
                <input
                    type="file"
                    wire:model="image_upload"
                    accept="image/jpeg,image/png,image/webp"
                    class="absolute inset-0 h-full w-full cursor-pointer opacity-0"
                >
                <span class="font-bold text-slate-700 dark:text-slate-200">{{ __('products.image.drop_or_browse') }}</span>
                <span class="mt-1 text-xs text-slate-500">{{ __('products.image.help') }}</span>
            </label>

            <div class="mt-3 flex flex-wrap gap-2">
                <label class="erp-btn-secondary relative cursor-pointer overflow-hidden">
                    <input type="file" wire:model="image_upload" accept="image/jpeg,image/png,image/webp" class="absolute inset-0 cursor-pointer opacity-0">
                    {{ $hasCurrentImage || $previewUrl ? __('products.image.replace') : __('products.image.choose') }}
                </label>
                <label class="erp-btn-secondary relative cursor-pointer overflow-hidden">
                    <input type="file" wire:model="image_upload" accept="image/*" capture="environment" class="absolute inset-0 cursor-pointer opacity-0">
                    {{ __('products.image.take_photo') }}
                </label>
                @if ($previewUrl || $hasCurrentImage)
                    <button type="button" wire:click="removeImage" class="rounded-lg px-3 py-2 text-sm font-bold text-red-600 hover:bg-red-50 dark:hover:bg-red-500/10">
                        {{ __('products.image.remove') }}
                    </button>
                @endif
            </div>

            <div x-show="uploading" x-cloak class="mt-3" aria-live="polite">
                <div class="flex justify-between text-xs font-bold text-slate-600 dark:text-slate-300">
                    <span>{{ __('products.image.uploading') }}</span>
                    <span x-text="progress + '%'"></span>
                </div>
                <div class="mt-1 h-2 overflow-hidden rounded-full bg-slate-200 dark:bg-slate-700">
                    <div class="h-full rounded-full bg-build-orange transition-all" x-bind:style="`width: ${progress}%`"></div>
                </div>
            </div>

            @error('image_upload')
                <span class="mt-2 block text-xs font-semibold text-red-600">{{ $message }}</span>
            @enderror
        </div>
    </div>
</section>
