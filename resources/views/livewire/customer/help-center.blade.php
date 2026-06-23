<?php

use function Livewire\Volt\layout;
use function Livewire\Volt\state;

layout('layouts.customer');

state(['search' => '']);

$sections = fn () => [
    ['title' => 'Kuanza Kutumia Portal', 'items' => ['Angalia dashibodi yako.', 'Fuatilia madeni yako.', 'Soma taarifa kutoka kampuni.']],
    ['title' => 'Madeni Yangu', 'items' => ['Tazama invoice na salio lililobaki.', 'Fuatilia malipo yaliyofanyika.']],
    ['title' => 'Pakia Risiti', 'items' => ['Pakia uthibitisho wa malipo ili deni lipungue baada ya uhakiki.', 'Andika reference number kama ipo.']],
    ['title' => 'Amana Zangu', 'items' => ['Tazama amana ulizoweka kwa matumizi ya baadaye.', 'Angalia salio la amana.']],
    ['title' => 'Taarifa za Akaunti', 'items' => ['Pakua statement yako.', 'Tazama historia ya manunuzi na malipo.']],
    ['title' => 'Taarifa', 'items' => ['Pokea matangazo, promosheni, na ujumbe muhimu kutoka kampuni.']],
    ['title' => 'Maswali Yanayoulizwa Mara kwa Mara', 'items' => ['Kama huwezi kuingia, hakikisha email na password ni sahihi.', 'Kama deni halijapungua, subiri risiti ihakikiwe na staff.']],
];

?>

<div>
    <x-page-header title="Kituo cha Msaada" description="Mwongozo wa kutumia Portal ya Wateja." :breadcrumbs="[__('messages.customer_portal') => route('customer.dashboard'), 'Kituo cha Msaada' => null]">
        <button type="button" class="erp-btn-primary" onclick="window.HardexOnboarding?.startTour('customer-portal')">Anza Mwongozo</button>
    </x-page-header>

    <x-card>
        <input wire:model.live.debounce.300ms="search" class="erp-input" placeholder="Tafuta: madeni, risiti, amana, statement">
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
