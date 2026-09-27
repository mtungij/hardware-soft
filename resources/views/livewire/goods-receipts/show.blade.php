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
    abort_unless(auth()->user()?->hasAnyRole(['Super Admin', 'Admin', 'Manager', 'Store Keeper']), 403);

    try {
        $inventory->postGoodsReceipt($this->receipt, auth()->id());
        $this->receipt = $this->receipt->refresh()->load(['branch', 'purchase.supplier', 'receiver', 'postedBy', 'items.product', 'items.purchaseUnit', 'items.stockUnit', 'items.stockLocation', 'additionalCosts']);
        session()->flash('success', 'Goods receipt posted.');
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

    <x-page-header title="Goods Receipt" description="{{ $receipt->grn_number }}" :breadcrumbs="['Dashboard' => route('dashboard'), 'Purchases' => route('purchases.index'), $receipt->purchase?->reference_number => route('purchases.show', $receipt->purchase), $receipt->grn_number => null]">
        @if ($receipt->status === 'draft' && auth()->user()?->hasAnyRole(['Super Admin', 'Admin', 'Manager', 'Store Keeper']))<button type="button" wire:click="postReceipt" wire:confirm="Post this receipt to inventory?" class="rounded-xl bg-build-orange px-4 py-2.5 text-sm font-black text-white">Post Inventory</button>@endif
        <a href="{{ route('purchases.show', $receipt->purchase) }}" wire:navigate class="rounded-xl border border-slate-200 px-4 py-2.5 text-sm font-black dark:border-slate-700">Back</a>
    </x-page-header>

    @error('receipt') <p class="my-3 text-sm font-bold text-red-600">{{ $message }}</p> @enderror
    <div class="grid gap-6 xl:grid-cols-3">
        <x-card title="Receipt Summary">
            <dl class="space-y-3 text-sm">
                <div class="flex justify-between gap-4"><dt class="text-slate-500">Receipt Number</dt><dd class="font-bold">{{ $receipt->grn_number }}</dd></div>
                <div class="flex justify-between gap-4"><dt class="text-slate-500">Purchase Number</dt><dd>{{ $receipt->purchase?->reference_number }}</dd></div>
                <div class="flex justify-between gap-4"><dt class="text-slate-500">Supplier</dt><dd>{{ $receipt->purchase?->supplier?->name }}</dd></div>
                <div class="flex justify-between gap-4"><dt class="text-slate-500">Delivery Note</dt><dd>{{ $receipt->supplier_delivery_note_number ?: '-' }}</dd></div>
                <div class="flex justify-between gap-4"><dt class="text-slate-500">Supplier Invoice</dt><dd>{{ $receipt->supplier_invoice_number ?: '-' }}</dd></div>
                <div class="flex justify-between gap-4"><dt class="text-slate-500">Receiving Date</dt><dd>{{ $receipt->received_date?->format('d M Y') }}</dd></div>
                <div class="flex justify-between gap-4"><dt class="text-slate-500">Received By</dt><dd>{{ $receipt->receiver?->name }}</dd></div>
                <div class="flex justify-between gap-4"><dt class="text-slate-500">Posted By</dt><dd>{{ $receipt->postedBy?->name ?? '-' }}</dd></div>
                <div class="flex justify-between gap-4"><dt class="text-slate-500">Status</dt><dd><span class="{{ $receipt->status === 'posted' ? 'badge-success' : ($receipt->status === 'cancelled' ? 'rounded-full bg-red-100 px-2.5 py-1 text-xs font-black text-red-700 dark:bg-red-500/15 dark:text-red-300' : 'badge-warning') }}">{{ ucfirst($receipt->status ?? 'posted') }}</span></dd></div>
                <div class="flex justify-between gap-4"><dt class="text-slate-500">Total Quantity</dt><dd class="font-black">{{ \App\Support\NumberFormatter::quantity($totalQuantity) }}</dd></div>
                <div class="flex justify-between gap-4"><dt class="text-slate-500">Goods Value</dt><dd>TZS {{ \App\Support\NumberFormatter::money($goodsValue) }}</dd></div>
                <div class="flex justify-between gap-4"><dt class="text-slate-500">Additional Costs</dt><dd>TZS {{ \App\Support\NumberFormatter::money($additionalCost) }}</dd></div>
                <div class="flex justify-between gap-4"><dt class="text-slate-500">Total Landed Cost</dt><dd class="font-black">TZS {{ \App\Support\NumberFormatter::money($totalCost) }}</dd></div>
                <div class="flex justify-between gap-4"><dt class="text-slate-500">Locations</dt><dd>{{ $locations->join(', ') ?: '-' }}</dd></div>
            </dl>
        </x-card>

        <x-card title="Received Products" class="xl:col-span-2">
            <x-table :headers="['Product', 'SKU', 'Ordered', 'Previous', 'Received Now / Stock Increase', 'Supplier Unit Cost', 'Supplier Line', 'Allocated Cost', 'Landed Line', 'Landed Unit', 'Landed Base Unit', 'Receiving Location', 'Batch', 'Expiry']">
                @foreach ($receipt->items as $item)
                    <tr>
                        <td class="px-4 py-3 font-black">
                            {{ $item->product?->displayName() }}
                            @if ($item->sizeLabel())
                                <p class="text-xs font-bold text-cyan-700 dark:text-cyan-200">Size: {{ $item->sizeLabel() }}</p>
                            @endif
                        </td>
                        <td class="px-4 py-3 font-mono">{{ $item->product?->sku }}</td>
                        <td class="px-4 py-3">{{ \App\Support\NumberFormatter::quantity($item->ordered_quantity) }} {{ ($item->purchase_unit_code_snapshot ?: $item->purchaseUnit?->short_name) }}</td>
                        <td class="px-4 py-3">{{ \App\Support\NumberFormatter::quantity($item->previously_received_quantity) }} {{ ($item->purchase_unit_code_snapshot ?: $item->purchaseUnit?->short_name) }}</td>
                        <td class="px-4 py-3 font-bold">
                            {{ \App\Support\NumberFormatter::quantity($item->received_quantity) }} {{ ($item->purchase_unit_code_snapshot ?: $item->purchaseUnit?->short_name) }}
                            <span class="block text-xs text-slate-500">Stock +{{ \App\Support\NumberFormatter::quantity($item->stock_quantity) }} {{ ($item->stock_unit_code_snapshot ?: $item->stockUnit?->short_name) }}</span>
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
                        <td class="px-4 py-3">{{ $item->expiry_date?->format('d M Y') ?? '-' }}</td>
                    </tr>
                @endforeach
            </x-table>
        </x-card>
    </div>

    <x-card title="Additional / Landed Costs" class="mt-6">
        <x-table :headers="['Cost Type', 'Amount', 'Paid To / Payee', 'Payment Method', 'Payment Reference', 'Notes']">
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
                <tr><td colspan="6" class="px-4 py-4 text-center text-slate-500">No additional costs on this receipt.</td></tr>
            @endforelse
        </x-table>
        <dl class="mt-4 space-y-1 border-t border-slate-200 pt-3 text-sm dark:border-slate-700">
            @foreach ($receipt->additionalCosts->groupBy('cost_type_name_snapshot') as $typeName => $rows)
                <div class="flex justify-between gap-3"><dt>{{ $typeName }}</dt><dd>TZS {{ \App\Support\NumberFormatter::money($rows->sum('amount')) }}</dd></div>
            @endforeach
            <div class="flex justify-between gap-3 font-bold"><dt>Total Additional Costs</dt><dd>TZS {{ \App\Support\NumberFormatter::money($additionalCost) }}</dd></div>
            <div class="flex justify-between gap-3 font-black"><dt>Total Landed Cost</dt><dd>TZS {{ \App\Support\NumberFormatter::money($totalCost) }}</dd></div>
        </dl>
        <p class="mt-3 text-sm font-bold">Supplier payable is TZS {{ \App\Support\NumberFormatter::money($receipt->purchase?->total_amount) }} for the purchase order. Additional costs are recorded separately.</p>
    </x-card>

    @if ($receipt->notes)
        <x-card title="Notes" class="mt-6">
            <p class="text-sm text-slate-600 dark:text-slate-300">{{ $receipt->notes }}</p>
        </x-card>
    @endif
</div>
