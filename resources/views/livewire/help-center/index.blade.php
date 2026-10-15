<?php

use function Livewire\Volt\layout;
use function Livewire\Volt\state;

layout('layouts.app');

state(['search' => '']);

$sections = fn () => [
    ['title' => __('help.010b85ad56b3'), 'tour' => 'dashboard', 'items' => [__('help.b962c05e44ac'), __('help.2ce212a4b66c'), __('help.81d6bb6ca052'), __('help.6548c74fdff5')]],
    ['title' => __('help.fe0a091fdbfb'), 'tour' => 'products', 'items' => [__('help.11ee3587bcfe'), __('help.4f334018fa80'), __('help.e1b458fca3c6')]],
    ['title' => __('help.6c4e94f10417'), 'tour' => 'purchases', 'items' => [__('help.dcdce2bdd400'), __('help.9ed8c23980d3'), __('help.64368d8c1f57'), __('help.8b25f4a8ac4f')]],
    ['title' => __('help.d0edfb6e8c55'), 'tour' => 'pos', 'items' => [__('help.4d3a5a955e80'), __('help.eaad095bb626'), __('help.23238b70803b')]],
    ['title' => __('help.bcecf4562f17'), 'tour' => 'stock', 'items' => [__('help.60c387e229f0'), __('help.9daef275becf'), __('help.5a742a970e23')]],
    ['title' => __('help.035eae60290f'), 'tour' => 'customers', 'items' => [__('help.06405014c420'), __('help.26905866d4e5'), __('help.d5f5edfb6760')]],
    ['title' => __('help.6f76bb2b1215'), 'tour' => 'suppliers', 'items' => [__('help.79a534df8e44'), __('help.dcdce2bdd400'), __('help.cda40995538e')]],
    ['title' => __('help.88bc3fe3dadd'), 'tour' => 'reports', 'items' => [__('help.301835f9a6af'), __('help.5b99c168d68b')]],
    ['title' => __('help.1cef85561f1a'), 'tour' => 'customers', 'items' => [__('help.e59e8f34c6ad'), __('help.47831639ae28'), __('help.e6a930dc95cd')]],
    ['title' => __('help.d790b402d79a'), 'tour' => 'dashboard', 'items' => [__('help.217a6deb0f93'), __('help.e8d2c201b311'), __('help.ed19b190de67')]],
];

?>

<div>
    <x-page-header title="{{ __('application.help_center_11015825') }}" description="{{ __('application.search_guides_and_tips_and_restart_any_tour_4043d726') }}" :breadcrumbs="['Dashboard' => route('dashboard'), 'Kituo cha Msaada' => null]" />

    <x-card>
        <input wire:model.live.debounce.300ms="search" class="erp-input" placeholder="{{ __('application.search_adding_products_receiving_stock_selling_or_uploading_receipts_07e8e7b8') }}">
    </x-card>

    <div class="mt-6 grid gap-4 md:grid-cols-2 xl:grid-cols-3">
        @foreach (collect($this->sections())->filter(fn ($section) => ! $search || str($section['title'].' '.implode(' ', $section['items']))->lower()->contains(str($search)->lower())) as $section)
            <x-card :title="$section['title']">
                <ul class="space-y-2 text-sm text-slate-600 dark:text-slate-300">
                    @foreach ($section['items'] as $item)
                        <li class="rounded-lg border border-slate-200 px-3 py-2 dark:border-slate-700">{{ $item }}</li>
                    @endforeach
                </ul>
                <button type="button" class="mt-4 rounded-lg bg-build-orange px-3 py-2 text-sm font-semibold text-white" onclick="window.HardexOnboarding?.startTour('{{ $section['tour'] }}')">{{ __('application.start_guide_4e62f7d3') }}</button>
            </x-card>
        @endforeach
    </div>
</div>
