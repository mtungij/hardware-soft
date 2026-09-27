<?php

use App\Models\InternalSale;
use App\Models\StockMovement;
use App\Services\InternalSaleService;
use App\Support\AuthorizationScope;

use function Livewire\Volt\layout;
use function Livewire\Volt\mount;
use function Livewire\Volt\state;

layout('layouts.app');
state(['saleId' => null]);

mount(function (InternalSale $internalSale) {
    abort_unless((int) $internalSale->company_id === (int) auth()->user()->company_id
        && (AuthorizationScope::canAccessStockLocation(auth()->user(), (int) $internalSale->from_location_id)
            || AuthorizationScope::canAccessStockLocation(auth()->user(), (int) $internalSale->to_location_id)), 403);
    $this->saleId = $internalSale->id;
});

$complete = function (InternalSaleService $service): void {
    $sale = InternalSale::findOrFail($this->saleId);
    $service->complete($sale, auth()->user());
    session()->flash('success', 'Internal Sale completed.');
};

$cancel = function (InternalSaleService $service): void {
    $sale = InternalSale::findOrFail($this->saleId);
    $service->cancelDraft($sale, auth()->user());
    session()->flash('success', 'Internal Sale cancelled.');
};

?>

<div>
    @php
        $sale = InternalSale::with(['items.product', 'fromLocation', 'toLocation', 'branch', 'creator'])->findOrFail($saleId);
        $canViewCost = auth()->user()->can('internal_sales.view_cost');
        $canViewMargin = auth()->user()->can('internal_sales.view_margin') && AuthorizationScope::canAccessStockLocation(auth()->user(), (int) $sale->from_location_id);
        $canManageBoth = AuthorizationScope::canAccessStockLocation(auth()->user(), (int) $sale->from_location_id) && AuthorizationScope::canAccessStockLocation(auth()->user(), (int) $sale->to_location_id);
    @endphp
    <x-page-header title="Internal Sale {{ $sale->internal_sale_number }}" description="Internal value is informational and is excluded from company revenue." :breadcrumbs="['Dashboard' => route('dashboard'), 'Internal Sales' => route('internal-sales.index'), $sale->internal_sale_number => null]">
        @if ($sale->status === 'draft' && $canManageBoth && auth()->user()->can('internal_sales.create'))
            <a href="{{ route('internal-sales.edit', $sale) }}" wire:navigate class="rounded-xl border px-4 py-2">Edit draft</a>
        @endif
        @if($sale->status === 'completed' && auth()->user()->can('internal_sales.print_delivery_note'))
            <a href="{{ route('internal-sales.delivery-note.print', $sale) }}" target="_blank" rel="noopener" class="rounded-xl border px-4 py-2">Print Delivery Note</a>
            <a href="{{ route('internal-sales.delivery-note.pdf', $sale) }}" class="rounded-xl border px-4 py-2">Delivery PDF</a>
        @endif
        @if($sale->status === 'completed' && auth()->user()->can('internal_sales.print_value_note'))
            <a href="{{ route('internal-sales.value-note.print', $sale) }}" target="_blank" rel="noopener" class="rounded-xl border px-4 py-2">Print Internal Sale Value Note</a>
        @endif
        <a href="{{ route('internal-sales.index') }}" wire:navigate class="rounded-xl border px-4 py-2">Back</a>
    </x-page-header>
    <x-card>
        <div class="grid gap-3 md:grid-cols-3 text-sm">
            <p><b>Status:</b> {{ ucfirst($sale->status) }}</p><p><b>Date:</b> {{ $sale->sale_date?->format('Y-m-d') }}</p><p><b>Branch:</b> {{ $sale->branch?->name }}</p>
            <p><b>Source:</b> {{ $sale->fromLocation?->name }}</p><p><b>Destination:</b> {{ $sale->toLocation?->name }}</p><p><b>Created by:</b> {{ $sale->creator?->name }}</p>
        </div>
        @if ($sale->notes)<p class="mt-3 text-sm">{{ $sale->notes }}</p>@endif
        @if ($sale->status === 'draft' && $canManageBoth)
            <div class="mt-4 flex gap-3">
                @can('internal_sales.complete')<button wire:click="complete" wire:confirm="Complete and post stock movements?" class="rounded-lg bg-build-orange px-4 py-2 font-bold text-white">Complete</button>@endcan
                @can('internal_sales.cancel')<button wire:click="cancel" wire:confirm="Cancel this draft?" class="rounded-lg border px-4 py-2 font-bold">Cancel draft</button>@endcan
            </div>
        @endif
    </x-card>
    <x-card class="mt-4"><h3 class="mb-3 font-bold">Items</h3>
        <x-table :headers="array_merge(['Product', 'Transaction qty', 'Base qty', 'Internal unit price', 'Price source', 'Internal value'], $canViewCost ? ['Company unit cost', 'Source acquisition cost', 'Destination acquisition cost'] : [])">
            @foreach ($sale->items as $item)
                <tr>
                    <td class="px-4 py-3">{{ $item->product?->displayName() }}</td>
                    <td class="px-4 py-3 text-right">{{ $item->transaction_quantity }} {{ $item->transaction_unit_code_snapshot }}</td>
                    <td class="px-4 py-3 text-right">{{ $item->base_quantity }}</td>
                    <td class="px-4 py-3 text-right">{{ \App\Support\NumberFormatter::money($item->internal_unit_price) }}</td>
                    <td class="px-4 py-3 text-xs">{{ $item->price_source ?: '—' }}</td>
                    <td class="px-4 py-3 text-right">{{ \App\Support\NumberFormatter::money($item->line_total) }}</td>
                    @if($canViewCost)
                        <td class="px-4 py-3 text-right">{{ $item->company_base_unit_cost === null ? '—' : \App\Support\NumberFormatter::money($item->company_base_unit_cost) }}</td>
                        <td class="px-4 py-3 text-right">{{ $item->source_acquisition_base_unit_cost === null ? '—' : \App\Support\NumberFormatter::money($item->source_acquisition_base_unit_cost) }}</td>
                        <td class="px-4 py-3 text-right">{{ $item->destination_acquisition_base_unit_cost === null ? '—' : \App\Support\NumberFormatter::money($item->destination_acquisition_base_unit_cost) }}</td>
                    @endif
                </tr>
            @endforeach
        </x-table>
        <p class="mt-4 text-right font-bold">Internal value: TZS {{ \App\Support\NumberFormatter::money($sale->total_internal_value) }}</p>
    </x-card>
    @if ($sale->status === 'completed' && $canViewMargin)
        @php
            $sourceCost = $sale->items->sum(fn ($item) => (float) $item->base_quantity * (float) ($item->source_acquisition_base_unit_cost ?? $item->company_base_unit_cost));
            $internalMargin = (float) $sale->total_internal_value - $sourceCost;
        @endphp
        <x-card class="mt-4"><h3 class="mb-3 font-bold">Source Location Performance · Internal Location Margin</h3>
            <x-table :headers="['Product', 'Internal value', 'Source cost', 'Internal gross profit']">
                @foreach($sale->items as $item)
                    @php $lineCost = (float) $item->base_quantity * (float) ($item->source_acquisition_base_unit_cost ?? $item->company_base_unit_cost); @endphp
                    <tr><td class="px-4 py-3">{{ $item->product?->displayName() }}</td><td class="px-4 py-3 text-right">{{ \App\Support\NumberFormatter::money($item->line_total) }}</td><td class="px-4 py-3 text-right">{{ \App\Support\NumberFormatter::money($lineCost) }}</td><td class="px-4 py-3 text-right">{{ \App\Support\NumberFormatter::money((float) $item->line_total - $lineCost) }}</td></tr>
                @endforeach
            </x-table>
            <p class="mt-3 text-right font-bold">Internal Location Margin: TZS {{ \App\Support\NumberFormatter::money($internalMargin) }}</p>
            <p class="text-xs text-slate-500">Analytical location margin. Excluded from consolidated company profit.</p>
        </x-card>
    @endif
    @if ($sale->status === 'completed')
        @php $movements = StockMovement::where('reference_type', InternalSale::class)->where('reference_id', $sale->id)->whereIn('stock_location_id', AuthorizationScope::stockLocationIds(auth()->user()))->orderBy('id')->get(); @endphp
        <x-card class="mt-4"><h3 class="mb-3 font-bold">Stock posting</h3>
            <x-table :headers="array_merge(['Movement', 'Location', 'Base quantity'], $canViewCost ? ['Company unit cost', 'Location acquisition cost'] : [])">
                @foreach ($movements as $movement)
                    <tr><td class="px-4 py-3">{{ $movement->movement_type }}</td><td class="px-4 py-3">{{ $movement->stockLocation?->name }}</td><td class="px-4 py-3 text-right">{{ $movement->signedQuantity() }}</td>@if($canViewCost)<td class="px-4 py-3 text-right">{{ \App\Support\NumberFormatter::money($movement->unit_cost) }}</td><td class="px-4 py-3 text-right">{{ \App\Support\NumberFormatter::money($movement->location_acquisition_unit_cost) }}</td>@endif</tr>
                @endforeach
            </x-table>
        </x-card>
    @endif
</div>
