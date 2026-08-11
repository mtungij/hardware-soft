<?php

use App\Models\Branch;
use App\Models\Sale;
use App\Models\StockLocation;
use App\Support\InventorySettings;
use Livewire\WithPagination;

use function Livewire\Volt\layout;
use function Livewire\Volt\mount;
use function Livewire\Volt\state;
use function Livewire\Volt\uses;

layout('layouts.app');
uses([WithPagination::class]);
state(['branch_id' => '', 'date_from' => '', 'date_to' => '', 'search' => '', 'sale_type' => '', 'stock_location_id' => '']);
mount(function () {
    $this->branch_id = request('branch_id', $this->branch_id);
    $this->date_from = request('date_from', now()->startOfMonth()->toDateString());
    $this->date_to = request('date_to', today()->toDateString());
    $this->search = request('search', $this->search);
    $this->sale_type = request('sale_type', $this->sale_type);
    $this->stock_location_id = request('stock_location_id', $this->stock_location_id);
});

?>

<div>
    <x-page-header title="Sales Report" description="Revenue, payment status, cashier, and customer sales analysis." :breadcrumbs="['Dashboard' => route('dashboard'), 'Reports' => null, 'Sales' => null]">
        <x-export-actions export="reports.sales" :params="compact('branch_id', 'date_from', 'date_to', 'search', 'sale_type', 'stock_location_id')" />
    </x-page-header>
    @php
        $query = Sale::with(['branch', 'customer', 'soldBy', 'createdBy', 'items.stockLocation', 'items.product', 'items.sellingUnit', 'items.baseUnit'])->whereBetween('sale_date', [$date_from, $date_to])->when($branch_id, fn ($q) => $q->where('branch_id', $branch_id))->when($sale_type, fn ($q) => $q->where('sale_type', $sale_type))->when($stock_location_id, fn ($q) => $q->whereHas('items', fn ($itemQuery) => $itemQuery->where('stock_location_id', $stock_location_id)))->when($search, fn ($q) => $q->where('sale_number', 'like', "%{$search}%")->orWhereHas('customer', fn ($c) => $c->where('name', 'like', "%{$search}%")));
        $summary = ['sales' => (clone $query)->count(), 'revenue' => (clone $query)->where('status', 'completed')->sum('total_amount'), 'paid' => (clone $query)->sum('paid_amount'), 'balance' => (clone $query)->sum('balance_amount')];
        $saleItemQuery = \App\Models\SaleItem::query()
            ->whereHas('sale', fn ($saleQuery) => $saleQuery->where('status', 'completed')->whereBetween('sale_date', [$date_from, $date_to])->when($branch_id, fn ($q) => $q->where('branch_id', $branch_id)))
            ->when($stock_location_id, fn ($q) => $q->where('stock_location_id', $stock_location_id));
        $retailItems = (clone $saleItemQuery)->where('sale_type', 'retail')->get();
        $wholesaleItems = (clone $saleItemQuery)->where('sale_type', 'wholesale')->get();
        $retailTotal = $retailItems->sum('line_total');
        $wholesaleTotal = $wholesaleItems->sum('line_total');
        $retailProfit = $retailItems->sum(fn ($item) => (float) $item->line_total - ((float) $item->quantity * (float) $item->unit_cost));
        $wholesaleProfit = $wholesaleItems->sum(fn ($item) => (float) $item->line_total - ((float) $item->quantity * (float) $item->unit_cost));
        $rows = $query->latest()->paginate(15);
    @endphp
    <x-card><div class="grid gap-3 md:grid-cols-6"><select wire:model.live="branch_id" class="rounded-lg border px-3 py-2 text-sm dark:bg-navy-950"><option value="">All branches</option>@foreach (Branch::orderBy('name')->get() as $branch)<option value="{{ $branch->id }}">{{ $branch->name }}</option>@endforeach</select><select wire:model.live="sale_type" class="rounded-lg border px-3 py-2 text-sm dark:bg-navy-950"><option value="">All Sales</option><option value="retail">Retail</option><option value="wholesale">Wholesale</option></select><select wire:model.live="stock_location_id" class="rounded-lg border px-3 py-2 text-sm dark:bg-navy-950"><option value="">All stock locations</option>@foreach (StockLocation::whereIn('type', ['store', 'dispensing'])->orderBy('type')->get() as $location)<option value="{{ $location->id }}">{{ InventorySettings::stockLocationLabel($location) }}</option>@endforeach</select><input wire:model.live="date_from" type="date" class="rounded-lg border px-3 py-2 text-sm dark:bg-navy-950"><input wire:model.live="date_to" type="date" class="rounded-lg border px-3 py-2 text-sm dark:bg-navy-950"><input wire:model.live.debounce.300ms="search" class="rounded-lg border px-3 py-2 text-sm dark:bg-navy-950" placeholder="Search"></div></x-card>
    <div class="mt-4 grid gap-4 sm:grid-cols-4"><x-card><p class="text-sm text-slate-500">Sales</p><p class="text-2xl font-black">{{ $summary['sales'] }}</p></x-card><x-card><p class="text-sm text-slate-500">Revenue</p><p class="text-2xl font-black">TZS {{ \App\Support\NumberFormatter::money($summary['revenue']) }}</p></x-card><x-card><p class="text-sm text-slate-500">Paid</p><p class="text-2xl font-black">TZS {{ \App\Support\NumberFormatter::money($summary['paid']) }}</p></x-card><x-card><p class="text-sm text-slate-500">Balance</p><p class="text-2xl font-black">TZS {{ \App\Support\NumberFormatter::money($summary['balance']) }}</p></x-card></div>
    <div class="mt-4 grid gap-4 sm:grid-cols-4"><x-card><p class="text-sm text-slate-500">Retail Sales Total</p><p class="text-2xl font-black">TZS {{ \App\Support\NumberFormatter::money($retailTotal) }}</p></x-card><x-card><p class="text-sm text-slate-500">Wholesale Sales Total</p><p class="text-2xl font-black">TZS {{ \App\Support\NumberFormatter::money($wholesaleTotal) }}</p></x-card><x-card><p class="text-sm text-slate-500">Retail Profit</p><p class="text-2xl font-black">TZS {{ \App\Support\NumberFormatter::money($retailProfit) }}</p></x-card><x-card><p class="text-sm text-slate-500">Wholesale Profit</p><p class="text-2xl font-black">TZS {{ \App\Support\NumberFormatter::money($wholesaleProfit) }}</p></x-card></div>
    <x-card class="mt-4">
        <x-table :headers="['Sale Number', 'Products / Transaction Qty', 'Base Qty Sold', 'User', 'Stock Location', 'Sale Type', 'Sales Total', 'COGS', 'Gross Profit', 'Date', 'Customer', 'Branch']">
            @foreach ($rows as $sale)
                @php($saleCogs = $sale->items->sum(fn ($item) => $item->base_unit_cost !== null ? (float) $item->base_quantity * (float) $item->base_unit_cost : (float) $item->quantity * (float) $item->unit_cost))
                <tr>
                    <td class="px-4 py-3 font-bold">{{ $sale->sale_number }}</td>
                    <td class="px-4 py-3">@foreach($sale->items as $item)<div>{{ $item->product?->displayName() }}: {{ \App\Support\NumberFormatter::quantity($item->quantity) }} {{ $item->selling_unit_code_snapshot ?: $item->sellingUnit?->short_name }}</div>@endforeach</td>
                    <td class="px-4 py-3">@foreach($sale->items as $item)<div>{{ \App\Support\NumberFormatter::quantity($item->base_quantity ?: $item->quantity) }} {{ $item->base_unit_code_snapshot ?: $item->baseUnit?->short_name }}</div>@endforeach</td>
                    <td class="px-4 py-3">{{ $sale->soldBy?->name ?? $sale->createdBy?->name }}</td><td class="px-4 py-3">{{ $sale->stockLocationLabel() }}</td><td class="px-4 py-3">{{ $sale->saleTypeLabel() }}</td>
                    <td class="px-4 py-3 text-right">TZS {{ \App\Support\NumberFormatter::money($sale->total_amount) }}</td><td class="px-4 py-3 text-right">TZS {{ \App\Support\NumberFormatter::money($saleCogs) }}</td><td class="px-4 py-3 text-right">TZS {{ \App\Support\NumberFormatter::money((float) $sale->total_amount - $saleCogs) }}</td>
                    <td class="px-4 py-3">{{ $sale->sale_date?->format('M d, Y') }}</td><td class="px-4 py-3">{{ $sale->customer?->name ?? 'Walk-in' }}</td><td class="px-4 py-3">{{ $sale->branch?->name }}</td>
                </tr>
            @endforeach
        </x-table>
        <div class="mt-4">{{ $rows->links() }}</div>
    </x-card>
</div>
