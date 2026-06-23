<?php

use function Livewire\Volt\layout;

layout('layouts.customer');

?>

@php
    $company = \App\Models\Company::current();
    $whatsappLink = $company?->whatsappLink();
@endphp

<div class="mx-auto max-w-2xl">
    <div class="rounded-3xl border border-amber-200 bg-amber-50 p-8 text-center dark:border-amber-500/30 dark:bg-amber-500/10">
        <div class="mx-auto grid h-16 w-16 place-items-center rounded-2xl bg-amber-500 text-2xl font-black text-white">!</div>
        <h1 class="mt-6 text-2xl font-black text-navy-900 dark:text-white">{{ __('messages.auth.pending_title') }}</h1>
        <p class="mt-3 text-sm leading-6 text-amber-800 dark:text-amber-100">{{ __('messages.auth.pending_description') }}</p>
        <div class="mt-6 flex flex-col gap-3 sm:flex-row sm:justify-center">
            @if ($whatsappLink)
                <a href="{{ $whatsappLink }}" target="_blank" rel="noopener" class="rounded-xl bg-emerald-600 px-4 py-3 text-sm font-black text-white">{{ __('messages.support.chat_whatsapp') }}</a>
            @endif
            <form method="POST" action="{{ route('customer.logout') }}">
                @csrf
                <button class="rounded-xl border border-slate-300 px-4 py-3 text-sm font-black dark:border-slate-700">{{ __('messages.nav.logout') }}</button>
            </form>
        </div>
    </div>
</div>
