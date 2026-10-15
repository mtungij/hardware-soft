<?php

use App\Models\Product;
use Livewire\WithPagination;

use function Livewire\Volt\layout;
use function Livewire\Volt\state;
use function Livewire\Volt\uses;

layout('layouts.app');
uses([WithPagination::class]);

state(['productFilter' => '', 'locationFilter' => '', 'typeFilter' => '', 'dateFrom' => '', 'dateTo' => '', 'branch_id' => (\App\Support\BranchAccess::restricted() ? (string) auth()->user()->branch_id : ''), 'receipts_today' => ''])->url();

?>

<div>
    <x-page-header title="{{ __('application.stock_movements_4a28921b') }}" description="{{ __('application.immutable_inventory_ledger_stock_movement_rows_are_not_deleted_ba647ccd') }}" :breadcrumbs="['Dashboard' => route('dashboard'), 'Stock Movements' => null]">
        <x-export-actions export="tables.stock-movements" :params="['product_id' => $productFilter, 'stock_location_id' => $locationFilter, 'movement_type' => $typeFilter, 'date_from' => $dateFrom, 'date_to' => $dateTo, 'branch_id' => $branch_id, 'receipts_today' => $receipts_today]" />
    </x-page-header>

    @if ($receipts_today)
        <p class="mb-3 text-sm">{{ __('dashboard.stock_received_today_help') }}</p>
    @endif
    <x-card>
        <div class="mb-4 grid gap-3 md:grid-cols-5">
            <select wire:model.live="productFilter" class="rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm dark:border-slate-700 dark:bg-navy-950">
                <option value="">{{ __('application.all_products_3ffece7c') }}</option>
                @foreach (Product::with('size')->orderBy('name')->get() as $product)
                    <option value="{{ $product->id }}">{{ $product->displayNameWithSize() }}</option>
                @endforeach
            </select>
            <select wire:model.live="locationFilter" class="rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm dark:border-slate-700 dark:bg-navy-950">
                <option value="">{{ __('application.all_locations_797a4917') }}</option>
                @foreach (app(\App\Services\FinancialReportService::class)->valuationLocations($branch_id ? (int) $branch_id : null) as $location)
                    <option value="{{ $location->id }}">{{ $location->name }}</option>
                @endforeach
            </select>
            <select wire:model.live="typeFilter" class="rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm dark:border-slate-700 dark:bg-navy-950">
                <option value="">{{ __('application.all_types_30c8a0fc') }}</option>
                @foreach (['opening_stock', 'purchase_in', 'purchase_receipt', 'transfer_in', 'transfer_out', 'sale_out', 'adjustment_in', 'adjustment_out', 'damage_out', 'return_in'] as $type)
                    <option value="{{ $type }}">{{ $type === 'opening_stock' ? __('application.opening_stock_79ec9b63') : $type }}</option>
                @endforeach
            </select>
            <input wire:model.live="dateFrom" type="date" class="rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm dark:border-slate-700 dark:bg-navy-950">
            <input wire:model.live="dateTo" type="date" class="rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm dark:border-slate-700 dark:bg-navy-950">
        </div>

        @php
            $movements = app(\App\Services\FinancialReportService::class)->stockMovementsForLocations($branch_id ? (int) $branch_id : null)
                ->with(['product', 'stockLocation', 'branch', 'creator'])
                ->when($receipts_today, fn ($query) => $query->whereIn('movement_type', ['purchase_in', 'purchase_receipt'])->whereDate('movement_date', today()))
                ->when($productFilter, fn ($query) => $query->where('product_id', $productFilter))
                ->when($locationFilter, fn ($query) => $query->where('stock_location_id', $locationFilter))
                ->when($typeFilter, fn ($query) => $query->where('movement_type', $typeFilter))
                ->when($dateFrom, fn ($query) => $query->whereDate('movement_date', '>=', $dateFrom))
                ->when($dateTo, fn ($query) => $query->whereDate('movement_date', '<=', $dateTo))
                ->latest('movement_date')
                ->paginate(15);
        @endphp

        @php $canViewCost = auth()->user()->can('stock.view_value'); @endphp
        <x-table :headers="$canViewCost ? [__('application.date_eb9a4bc1'), __('application.product_dd3b86d1'), __('application.location_d219c681'), __('application.type_3deb7456'), __('application.qty_in_daf3844b'), __('application.qty_out_6c8d0a9e'), __('application.cost_64ae43e8'), __('application.reference_db1c7845'), __('application.created_by_43de2bcd')] : [__('application.date_eb9a4bc1'), __('application.product_dd3b86d1'), __('application.location_d219c681'), __('application.type_3deb7456'), __('application.qty_in_daf3844b'), __('application.qty_out_6c8d0a9e'), __('application.reference_db1c7845'), __('application.created_by_43de2bcd')]">
            @forelse ($movements as $movement)
                <tr class="hover:bg-slate-50 dark:hover:bg-white/5">
                    <td class="px-4 py-3">{{ $movement->movement_date->locale(app()->getLocale())->translatedFormat('d M Y') }}</td>
                    <td class="px-4 py-3 font-bold">{{ $movement->product?->displayNameWithSize() }}</td>
                    <td class="px-4 py-3">{{ $movement->stockLocation?->name }}</td>
                    <td class="px-4 py-3"><span class="badge-info">{{ $movement->movement_type === 'opening_stock' ? __('application.opening_stock_79ec9b63') : $movement->movement_type }}</span></td>
                    <td class="px-4 py-3 text-emerald-700">{{ in_array($movement->movement_type, \App\Models\StockMovement::POSITIVE_TYPES, true) ? \App\Support\NumberFormatter::quantity($movement->quantity) : '-' }}</td>
                    <td class="px-4 py-3 text-red-700">{{ in_array($movement->movement_type, \App\Models\StockMovement::NEGATIVE_TYPES, true) ? \App\Support\NumberFormatter::quantity($movement->quantity) : '-' }}</td>
                    @if ($canViewCost)
                        <td class="px-4 py-3">TZS {{ \App\Support\NumberFormatter::money($movement->unit_cost) }}</td>
                    @endif
                    <td class="px-4 py-3 text-xs">{{ $movement->posting_reference ?: class_basename($movement->reference_type).' #'.$movement->reference_id }}</td>
                    <td class="px-4 py-3">{{ $movement->creator?->name }}</td>
                </tr>
            @empty
                <tr><td colspan="{{ $canViewCost ? 9 : 8 }}" class="px-4 py-8 text-center text-slate-500">{{ __('application.no_stock_movements_found_4df0448e') }}</td></tr>
            @endforelse
        </x-table>

        <div class="mt-4">{{ $movements->links() }}</div>
    </x-card>
</div>
