<?php

use App\Services\CustomerPortalCatalogue;
use function Livewire\Volt\computed;
use function Livewire\Volt\layout;
use function Livewire\Volt\state;

layout('layouts.customer');
state(['search' => '', 'selectedProducts' => []]);

$toggleProduct = function (int $productId): void {
    $account = auth('customer')->user();

    app(CustomerPortalCatalogue::class)
        ->products($account)
        ->findOrFail($productId);

    $ids = array_map('intval', $this->selectedProducts);

    if (in_array($productId, $ids, true)) {
        $ids = array_values(array_diff($ids, [$productId]));
    } else {
        $ids[] = $productId;
    }

    $this->selectedProducts = $ids;
};

$continueRequest = function (): void {
    $account = auth('customer')->user();
    $ids = array_values(array_unique(array_map('intval', $this->selectedProducts)));

    if (count($ids) < 1 || count($ids) > 50) {
        return;
    }

    $validIds = app(CustomerPortalCatalogue::class)
        ->products($account)
        ->whereIn('id', $ids)
        ->pluck('id')
        ->map(fn ($id) => (int) $id)
        ->all();

    abort_unless(count($validIds) === count($ids), 403);

    session()->put('customer_portal_selected_products:' . auth('customer')->user()->company_id . ':' . auth('customer')->id(), $ids);

    $this->redirectRoute('customer.purchase-requests.create', navigate: true);
};
$products = computed(function () {
    $account = auth('customer')->user();
    $catalogue = app(CustomerPortalCatalogue::class);
    $branch = $catalogue->branch($account);
    return $catalogue->products($account, $branch->id, $this->search)->limit(50)->get()
        ->map(fn ($product) => $catalogue->payload($product, $account, $branch));
});
?>
<div class="mx-auto max-w-3xl space-y-5">
    <x-page-header :title="__('portal.products')" :description="__('portal.catalogue_description')" />
    <label class="block"><span class="sr-only">{{ __('portal.search') }}</span><input type="search" wire:model.live.debounce.300ms="search" placeholder="{{ __('portal.search') }}" class="min-h-14 w-full rounded-2xl border-slate-200 px-4 dark:border-slate-700 dark:bg-slate-900"></label>
    <a href="{{ route('customer.purchase-requests.create') }}" wire:navigate class="block rounded-2xl bg-build-orange px-4 py-4 text-center font-black text-white"><span class="inline-flex items-center gap-2">
        <span>🛒</span>
        <span>{{ app()->getLocale() === 'sw' ? 'Chagua Bidhaa na Uombe Bei / Agiza' : 'Select Products & Request a Quote / Order' }}</span>
    </span></a>
    <div class="sticky top-2 z-10 rounded-2xl border border-orange-200 bg-white p-4 shadow-lg dark:border-slate-700 dark:bg-slate-900">
        <div class="mb-3 flex items-center justify-between">
            <span class="font-black">
                {{ app()->getLocale() === 'sw' ? 'Bidhaa Ulizochagua' : 'Selected Products' }}
            </span>
            <span class="rounded-full bg-build-orange px-3 py-1 text-sm font-black text-white">
                {{ count($selectedProducts) }}
            </span>
        </div>

        <button type="button"
            wire:click="continueRequest"
            wire:loading.attr="disabled"
            @disabled(count($selectedProducts) === 0)
            class="w-full rounded-xl bg-build-orange px-4 py-3 font-black text-white disabled:cursor-not-allowed disabled:opacity-50">
            {{ app()->getLocale() === 'sw' ? 'Endelea na Ombi →' : 'Continue Request →' }}
        </button>
    </div>

    <div class="space-y-3">
        @forelse($this->products as $product)
            <article wire:key="catalogue-{{ $product['id'] }}" class="flex items-center gap-4 rounded-2xl border border-slate-200 bg-white p-4 dark:border-slate-700 dark:bg-slate-900">
                <img src="{{ $product['image_url'] }}" alt="" loading="lazy" class="h-16 w-16 rounded-xl object-cover">
                <div class="min-w-0 flex-1"><h2 class="font-black">{{ $product['name'] }}</h2><p class="mt-1 text-sm text-slate-500">{{ $product['sku'] }} · {{ $product['unit'] }} · {{ $product['category'] }}</p>
                    @if(array_key_exists('selling_price', $product) && $product['selling_price'] !== null)
                        <p class="mt-2 font-black text-build-orange">{{ \App\Models\Company::current()?->currency ?: 'TZS' }} {{ \App\Support\NumberFormatter::money($product['selling_price']) }}</p>
                    @else
                        <p class="mt-2 text-sm text-slate-500">{{ __('portal.prices_private') }}</p>
                    @endif
                </div>
                <button type="button"
                    wire:click="toggleProduct({{ $product['id'] }})"
                    class="shrink-0 rounded-xl px-3 py-3 text-center text-xs font-black
                        {{ in_array((int) $product['id'], array_map('intval', $selectedProducts), true)
                            ? 'bg-green-100 text-green-700'
                            : 'bg-build-orange text-white' }}">
                    {{ in_array((int) $product['id'], array_map('intval', $selectedProducts), true)
                        ? (app()->getLocale() === 'sw' ? '✓ Imechaguliwa' : '✓ Selected')
                        : (app()->getLocale() === 'sw' ? '+ Chagua' : '+ Select') }}
                </button>
            </article>
        @empty
            <p class="rounded-2xl bg-white p-8 text-center dark:bg-slate-900">{{ __('portal.empty') }}</p>
        @endforelse
    </div>
</div>
