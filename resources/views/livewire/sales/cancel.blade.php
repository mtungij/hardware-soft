<?php

use App\Models\Sale;
use App\Services\InventoryService;
use App\Support\AuthorizationScope;

use function Livewire\Volt\layout;
use function Livewire\Volt\mount;
use function Livewire\Volt\state;

layout('layouts.app');

state(['sale' => null]);

mount(function (Sale $sale) {
    AuthorizationScope::authorizeSale(auth()->user(), $sale);
    abort_unless(auth()->user()->can('sales.cancel'), 403);
    $this->sale = $sale->load(['customer', 'items.product']);
});

$cancelSale = function (InventoryService $inventory) {
    abort_unless(auth()->user()->can('sales.cancel'), 403);
    $inventory->cancelSale($this->sale->id, auth()->id());

    session()->flash('success', __('application.sale_cancelled_and_stock_returned_successfully_28d966d9'));
    $this->redirectRoute('sales.index', navigate: true);
};

?>

<div>
    <x-page-header title="{{ __('application.cancel_sale_b849fe97') }}" :description="$sale->sale_number" :breadcrumbs="['Dashboard' => route('dashboard'), 'Sales' => route('sales.index'), $sale->sale_number => route('sales.show', $sale), 'Cancel' => null]" />

    <x-card class="max-w-2xl">
        <div class="rounded-lg bg-red-50 p-4 text-sm text-red-700 dark:bg-red-500/10 dark:text-red-300">
            {{ __('application.cancelling_this_sale_will_create_return_stock_movements_for_every_sold_item_6f2eb508') }}
        </div>

        <div class="mt-5 space-y-2 text-sm">
            <div class="flex justify-between"><span class="text-slate-500">{{ __('application.customer_0e85749a') }}</span><span class="font-bold">{{ $sale->customer?->displayName() ?? __('application.walk_in_customer_90cd7712') }}</span></div>
            <div class="flex justify-between"><span class="text-slate-500">{{ __('application.total_b25928c6') }}</span><span class="font-bold">TZS {{ \App\Support\NumberFormatter::money($sale->total_amount) }}</span></div>
            <div class="flex justify-between"><span class="text-slate-500">{{ __('application.balance_90eef613') }}</span><span class="font-bold">TZS {{ \App\Support\NumberFormatter::money($sale->balance_amount) }}</span></div>
            <div class="flex justify-between"><span class="text-slate-500">{{ __('application.items_44d25b5d') }}</span><span class="font-bold">{{ $sale->items->count() }}</span></div>
            <div class="flex justify-between"><span class="text-slate-500">{{ __('application.status_bae7d5be') }}</span><span class="font-bold">{{ \App\Support\UiText::status($sale->status) }}</span></div>
        </div>

        <div class="mt-6 flex flex-wrap gap-3">
            <a href="{{ route('sales.show', $sale) }}" wire:navigate class="rounded-lg border border-slate-200 px-4 py-2 text-sm font-bold dark:border-slate-700">{{ __('application.keep_sale_44c38fb9') }}</a>
            @if ($sale->status === 'completed')
                <button wire:click="cancelSale" wire:confirm="{{ __('application.cancel_this_sale_and_return_stock_be61bf11') }}" class="rounded-lg bg-red-600 px-4 py-2 text-sm font-bold text-white">{{ __('application.confirm_cancel_sale_77c2a746') }}</button>
            @endif
        </div>
    </x-card>
</div>
