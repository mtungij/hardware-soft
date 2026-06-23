@props([
    'tone' => 'info',
])

@php
    $classes = [
        'success' => 'badge-success',
        'warning' => 'badge-warning',
        'danger' => 'badge-danger',
        'info' => 'badge-info',
    ][$tone] ?? 'badge-info';
@endphp

<span {{ $attributes->merge(['class' => $classes]) }}>{{ $slot }}</span>
