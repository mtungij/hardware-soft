<?php

use App\Models\GoodsReceivingNote;
use App\Services\InventoryService;
use Illuminate\Validation\ValidationException;
use App\Support\InventorySettings;

use function Livewire\Volt\layout;
use function Livewire\Volt\mount;
use function Livewire\Volt\state;

layout('layouts.app');

state(['receipt' => null]);

mount(function (GoodsReceivingNote $receipt) {
    $this->receipt = $receipt->load([
        'branch',
        'purchase.supplier',
        'receiver',
        'postedBy',
        'items.product',
        'items.purchaseUnit',
        'items.stockUnit',
        'items.stockLocation',
        'additionalCosts',
    ]);
});

$postReceipt = function (InventoryService $inventory): void {
    abort_unless(auth()->user()?->hasAnyRole(['Super Admin', __('application.admin_4e7afebc'), __('application.manager_babe3050'), __('application.store_keeper_3fe47e4a')]), 403);

    try {
        $inventory->postGoodsReceipt($this->receipt, auth()->id());
        $this->receipt = $this->receipt->refresh()->load(['branch', 'purchase.supplier', 'receiver', 'postedBy', 'items.product', 'items.purchaseUnit', 'items.stockUnit', 'items.stockLocation', 'additionalCosts']);
        session()->flash('success', __('application.goods_receipt_posted_0e922d99'));
    } catch (ValidationException $exception) {
        $this->addError('receipt', $exception->validator->errors()->first());
    }
};

?>

<div>
    @php
        $totalQuantity = $receipt->items->sum('received_quantity');
        $goodsValue = (float) ($receipt->goods_value ?? $receipt->items->sum(fn ($item) => (float) ($item->total_cost ?: ((float) $item->received_quantity * (float) $item->cost_price))));
        $additionalCost = (float) ($receipt->additional_cost_total ?? 0);
        $totalCost = (float) ($receipt->landed_total ?? $goodsValue);
        $locations = $receipt->items->map(fn ($item) => $item->stockLocation?->name)->filter()->unique();
    @endphp

    <x-page-header title="{{ __('application.goods_receipt_c60a3196') }}" description="{{ $receipt->grn_number }}" :breadcrumbs="['Dashboard' => route('dashboard'), 'Purchases' => route('purchases.index'), $receipt->purchase?->reference_number => route('purchases.show', $receipt->purchase), $receipt->grn_number => null]">
        @if ($receipt->status === 'draft' && auth()->user()?->hasAnyRole(['Super Admin', 'Admin', 'Manager', 'Store Keeper']))<button type="button" wire:click="postReceipt" wire:confirm="{{ __('application.post_this_receipt_to_inventory_271c0732') }}" class="rounded-xl bg-build-orange px-4 py-2.5 text-sm font-black text-white">{{ __('application.post_inventory_32dc9acf') }}</button>@endif
        <a href="{{ route('purchases.show', $receipt->purchase) }}" wire:navigate class="rounded-xl border border-slate-200 px-4 py-2.5 text-sm font-black dark:border-slate-700">{{ __('application.back_b52b36b7') }}</a>
    </x-page-header>

    @error('receipt') <p class="my-3 text-sm font-bold text-red-600">{{ $message }}</p> @enderror
    <div class="grid gap-6 xl:grid-cols-3">
        <x-card title="{{ __('application.receipt_summary_bfdc02bd') }}">
            <dl class="space-y-3 text-sm">
                <div class="flex justify-between gap-4"><dt class="text-slate-500">{{ __('application.receipt_number_37553f8e') }}</dt><dd class="font-bold">{{ $receipt->grn_number }}</dd></div>
                <div class="flex justify-between gap-4"><dt class="text-slate-500">{{ __('application.purchase_number_bef19154') }}</dt><dd>{{ $receipt->purchase?->reference_number }}</dd></div>
                <div class="flex justify-between gap-4"><dt class="text-slate-500">{{ __('application.supplier_55edd462') }}</dt><dd>{{ $receipt->purchase?->supplier?->name }}</dd></div>
                <div class="flex justify-between gap-4"><dt class="text-slate-500">{{ __('application.delivery_note_da6ce2cc') }}</dt><dd>{{ $receipt->supplier_delivery_note_number ?: '-' }}</dd></div>
                <div class="flex justify-between gap-4"><dt class="text-slate-500">{{ __('application.supplier_invoice_dedacf19') }}</dt><dd>{{ $receipt->supplier_invoice_number ?: '-' }}</dd></div>
                <div class="flex justify-between gap-4"><dt class="text-slate-500">{{ __('application.receiving_date_64d0be44') }}</dt><dd>{{ $receipt->received_date?->locale(app()->getLocale())->translatedFormat('d M Y') }}</dd></div>
                <div class="flex justify-between gap-4"><dt class="text-slate-500">{{ __('application.received_by_f84f73c6') }}</dt><dd>{{ $receipt->receiver?->name }}</dd></div>
                <div class="flex justify-between gap-4"><dt class="text-slate-500">{{ __('application.posted_by_ac421e3f') }}</dt><dd>{{ $receipt->postedBy?->name ?? '-' }}</dd></div>
                <div class="flex justify-between gap-4"><dt class="text-slate-500">{{ __('application.status_bae7d5be') }}</dt><dd><span class="{{ $receipt->status === 'posted' ? 'badge-success' : ($receipt->status === 'cancelled' ? 'rounded-full bg-red-100 px-2.5 py-1 text-xs font-black text-red-700 dark:bg-red-500/15 dark:text-red-300' : 'badge-warning') }}">{{ \App\Support\UiText::label($receipt->status ?? 'posted') }}</span></dd></div>
                <div class="flex justify-between gap-4"><dt class="text-slate-500">{{ __('application.total_quantity_c06411e1') }}</dt><dd class="font-black">{{ \App\Support\NumberFormatter::quantity($totalQuantity) }}</dd></div>
                <div class="flex justify-between gap-4"><dt class="text-slate-500">{{ __('application.goods_value_e621e6b5') }}</dt><dd>TZS {{ \App\Support\NumberFormatter::money($goodsValue) }}</dd></div>
                <div class="flex justify-between gap-4"><dt class="text-slate-500">{{ __('application.additional_costs_9c8e58f4') }}</dt><dd>TZS {{ \App\Support\NumberFormatter::money($additionalCost) }}</dd></div>
                <div class="flex justify-between gap-4"><dt class="text-slate-500">{{ __('application.total_landed_cost_931201f0') }}</dt><dd class="font-black">TZS {{ \App\Support\NumberFormatter::money($totalCost) }}</dd></div>
                <div class="flex justify-between gap-4"><dt class="text-slate-500">{{ __('application.locations_e59e8f15') }}</dt><dd>{{ $locations->join(', ') ?: '-' }}</dd></div>
            </dl>
        </x-card>

        <x-card title="{{ __('application.received_products_8355b982') }}" class="xl:col-span-2">
            <x-table :headers="[__('application.product_dd3b86d1'), __('application.sku_d59192b9'), __('application.ordered_c9dd3b77'), __('application.previous_50f94286'), __('terminology.received_now_stock_increase_0ad2f5be'), __('application.supplier_unit_cost_cb1c1045'), __('terminology.supplier_line_b43524c1'), __('terminology.allocated_cost_94724b31'), __('terminology.landed_line_c3f1ebc1'), __('terminology.landed_unit_f1b0a832'), __('terminology.landed_base_unit_1282f222'), __('terminology.receiving_location_4db8c7a7'), __('application.batch_8bf1ef56'), __('terminology.expiry_ba8f571e')]">
                @foreach ($receipt->items as $item)
                    <tr>
                        <td class="px-4 py-3 font-black">
                            {{ $item->product?->displayName() }}
                            @if ($item->sizeLabel())
                                <p class="text-xs font-bold text-cyan-700 dark:text-cyan-200">{{ __('application.size_86251551') }} {{ $item->sizeLabel() }}</p>
                            @endif
                        </td>
                        <td class="px-4 py-3 font-mono">{{ $item->product?->sku }}</td>
                        <td class="px-4 py-3">{{ \App\Support\NumberFormatter::quantity($item->ordered_quantity) }} {{ ($item->purchase_unit_code_snapshot ?: $item->purchaseUnit?->short_name) }}</td>
                        <td class="px-4 py-3">{{ \App\Support\NumberFormatter::quantity($item->previously_received_quantity) }} {{ ($item->purchase_unit_code_snapshot ?: $item->purchaseUnit?->short_name) }}</td>
                        <td class="px-4 py-3 font-bold">
                            {{ \App\Support\NumberFormatter::quantity($item->received_quantity) }} {{ ($item->purchase_unit_code_snapshot ?: $item->purchaseUnit?->short_name) }}
                            <span class="block text-xs text-slate-500">{{ __('application.stock_038bc5af') }}{{ \App\Support\NumberFormatter::quantity($item->stock_quantity) }} {{ ($item->stock_unit_code_snapshot ?: $item->stockUnit?->short_name) }}</span>
                            <span class="block text-xs text-slate-500">1 {{ $item->purchase_unit_code_snapshot ?: $item->purchaseUnit?->short_name }} = {{ \App\Support\NumberFormatter::quantity($item->conversion_factor_snapshot) }} {{ $item->stock_unit_code_snapshot ?: $item->stockUnit?->short_name }}</span>
                        </td>
                        <td class="px-4 py-3">TZS {{ \App\Support\NumberFormatter::money(($item->unit_cost ?: $item->cost_price)) }} / {{ ($item->purchase_unit_code_snapshot ?: $item->purchaseUnit?->short_name) }}</td>
                        <td class="px-4 py-3">TZS {{ \App\Support\NumberFormatter::money($item->supplier_line_cost ?? $item->total_cost ?? ((float) $item->received_quantity * (float) $item->cost_price)) }}</td>
                        <td class="px-4 py-3">TZS {{ \App\Support\NumberFormatter::money($item->allocated_additional_cost ?? 0) }}</td>
                        <td class="px-4 py-3">TZS {{ \App\Support\NumberFormatter::money($item->landed_line_cost ?? $item->total_cost ?? ((float) $item->received_quantity * (float) $item->cost_price)) }}</td>
                        <td class="px-4 py-3">TZS {{ \App\Support\NumberFormatter::money($item->landed_unit_cost ?? $item->cost_price) }}</td>
                        <td class="px-4 py-3">TZS {{ \App\Support\NumberFormatter::money($item->landed_base_unit_cost ?? ((float) $item->cost_price / max(0.0001, (float) $item->conversion_factor_snapshot))) }}</td>
                        <td class="px-4 py-3">{{ $item->stockLocation ? InventorySettings::stockLocationLabel($item->stockLocation) : '-' }}</td>
                        <td class="px-4 py-3">{{ $item->batch_number ?: '-' }}</td>
                        <td class="px-4 py-3">{{ $item->expiry_date?->locale(app()->getLocale())->translatedFormat('d M Y') ?? '-' }}</td>
                    </tr>
                @endforeach
            </x-table>
        </x-card>
    </div>

    <x-card title="{{ __('application.additional_landed_costs_6329229b') }}" class="mt-6">
        <x-table :headers="[__('application.cost_type_64e3d203'), __('application.amount_43dc8532'), __('application.paid_to_payee_6ff4ebdf'), __('application.payment_method_f383f6a2'), __('application.payment_reference_80e69501'), __('application.notes_70440046')]">
            @forelse ($receipt->additionalCosts as $cost)
                <tr>
                    <td class="px-4 py-3">{{ $cost->cost_type_name_snapshot }}</td>
                    <td class="px-4 py-3">TZS {{ \App\Support\NumberFormatter::money($cost->amount) }}</td>
                    <td class="px-4 py-3">{{ $cost->payee ?: '-' }}</td>
                    <td class="px-4 py-3">{{ $cost->payment_method ?: '-' }}</td>
                    <td class="px-4 py-3">{{ $cost->payment_reference ?: '-' }}</td>
                    <td class="px-4 py-3">{{ $cost->notes ?: '-' }}</td>
                </tr>
            @empty
                <tr><td colspan="6" class="px-4 py-4 text-center text-slate-500">{{ __('application.no_additional_costs_on_this_receipt_010e822b') }}</td></tr>
            @endforelse
        </x-table>
        <dl class="mt-4 space-y-1 border-t border-slate-200 pt-3 text-sm dark:border-slate-700">
            @foreach ($receipt->additionalCosts->groupBy('cost_type_name_snapshot') as $typeName => $rows)
                <div class="flex justify-between gap-3"><dt>{{ $typeName }}</dt><dd>TZS {{ \App\Support\NumberFormatter::money($rows->sum('amount')) }}</dd></div>
            @endforeach
            <div class="flex justify-between gap-3 font-bold"><dt>{{ __('application.total_additional_costs_d65ea4ed') }}</dt><dd>TZS {{ \App\Support\NumberFormatter::money($additionalCost) }}</dd></div>
            <div class="flex justify-between gap-3 font-black"><dt>{{ __('application.total_landed_cost_931201f0') }}</dt><dd>TZS {{ \App\Support\NumberFormatter::money($totalCost) }}</dd></div>
        </dl>
        <p class="mt-3 text-sm font-bold">{{ __('application.supplier_payable_is_tzs_15bfc6b5') }} {{ \App\Support\NumberFormatter::money($receipt->purchase?->total_amount) }} {{ __('application.for_the_purchase_order_additional_costs_are_recorded_separately_fdc15bb4') }}</p>
    </x-card>

    @if ($receipt->notes)
        <x-card title="{{ __('application.notes_70440046') }}" class="mt-6">
            <p class="text-sm text-slate-600 dark:text-slate-300">{{ $receipt->notes }}</p>
        </x-card>
    @endif
</div>
