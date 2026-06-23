<?php

use function Livewire\Volt\layout;
use function Livewire\Volt\state;

layout('layouts.app');

state(['search' => '']);

$sections = fn () => [
    ['title' => 'Kuanza Kutumia Mfumo', 'tour' => 'dashboard', 'items' => ['Jaza taarifa za kampuni', 'Ongeza tawi la kwanza', 'Ongeza mtumiaji wa kwanza', 'Kagua dashibodi kila siku']],
    ['title' => 'Bidhaa', 'tour' => 'products', 'items' => ['Ongeza bidhaa kabla ya kurekodi manunuzi.', 'SKU ni utambulisho wa kipekee wa bidhaa.', 'Reorder Level ni kiwango cha chini kabla ya kuagiza tena.']],
    ['title' => 'Manunuzi', 'tour' => 'purchases', 'items' => ['Unda purchase order.', 'Chagua supplier.', 'Ongeza bidhaa na gharama.', 'Pokea stock baada ya bidhaa kufika.']],
    ['title' => 'Mauzo', 'tour' => 'pos', 'items' => ['Tafuta bidhaa au tumia barcode scanner.', 'Chagua mteja kama ni mauzo ya mkopo.', 'Kamilisha malipo na chapisha risiti.']],
    ['title' => 'Stock', 'tour' => 'stock', 'items' => ['Pokea stock kwanza kabla ya kuhamisha kwenye Dispensing.', 'Dispensing Stock hutumika kuuza moja kwa moja.', 'Stock Movements ni historia ya kila movement.']],
    ['title' => 'Wateja', 'tour' => 'customers', 'items' => ['Ongeza mteja na email yake.', 'Mfumo unaweza kutuma taarifa za Customer Portal.', 'Tazama madeni, malipo, na statement za wateja.']],
    ['title' => 'Suppliers', 'tour' => 'suppliers', 'items' => ['Ongeza supplier mpya.', 'Tengeneza purchase order.', 'Tuma oda kwa email kama SMTP ipo tayari.']],
    ['title' => 'Ripoti', 'tour' => 'reports', 'items' => ['Tumia ripoti za mauzo, manunuzi, faida, na stock.', 'Hamisha ripoti kwenda PDF au Excel pale inapopatikana.']],
    ['title' => 'Portal ya Wateja', 'tour' => 'customers', 'items' => ['Wateja wanaweza kuona madeni.', 'Wanaweza kupakia risiti.', 'Wanaweza kupokea taarifa na matangazo.']],
    ['title' => 'Maswali Yanayoulizwa Mara kwa Mara', 'tour' => 'dashboard', 'items' => ['Kama data haionekani, hakikisha filters ziko sahihi.', 'Kama email haitumwi, kagua SMTP settings.', 'Kama stock haiongezeki, hakikisha purchase imepokelewa.']],
];

?>

<div>
    <x-page-header title="Kituo cha Msaada" description="Tafuta mwongozo, vidokezo, na anza tour yoyote upya." :breadcrumbs="['Dashboard' => route('dashboard'), 'Kituo cha Msaada' => null]" />

    <x-card>
        <input wire:model.live.debounce.300ms="search" class="erp-input" placeholder="Tafuta: jinsi ya kuongeza bidhaa, kupokea stock, kuuza, au kupakia risiti">
    </x-card>

    <div class="mt-6 grid gap-4 md:grid-cols-2 xl:grid-cols-3">
        @foreach (collect($this->sections())->filter(fn ($section) => ! $search || str($section['title'].' '.implode(' ', $section['items']))->lower()->contains(str($search)->lower())) as $section)
            <x-card :title="$section['title']">
                <ul class="space-y-2 text-sm text-slate-600 dark:text-slate-300">
                    @foreach ($section['items'] as $item)
                        <li class="rounded-lg border border-slate-200 px-3 py-2 dark:border-slate-700">{{ $item }}</li>
                    @endforeach
                </ul>
                <button type="button" class="mt-4 rounded-lg bg-build-orange px-3 py-2 text-sm font-semibold text-white" onclick="window.HardexOnboarding?.startTour('{{ $section['tour'] }}')">Anza Mwongozo</button>
            </x-card>
        @endforeach
    </div>
</div>
