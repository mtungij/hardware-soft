<?php

use App\Models\Product;
use App\Models\StockLocation;
use App\Models\StockMovement;
use Livewire\WithPagination;

use function Livewire\Volt\layout;
use function Livewire\Volt\state;
use function Livewire\Volt\uses;

layout('layouts.app');
uses([WithPagination::class]);

state(['productFilter' => '', 'locationFilter' => '', 'typeFilter' => '', 'dateFrom' => '', 'dateTo' => '']);

?>

<div>
    <x-page-header title="Stock Movements" description="Immutable inventory ledger. Stock movement rows are not deleted." :breadcrumbs="['Dashboard' => route('dashboard'), 'Stock Movements' => null]" />

    <x-card>
        <div class="mb-4 grid gap-3 md:grid-cols-5">
            <select wire:model.live="productFilter" class="rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm dark:border-slate-700 dark:bg-navy-950">
                <option value="">All products</option>
                @foreach (Product::orderBy('name')->get() as $product)
                    <option value="{{ $product->id }}">{{ $product->name }}</option>
                @endforeach
            </select>
            <select wire:model.live="locationFilter" class="rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm dark:border-slate-700 dark:bg-navy-950">
                <option value="">All locations</option>
                @foreach (StockLocation::orderBy('name')->get() as $location)
                    <option value="{{ $location->id }}">{{ $location->name }}</option>
                @endforeach
            </select>
            <select wire:model.live="typeFilter" class="rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm dark:border-slate-700 dark:bg-navy-950">
                <option value="">All types</option>
                @foreach (['purchase_in', 'transfer_in', 'transfer_out', 'sale_out', 'adjustment_in', 'adjustment_out', 'damage_out', 'return_in'] as $type)
                    <option value="{{ $type }}">{{ $type }}</option>
                @endforeach
            </select>
            <input wire:model.live="dateFrom" type="date" class="rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm dark:border-slate-700 dark:bg-navy-950">
            <input wire:model.live="dateTo" type="date" class="rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm dark:border-slate-700 dark:bg-navy-950">
        </div>

        @php
            $movements = StockMovement::query()
                ->with(['product', 'stockLocation', 'branch', 'creator'])
                ->when($productFilter, fn ($query) => $query->where('product_id', $productFilter))
                ->when($locationFilter, fn ($query) => $query->where('stock_location_id', $locationFilter))
                ->when($typeFilter, fn ($query) => $query->where('movement_type', $typeFilter))
                ->when($dateFrom, fn ($query) => $query->whereDate('movement_date', '>=', $dateFrom))
                ->when($dateTo, fn ($query) => $query->whereDate('movement_date', '<=', $dateTo))
                ->latest('movement_date')
                ->paginate(15);
        @endphp

        <x-table :headers="['Date', 'Product', 'Location', 'Type', 'Qty In', 'Qty Out', 'Cost', 'Reference', 'Created By']">
            @forelse ($movements as $movement)
                <tr class="hover:bg-slate-50 dark:hover:bg-white/5">
                    <td class="px-4 py-3">{{ $movement->movement_date->format('d M Y') }}</td>
                    <td class="px-4 py-3 font-bold">{{ $movement->product?->name }}</td>
                    <td class="px-4 py-3">{{ $movement->stockLocation?->name }}</td>
                    <td class="px-4 py-3"><span class="badge-info">{{ $movement->movement_type }}</span></td>
                    <td class="px-4 py-3 text-emerald-700">{{ in_array($movement->movement_type, \App\Models\StockMovement::POSITIVE_TYPES, true) ? number_format((float) $movement->quantity, 2) : '-' }}</td>
                    <td class="px-4 py-3 text-red-700">{{ in_array($movement->movement_type, \App\Models\StockMovement::NEGATIVE_TYPES, true) ? number_format((float) $movement->quantity, 2) : '-' }}</td>
                    <td class="px-4 py-3">TZS {{ number_format((float) $movement->unit_cost, 2) }}</td>
                    <td class="px-4 py-3 text-xs">{{ class_basename($movement->reference_type) }} #{{ $movement->reference_id }}</td>
                    <td class="px-4 py-3">{{ $movement->creator?->name }}</td>
                </tr>
            @empty
                <tr><td colspan="9" class="px-4 py-8 text-center text-slate-500">No stock movements found.</td></tr>
            @endforelse
        </x-table>

        <div class="mt-4">{{ $movements->links() }}</div>
    </x-card>
</div>
