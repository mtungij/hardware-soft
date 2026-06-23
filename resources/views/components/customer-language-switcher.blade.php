@props([
    'class' => '',
])

@php
    $currentLocale = app()->getLocale();
@endphp

<div
    x-data="{ locale: localStorage.getItem('hardex_customer_locale') || @js($currentLocale) }"
    x-init="localStorage.setItem('hardex_customer_locale', @js($currentLocale))"
    class="{{ $class }}"
>
    <div class="flex items-center gap-1 rounded-xl border border-slate-200 bg-white p-1 text-xs font-black dark:border-slate-700 dark:bg-slate-900">
        @foreach (['sw' => __('messages.kiswahili'), 'en' => __('messages.english')] as $locale => $label)
            <form method="POST" action="{{ route('customer.language', $locale) }}">
                @csrf
                <button
                    type="submit"
                    @click="localStorage.setItem('hardex_customer_locale', @js($locale))"
                    class="rounded-lg px-3 py-2 transition {{ $currentLocale === $locale ? 'bg-build-orange text-white' : 'text-slate-600 hover:bg-slate-100 dark:text-slate-300 dark:hover:bg-white/5' }}"
                >
                    {{ $label }}
                </button>
            </form>
        @endforeach
    </div>
</div>
