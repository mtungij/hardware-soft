@props([
    'product' => null,
    'alt' => null,
    'initials' => null,
    'fallbackClass' => null,
    'initialsClass' => null,
    'frameClass' => 'rounded-lg ring-1',
])

@php
    $name = trim((string) ($product?->name ?? ''));
    $avatar = \App\Support\ProductAvatar::for($name, $product?->getKey());
    $displayInitials = filled($initials) ? (string) $initials : $avatar['initials'];
    $accentClasses = filled($fallbackClass) ? (string) $fallbackClass : $avatar['classes'];
    $initialTypography = filled($initialsClass) ? (string) $initialsClass : 'text-xl font-black tracking-wide';
    $imageUrl = $product?->image_url;
    $hasImage = filled($imageUrl) && $imageUrl !== '/images/product-placeholder.svg';
@endphp

<span
    {{ $attributes->class(['relative isolate block shrink-0 overflow-hidden', $frameClass]) }}
    role="img"
    aria-label="{{ $alt ?? ($name ?: __('products.image.no_image')) }}"
    data-product-image
    data-product-initials="{{ $displayInitials }}"
    data-product-accent="{{ $accentClasses }}"
>
    <span
        class="absolute inset-0 grid place-items-center {{ $initialTypography }} {{ $accentClasses }}"
        aria-hidden="true"
        data-product-image-fallback
    >{{ $displayInitials }}</span>

    @if ($hasImage)
        <img
            class="absolute inset-0 h-full w-full object-cover"
            src="{{ $imageUrl }}"
            alt="{{ $alt ?? $name }}"
            loading="lazy"
            decoding="async"
            onerror="this.onerror=null;this.hidden=true;"
            data-product-image-source
        >
    @else
        <span class="sr-only">{{ $alt ?? ($name ?: __('products.image.no_image')) }}</span>
    @endif
</span>
