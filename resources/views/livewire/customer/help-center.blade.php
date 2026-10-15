<?php

use function Livewire\Volt\layout;
use function Livewire\Volt\state;

layout('layouts.customer');

state(['search' => '']);

$sections = fn () => [
    ['title' => __('help.9dc65ae46391'), 'items' => [__('help.0e58c5e02032'), __('help.770417a14855'), __('help.c2895383b403')]],
    ['title' => __('help.f133ad28d636'), 'items' => [__('help.2d2329e4e8ab'), __('help.616a4c98f905')]],
    ['title' => __('help.9f344dadce0f'), 'items' => [__('help.ccb14996a45c'), __('help.55743816bc3b')]],
    ['title' => __('help.5973e69df5bb'), 'items' => [__('help.f60d48b17e8e'), __('help.90e0c557f34d')]],
    ['title' => __('help.b87a4bf19833'), 'items' => [__('help.f27a3753510c'), __('help.e85ab743a56b')]],
    ['title' => __('help.753a22b2eb61'), 'items' => [__('application.receive_announcements_promotions_and_important_company_messages_b5e55e0a')]],
    ['title' => __('help.d790b402d79a'), 'items' => [__('help.4e223792662e'), __('help.c2e2da1b9499')]],
];

?>

<div>
    <x-page-header title="{{ __('application.help_center_11015825') }}" description="{{ __('application.customer_portal_user_guide_423b1f6d') }}" :breadcrumbs="[__('messages.customer_portal') => route('customer.dashboard'), 'Kituo cha Msaada' => null]">
        <button type="button" class="erp-btn-primary" onclick="window.HardexOnboarding?.startTour('customer-portal')">{{ __('application.start_guide_4e62f7d3') }}</button>
    </x-page-header>

    <x-card>
        <input wire:model.live.debounce.300ms="search" class="erp-input" placeholder="{{ __('application.search_debts_receipts_deposits_statements_07110349') }}">
    </x-card>

    <div class="mt-6 grid gap-4 md:grid-cols-2">
        @foreach (collect($this->sections())->filter(fn ($section) => ! $search || str($section['title'].' '.implode(' ', $section['items']))->lower()->contains(str($search)->lower())) as $section)
            <x-card :title="$section['title']">
                <ul class="space-y-2 text-sm text-slate-600 dark:text-slate-300">
                    @foreach ($section['items'] as $item)
                        <li class="rounded-lg border border-slate-200 px-3 py-2 dark:border-slate-700">{{ $item }}</li>
                    @endforeach
                </ul>
            </x-card>
        @endforeach
    </div>
</div>
